<?php

namespace App\Services\CallGear;

use App\Models\Branch;
use App\Models\Call;
use App\Models\Employee;
use App\Models\WebhookEvent;
use App\Services\Integrations\PerformanceSource;
use App\Services\Integrations\SyncRecorder;
use App\Services\Zenoti\EmployeeMatcher;
use Illuminate\Support\Str;

/**
 * CallGear is wired in but stays switched off (CALLGEAR_ENABLED=false) until
 * API access arrives. Once the token is in .env it syncs calls and employees
 * into the same tables the dashboards read.
 */
class CallGearSource implements PerformanceSource
{
    public function __construct(private CallGearClient $client) {}

    private ?int $lookbackDays = null;

    /** Pull a longer call history on the next sync (Sync now "Last 30 days", SSH --days). */
    public function withLookbackDays(?int $days): static
    {
        $this->lookbackDays = $days;

        return $this;
    }

    public function key(): string
    {
        return 'callgear';
    }

    public function label(): string
    {
        return 'CallGear';
    }

    public function isConfigured(): bool
    {
        return $this->client->isConfigured();
    }

    public function entities(): array
    {
        return ['employees', 'calls'];
    }

    public function sync(?string $entity = null): array
    {
        $runs = [];
        foreach ($entity ? [$entity] : $this->entities() as $e) {
            $runs[] = SyncRecorder::run('callgear', $e, function () use ($e) {
                if (! $this->isConfigured()) {
                    return ['message' => 'Skipped: CallGear API not configured yet'];
                }

                return $this->{'sync'.Str::studly($e)}();
            });
        }

        return $runs;
    }

    public function verifyWebhookToken(?string $token): bool
    {
        $secret = config('callgear.webhook_secret');

        return filled($secret) && is_string($token) && hash_equals($secret, $token);
    }

    protected function syncEmployees(): array
    {
        $counts = ['created' => 0, 'updated' => 0, 'merged' => 0];
        $staff = Employee::where('source', '!=', 'callgear')->with('tags:id,name')->get(['id', 'first_name', 'last_name']);
        foreach ($this->client->employees() as $row) {
            $id = (string) ($row['id'] ?? '');
            if (! $id) {
                continue;
            }
            $sameName = self::matchByName(trim(($row['first_name'] ?? '').' '.($row['last_name'] ?? '')) ?: (string) ($row['full_name'] ?? ''), $staff);
            // Match an existing (e.g. Zenoti) employee by CallGear id, email, then name so one person has one record.
            $employee = Employee::where('callgear_id', $id)->first()
                ?? (! empty($row['email']) ? Employee::where('email', $row['email'])->first() : null)
                ?? $sameName
                ?? new Employee(['source' => 'callgear', 'first_name' => $row['first_name'] ?? $row['full_name'] ?? 'Unknown']);
            // An earlier sync made a separate CallGear-only record: fold it into the Zenoti person.
            if ($employee->exists && $employee->source === 'callgear' && $sameName && $sameName->id !== $employee->id) {
                $employee = self::link($sameName, $employee);
                $counts['merged']++;
            }
            $counts[$employee->exists ? 'updated' : 'created']++;
            $employee->fill([
                'callgear_id' => $id,
                'last_name' => $employee->last_name ?? ($row['last_name'] ?? null),
                'email' => $employee->email ?? ($row['email'] ?? null),
            ])->save();
        }

        if ($counts['merged']) {
            $counts['message'] = "{$counts['merged']} CallGear agent(s) linked to their Zenoti employee by name.";
        }
        $unlinked = Employee::where('source', 'callgear')->whereNotNull('callgear_id')->where('is_active', true)->count();
        if ($unlinked) {
            $counts['message'] = trim(($counts['message'] ?? '')." $unlinked CallGear agent(s) not matched to a Zenoti employee: link them on the employee's page.");
        }
        unset($counts['merged']);

        return $counts;
    }

    /**
     * The one staff member a CallGear agent name belongs to: exact full name, else every word of
     * the shorter name found in the longer one ("Hadeer Gaber" = "Hadeer Gaber Saad Hassan").
     * When several fit, only Callgear-tagged staff count. Null unless exactly one fits.
     */
    public static function matchByName(string $name, $staff): ?Employee
    {
        $words = fn (string $n) => array_values(array_filter(preg_split('/[\s.\-]+/u', mb_strtolower(trim($n))), fn ($w) => mb_strlen($w) > 1));
        $agent = $words($name);
        if (! $agent) {
            return null;
        }
        $key = implode(' ', $agent);
        $exact = $staff->filter(fn ($e) => implode(' ', $words($e->first_name.' '.$e->last_name)) === $key);
        $fits = $exact->isNotEmpty() ? $exact : $staff->filter(function ($e) use ($words, $agent) {
            $mine = $words($e->first_name.' '.$e->last_name);
            [$short, $long] = count($agent) <= count($mine) ? [$agent, $mine] : [$mine, $agent];

            return $short && $short[0] === $long[0] && ! array_diff($short, $long);
        });
        if ($fits->count() > 1) {
            $fits = $fits->filter(fn ($e) => $e->tags->contains(fn ($t) => strcasecmp($t->name, 'callgear') === 0));
        }

        return $fits->count() === 1 ? $fits->first() : null;
    }

    /** Make $agent's CallGear id and calls belong to $person; the separate agent record is switched off. */
    public static function link(Employee $person, Employee $agent): Employee
    {
        if ($person->id === $agent->id) {
            return $person;
        }
        Call::where('employee_id', $agent->id)->update(['employee_id' => $person->id]);
        $callgearId = $agent->callgear_id;
        $agent->forceFill(['callgear_id' => null, 'is_active' => false])->save();
        $person->forceFill(['callgear_id' => $callgearId])->save();

        return $person;
    }

    protected function syncCalls(): array
    {
        $counts = ['created' => 0, 'updated' => 0];
        $days = max(1, $this->lookbackDays ?? (int) config('callgear.lookback_days', 2));
        // A week per request keeps each report well inside CallGear's limits.
        for ($start = now()->subDays($days)->startOfDay(); $start->lt(now()); $start = $start->copy()->addWeek()) {
            SyncRecorder::checkStop();
            $end = $start->copy()->addWeek()->min(now());
            for ($offset = 0; ; $offset += 1000) {
                $rows = $this->client->callsReport($start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s'), $offset);
                foreach ($rows as $row) {
                    $this->upsertCall($row, $counts);
                }
                if (count($rows) < 1000) {
                    break;
                }
            }
        }
        if (! $counts['created'] && ! $counts['updated']) {
            $counts['message'] = "CallGear returned no calls for the last $days day(s).";
        }

        return $counts;
    }

    public function upsertCall(array $row, array &$counts = []): Call
    {
        $call = Call::firstOrNew(['external_id' => (string) ($row['id'] ?? $row['call_session_id'] ?? '')]);
        $counts[$call->exists ? 'updated' : 'created'] = ($counts[$call->exists ? 'updated' : 'created'] ?? 0) + 1;
        $employeeExt = data_get($row, 'employees.0.employee_id') ?? ($row['employee_id'] ?? null);

        $call->fill([
            'branch_id' => isset($row['site_id']) ? Branch::where('callgear_site_id', (string) $row['site_id'])->value('id') : $call->branch_id,
            'employee_id' => $employeeExt ? Employee::where('callgear_id', (string) $employeeExt)->value('id') : $call->employee_id,
            'direction' => $row['direction'] ?? null,
            'status' => ! empty($row['is_lost']) ? 'missed' : 'answered',
            'caller' => $row['contact_phone_number'] ?? null,
            'callee' => $row['virtual_phone_number'] ?? null,
            // Report rows say total_duration; "Call finished" notifications say total_time_duration.
            'duration_seconds' => (int) ($row['total_duration'] ?? $row['total_time_duration'] ?? 0),
            'wait_seconds' => (int) ($row['wait_duration'] ?? $row['wait_time_duration'] ?? 0),
            'started_at' => $row['start_time'] ?? now(),
            'raw' => $row,
        ])->save();

        return $call;
    }

    public function handleWebhook(WebhookEvent $event): void
    {
        $data = $event->payload['data'] ?? $event->payload;
        $status = 'ignored';
        if (isset($data['id']) || isset($data['call_session_id'])) {
            $this->upsertCall($data);
            $status = 'processed';
        }
        // Whatever the notification carries, fetch the latest calls from the Data API so the
        // dashboards update right away. At most once every 2 minutes, after the reply is sent.
        if ($this->isConfigured() && \Illuminate\Support\Facades\Cache::add('callgear.webhook_pull', true, 120)) {
            app()->terminating(fn () => $this->pullRecentCalls());
            $status = 'processed';
        }
        $event->update(['status' => $status, 'processed_at' => now()]);
    }

    /** Today's and yesterday's calls from the Data API (quietly: no sync run row every 2 minutes). */
    public function pullRecentCalls(): void
    {
        try {
            $this->withLookbackDays(1)->syncCalls();
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
