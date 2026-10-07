<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\Collection;
use App\Models\Employee;
use App\Models\Guest;
use App\Models\Sale;
use App\Models\SyncRequest;
use App\Models\SyncRun;
use App\Models\WebhookEvent;
use App\Services\Integrations\IntegrationManager;
use App\Services\Integrations\SyncRecorder;
use App\Console\Commands\RunSyncRequests;
use Illuminate\Support\Carbon;
use Illuminate\Http\Request;

class IntegrationController extends Controller
{
    public function index(IntegrationManager $integrations)
    {
        \App\Services\Integrations\SyncRecorder::clearStale();
        SyncRequest::where('status', 'running')->where('updated_at', '<', now()->subHours(3))
            ->update(['status' => 'failed', 'message' => 'Stopped without finishing.', 'finished_at' => now()]);
        // Cancel pressed on a sync whose process is gone: nothing will pick it up, so finish it here.
        SyncRequest::where('status', 'cancelling')->where('updated_at', '<', now()->subMinutes(SyncRecorder::STALE_MINUTES))
            ->update(['status' => 'cancelled', 'message' => 'Cancelled.', 'finished_at' => now()]);
        return view('admin.integrations.index', [
            'sources' => $integrations->all(),
            'runs' => SyncRun::latest('id')->limit(30)->get(),
            'events' => WebhookEvent::latest('id')->limit(20)->get(),
            'counts' => [
                'Branches' => Branch::count(),
                'Employees' => Employee::count(),
                'Guests' => Guest::count(),
                'Appointments' => Appointment::count(),
                'Sales lines' => Sale::count(),
                'Collections' => Collection::count(),
                'Calls (CallGear)' => \App\Models\Call::count(),
            ],
            'syncBranches' => Branch::whereNotNull('zenoti_center_id')->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'syncTags' => \App\Models\Tag::has('employees')->orderBy('name')->pluck('name'),
            'requests' => SyncRequest::with('requester', 'branch')->latest('id')->limit(10)->get(),
            'callgear' => self::callgearStatus(),
            'callStats' => ['total' => \App\Models\Call::count(), 'last' => \App\Models\Call::max('started_at'), 'incoming_events' => WebhookEvent::where('provider', 'callgear')->count(), 'last_event' => WebhookEvent::where('provider', 'callgear')->max('created_at')],
            'heartbeat' => RunSyncRequests::lastBeat(),
            'autoSync' => ['last' => \App\Support\AutoSync::lastTick(), 'url' => route('autosync.run', ['token' => \App\Support\AutoSync::token()])],
            'cronCommand' => 'cd '.base_path().' && '.$this->cliPhp().' artisan schedule:run >> storage/logs/cron.log 2>&1',
            'range' => [Appointment::min('start_time'), Appointment::max('start_time')],
            'cronLog' => $this->tail(storage_path('logs/cron.log'), 15),
            'lastError' => $this->lastError(),
            'canSpawn' => $this->canSpawn(),
            'storage' => $this->storageCheck(),
            'queued' => SyncRequest::where('status', 'queued')->count(),
            'zenotiFields' => collect(['appointments' => 'Appointment', 'sales' => 'Sales line'])
                ->map(fn ($label, $kind) => ['label' => $label] + (json_decode((string) \App\Models\Setting::get("zenoti.fields.$kind"), true) ?: []))
                ->filter(fn ($f) => ! empty($f['fields'])),
        ]);
    }

    /**
     * The sales lines we actually sync (the accrual report), for one day: every field name the rows
     * carry, the payment-related fields with their values, and how each sample line is counted for
     * call agents, so totals can be compared with the client's Zenoti export.
     */
    private function salesSample(\App\Services\Zenoti\ZenotiClient $client, ?string $center, string $date): array
    {
        $rows = config('zenoti.sales_source') === 'accrual'
            ? $client->salesAccrual((string) $center, $date, $date)
            : $client->sales((string) $center, $date, \Carbon\Carbon::parse($date)->addDay()->toDateString());
        $keys = collect($rows)->flatMap(fn ($r) => array_keys(\Illuminate\Support\Arr::dot($r)))->unique()->sort()->values();
        $money = $keys->filter(fn ($k) => preg_match('/pay|cash|card|custom|collect|amount|sale|price|total|tax|discount|redeem|gift|prepaid|package|member|close|date/i', $k))->values();
        $lines = collect($rows)->map(function ($r) use ($money, $date) {
            $m = \App\Services\Zenoti\ZenotiMapper::sale($r);
            $line = (object) ['raw' => $r, 'net_amount' => $m['net_amount'], 'item_type' => $m['item_type'], 'status' => $m['status']];
            [$amount, $from] = \App\Support\WidgetQuery::agentRevenueAmount($line);

            return ['invoice' => $m['invoice_no'], 'item' => $m['item_name'], 'item_type' => $m['item_type'], 'status' => $m['status'],
                'created_by' => $r['created_by'] ?? data_get($r, 'created_by.name') ?? $r['created_by_name'] ?? null, 'sold_by' => $r['sold_by'] ?? data_get($r, 'sold_by.name') ?? null,
                'net_amount_used' => $m['net_amount'], 'agent_revenue' => \App\Support\WidgetQuery::agentRevenueExclusion($line) === null ? $amount : 0, 'amount_from' => $from,
                'why_not_counted' => \App\Support\WidgetQuery::agentRevenueExclusion($line),
                'counted_on' => \App\Support\WidgetQuery::closedAt((object) ['raw' => $r, 'sold_at' => $m['sold_at'] ?? $date])->toDateString(),
                'money_fields' => collect(\Illuminate\Support\Arr::dot($r))->only($money->all())->all()];
        });

        return [
            'source' => config('zenoti.sales_source') === 'accrual' ? 'reports/sales/accrual_basis/flat_file' : 'sales report',
            'date' => $date,
            'rows' => count($rows),
            'counted_for_agents_total' => round($lines->sum('agent_revenue'), 2),
            'amount_column' => \App\Support\WidgetQuery::REVENUE_COLUMNS[\App\Support\WidgetQuery::revenueColumn()][0],
            'all_fields' => $keys->all(),
            'lines' => $lines->take(15)->values()->all(),
        ];
    }

    /** "Test a Zenoti call": shows the raw reply so field names can be checked without SSH. */
    public function test(Request $request, \App\Services\Zenoti\ZenotiClient $client)
    {
        $branches = Branch::whereNotNull('zenoti_center_id')->orderBy('name')->get();
        $input = $request->validate([
            'call' => 'nullable|in:centers,employees,appointments,sales,guest,path,callgear_employees,callgear_calls,callgear_debug,callgear_tags,employee_filter,sales_probe',
            'center' => 'nullable|string|max:64',
            'date' => 'nullable|date',
            'guest_id' => 'nullable|string|max:64',
            'path' => 'nullable|string|max:200',
        ]);
        $result = $error = null;
        if ($call = $input['call'] ?? null) {
            $center = $input['center'] ?? $branches->first()?->zenoti_center_id;
            $date = $input['date'] ?? now()->toDateString();
            try {
                $result = match ($call) {
                    'centers' => $client->get($client->endpoint('centers'), ['page' => 1, 'size' => 3]),
                    'employees' => $client->get($client->endpoint('employees', ['center_id' => $center]), ['page' => 1, 'size' => 3]),
                    'appointments' => $this->appointmentsByBranch($client, $branches, $center, $date),
                    'sales' => $this->salesSample($client, $center === 'all' ? $branches->first()?->zenoti_center_id : $center, $date),
                    'guest' => $client->get($client->endpoint('guest', ['guest_id' => $input['guest_id'] ?? ''])),
                    'path' => $client->get(ltrim((string) ($input['path'] ?? ''), '/'), ['center_id' => $center]),
                    'callgear_employees' => $this->callgear()->employees(),
                    'callgear_debug' => $this->callgearDebug(),
                    // Do complaint marks (tags) and notes come through the Data API? Tries each field on its own.
                    'callgear_tags' => collect(['tags', 'comments', 'call_records'])->mapWithKeys(fn ($field) => [$field => rescue(fn () => collect($this->callgear()->call('get.calls_report', [
                        'date_from' => \Carbon\Carbon::parse($date)->startOfDay()->format('Y-m-d H:i:s'), 'date_till' => \Carbon\Carbon::parse($date)->endOfDay()->format('Y-m-d H:i:s'),
                        'offset' => 0, 'limit' => 500, 'fields' => ['id', 'start_time', 'contact_phone_number', 'employees', $field],
                    ]))->filter(fn ($r) => ! empty($r[$field]))->take(5)->values()->all() ?: 'field accepted, but no call that day has any', fn ($e) => 'not available: '.mb_substr($e->getMessage(), 0, 200), false)])->all(),
                    'sales_probe' => $this->salesProbe($client, $center === 'all' ? $branches->first()?->zenoti_center_id : $center, $date),
                    'employee_filter' => $this->employeeFilterCheck($client, $center === 'all' ? $branches->first()?->zenoti_center_id : $center, $date),
                    'callgear_calls' => $this->callgear()->callsReport(\Carbon\Carbon::parse($date)->startOfDay()->format('Y-m-d H:i:s'), \Carbon\Carbon::parse($date)->endOfDay()->format('Y-m-d H:i:s'), 0, 20),
                };
            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }
        }
        $json = $result === null ? null : json_encode(($input['call'] ?? null) === 'sales' ? $result : $this->trimList($result), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $callgear = self::callgearStatus();

        return view('admin.integrations.test', compact('branches', 'json', 'error', 'callgear'));
    }

    /**
     * The exact get.employees request this server sends and CallGear's raw reply, for CallGear
     * support. The key only ever appears masked (first and last 4 characters).
     */
    private function callgearDebug(): array
    {
        $token = (string) config('callgear.access_token');
        $mask = fn (string $t) => strlen($t) > 10 ? substr($t, 0, 4).str_repeat('*', strlen($t) - 8).substr($t, -4) : str_repeat('*', strlen($t));
        $url = (string) config('callgear.base_url');
        $body = ['jsonrpc' => '2.0', 'id' => 'debug-'.now()->format('His'), 'method' => 'get.employees', 'params' => ['access_token' => $token]];
        $masked = $body;
        $masked['params']['access_token'] = $mask($token);
        $envLines = collect(@file(base_path('.env'), FILE_IGNORE_NEW_LINES) ?: [])
            ->filter(fn ($l) => str_starts_with(ltrim($l), 'CALLGEAR_ACCESS_TOKEN'));
        $key = [
            'masked' => $mask($token),
            'length' => strlen($token),
            'has_spaces_or_line_breaks' => (bool) preg_match('/\s/', $token),
            'has_quote_marks' => (bool) preg_match('/["\']/', $token),
            'lines_in_env_file' => $envLines->count(),
            'settings_cached' => app()->configurationIsCached(),
        ];
        if (! $token) {
            return ['key' => $key, 'problem' => 'CALLGEAR_ACCESS_TOKEN is empty for the app (check .env, then run artisan config:clear).'];
        }
        try {
            $response = \Illuminate\Support\Facades\Http::acceptJson()->timeout(45)->post($url, $body);
            $reply = ['http_status' => $response->status(), 'raw_body' => mb_substr(str_replace($token, $mask($token), $response->body()), 0, 3000)];
        } catch (\Throwable $e) {
            $reply = ['error' => $e->getMessage()];
        }

        return [
            'sent_at' => now()->toIso8601String(),
            'server_ip_seen_by_internet' => rescue(fn () => trim(\Illuminate\Support\Facades\Http::timeout(5)->get('https://api.ipify.org')->body()), null, false),
            'endpoint_url' => $url,
            'method' => 'POST',
            'headers' => ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
            'request_body' => $masked,
            'curl' => "curl -X POST '$url' -H 'Content-Type: application/json' -d '".json_encode($masked, JSON_UNESCAPED_SLASHES)."'",
            'response' => $reply,
            'key_check' => $key,
        ];
    }

    private function callgear(): \App\Services\CallGear\CallGearClient
    {
        if (! filled(config('callgear.access_token'))) {
            throw new \RuntimeException('CALLGEAR_ACCESS_TOKEN is empty in .env, so CallGear cannot be called.');
        }

        return app(\App\Services\CallGear\CallGearClient::class);
    }

    /** What is missing for the CallGear Data API sync (never shows the token itself). */
    public static function callgearStatus(): array
    {
        $missing = [];
        if (! config('callgear.enabled')) {
            $missing[] = 'CALLGEAR_ENABLED=true';
        }
        if (! filled(config('callgear.access_token'))) {
            $missing[] = 'CALLGEAR_ACCESS_TOKEN=(your Data API key)';
        }
        // Only the incoming-call URL needs the secret; the call import works without it.
        $optional = filled(config('callgear.webhook_secret')) ? [] : ['CALLGEAR_WEBHOOK_SECRET=(any long random text, for the incoming-call URL)'];

        return ['missing' => $missing, 'optional' => $optional, 'base_url' => config('callgear.base_url')];
    }

    /** Row count per branch for the 7 days from $date, plus two sample rows. */
    private function appointmentsByBranch($client, $branches, ?string $center, string $date): array
    {
        $to = \Carbon\Carbon::parse($date)->addDays(6)->toDateString();
        $out = ['period' => "$date to $to"];
        $sample = [];
        foreach ($branches as $b) {
            if ($center !== 'all' && $center !== $b->zenoti_center_id) {
                continue;
            }
            $client->lastEmptyResponse = null;
            $rows = $client->appointments($b->zenoti_center_id, $date, $to);
            $out[$b->name] = count($rows).' row(s)'.($rows ? '' : ' | reply: '.$client->lastEmptyResponse);
            $sample = array_merge($sample, array_slice($rows, 0, 2 - count($sample)));
        }

        return $out + ['sample_rows' => $sample];
    }

    /** Keep the first 3 rows of every list so the page stays readable. */
    private function trimList($data)
    {
        if (! is_array($data)) {
            return $data;
        }
        if (array_is_list($data) && count($data) > 3) {
            $data = array_merge(array_slice($data, 0, 3), ['… '.(count($data) - 3).' more rows']);
        }

        return array_map(fn ($v) => $this->trimList($v), $data);
    }

    /** Last lines of a log file, without reading the whole file. */
    private function tail(string $file, int $lines): ?string
    {
        if (! is_file($file) || ! is_readable($file)) {
            return null;
        }
        $size = filesize($file);
        $fh = fopen($file, 'r');
        fseek($fh, max(0, $size - 8000));
        $text = stream_get_contents($fh);
        fclose($fh);

        return trim(implode("\n", array_slice(explode("\n", trim((string) $text)), -$lines))) ?: null;
    }

    /** Newest ERROR line from laravel.log (first line only, no stack trace). */
    private function lastError(): ?string
    {
        $text = $this->tail(storage_path('logs/laravel.log'), 400);
        if (! $text || ! preg_match_all('/^\[[^\]]+\] \w+\.ERROR: .*$/m', $text, $m)) {
            return null;
        }

        return mb_substr(end($m[0]), 0, 600);
    }

    private function canSpawn(): bool
    {
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));

        return function_exists('exec') && ! in_array('exec', $disabled, true);
    }

    /**
     * Start the queued syncs right now, for when cron is broken. Uses a background process where the
     * server allows exec(); otherwise the web request itself carries on after the page has been sent
     * (LiteSpeed's litespeed_finish_request, and public/.htaccess sets noabort for this address).
     */
    public function runNow()
    {
        if (! SyncRequest::where('status', 'queued')->exists()) {
            return back()->with('status', 'Nothing is queued. Click "Sync now" first.');
        }
        if (SyncRequest::where('status', 'running')->where('updated_at', '>', now()->subMinutes(2))->exists()) {
            return request()->expectsJson() ? response()->json(['started' => false])
                : back()->with('status', 'A sync is already running. This page updates on its own.');
        }
        if ($this->canSpawn()) {
            exec('cd '.escapeshellarg(base_path()).' && nohup '.escapeshellarg($this->cliPhp()).' artisan integrations:run-requests --web >> storage/logs/cron.log 2>&1 &');
        } else {
            app()->terminating(function () {
                ignore_user_abort(true);
                @set_time_limit(0);
                \Illuminate\Support\Facades\Artisan::call('integrations:run-requests', ['--web' => true]);
            });
        }

        return request()->expectsJson() ? response()->json(['started' => true])
            : back()->with('status', 'Started the queued syncs. Keep this page open: it starts each next round of guest profiles on its own.');
    }

    /**
     * Does Zenoti accept an employee filter on sales / appointments? Asks for one day of one branch
     * without a filter and with each likely parameter name, for one Callgear-tagged employee, and
     * reports the row counts and whether every row returned belongs to that employee.
     */
    /**
     * Try the ways Zenoti can hand out sales for one branch and 7 days, and count rows (and rows
     * naming a Callgear-tagged employee) for each, so we can see which one returns everything.
     */
    private function salesProbe(\App\Services\Zenoti\ZenotiClient $client, ?string $center, string $date): array
    {
        if (! $center) {
            return ['error' => 'No Zenoti branch yet.'];
        }
        $from = \Carbon\Carbon::parse($date)->toDateString();
        $to = \Carbon\Carbon::parse($date)->addDays(6)->toDateString();
        $staff = \App\Models\Tag::agents()?->employees()->get(['employees.zenoti_id', 'employees.first_name', 'employees.last_name'])
            ->flatMap(fn ($e) => array_filter([$e->zenoti_id, trim($e->first_name.' '.$e->last_name)]))->map(fn ($v) => mb_strtolower($v))->all() ?? [];
        $sales = $client->endpoint('sales', ['center_id' => $center]);
        $get = ['center_id' => $center, 'start_date' => $from, 'end_date' => $to, 'page' => 1, 'size' => 100];
        $window = ['start_date' => "$from 00:00:00", 'end_date' => "$to 23:59:59"];
        $tries = [
            "GET $sales" => fn () => $client->get($sales, $get),
            "GET $sales item_type=0 (services)" => fn () => $client->get($sales, $get + ['item_type' => 0]),
            "GET $sales item_type=2 (products)" => fn () => $client->get($sales, $get + ['item_type' => 2]),
            "GET $sales item_type=0 status=-1" => fn () => $client->get($sales, $get + ['item_type' => 0, 'status' => -1]),
            'POST reports/sales/accrual_basis/flat_file (center_ids)' => fn () => $client->post('reports/sales/accrual_basis/flat_file', ['center_ids' => [$center]] + $window),
            'POST reports/sales/accrual_basis/flat_file (centers.ids)' => fn () => $client->post('reports/sales/accrual_basis/flat_file', ['centers' => ['ids' => [$center]]] + $window),
            'POST reports/sales/cash_basis/flat_file (centers.ids)' => fn () => $client->post('reports/sales/cash_basis/flat_file', ['centers' => ['ids' => [$center]]] + $window),
            "GET invoices (center {$center})" => fn () => $client->get("centers/$center/invoices", ['start_date' => $from, 'end_date' => $to, 'page' => 1, 'size' => 100]),
        ];
        $out = ['center' => $center, 'period' => "$from to $to", 'callgear_staff_known' => count($staff)];
        foreach ($tries as $label => $call) {
            try {
                $data = $call();
                $rows = $client->rows($data, ['center_sales_report', 'sales', 'invoices', 'data', 'report']);
                $mine = collect($rows)->filter(function ($r) use ($staff) {
                    $j = mb_strtolower(json_encode($r, JSON_UNESCAPED_UNICODE));

                    return collect($staff)->contains(fn ($s) => $s !== '' && str_contains($j, $s));
                })->count();
                $out[$label] = [
                    'rows' => count($rows),
                    'rows_naming_callgear_staff' => $mine,
                    'total_reported' => data_get($data, 'page_info.total') ?? data_get($data, 'total'),
                    'top_level_keys' => array_is_list($data) ? '(list)' : implode(', ', array_keys($data)),
                    'row_fields' => $rows ? implode(', ', array_keys(\App\Services\Zenoti\ZenotiMapper::fieldMap($rows[0]))) : null,
                ];
            } catch (\Throwable $e) {
                $out[$label] = 'error: '.mb_substr($e->getMessage(), 0, 300);
                if ($e instanceof \App\Services\Zenoti\ZenotiQuotaExceeded) {
                    break;
                }
            }
            usleep(400000);
        }

        return $out;
    }

    private function employeeFilterCheck(\App\Services\Zenoti\ZenotiClient $client, ?string $center, string $date): array
    {
        $employee = \App\Models\Tag::agents()?->employees()->whereNotNull('zenoti_id')->first();
        if (! $employee || ! $center) {
            return ['error' => 'Tag at least one employee "'.\App\Models\Tag::AGENTS.'" (with a Zenoti id) first.'];
        }
        $to = \Carbon\Carbon::parse($date)->addDay()->toDateString();
        $calls = [
            'sales' => [$client->endpoint('sales', ['center_id' => $center]), ['center_id' => $center, 'start_date' => $date, 'end_date' => $to, 'item_type' => 0], ['center_sales_report', 'sales'], 'sale'],
            'appointments' => [$client->endpoint('appointments', ['center_id' => $center]), ['center_id' => $center, 'start_date' => $date, 'end_date' => $to], ['appointments'], 'appointment'],
        ];
        $out = ['employee' => $employee->full_name, 'zenoti_id' => $employee->zenoti_id, 'center' => $center, 'date' => $date];
        foreach ($calls as $name => [$path, $query, $keys, $mapper]) {
            foreach ([null, 'employee_id', 'employee_ids', 'therapist_id'] as $param) {
                try {
                    $rows = $client->rows($client->get($path, $query + ($param ? [$param => $employee->zenoti_id] : [])), $keys);
                    $theirs = collect($rows)->filter(fn ($r) => (string) \App\Services\Zenoti\ZenotiMapper::$mapper($r)['_employee'] === (string) $employee->zenoti_id)->count();
                    $out[$name][$param ?? 'no filter'] = count($rows).' rows, '.$theirs.' of them this employee';
                } catch (\Throwable $e) {
                    $out[$name][$param ?? 'no filter'] = 'error: '.mb_substr($e->getMessage(), 0, 160);
                }
            }
        }
        $out['how_to_read'] = 'A filter works when its row count is smaller than "no filter" and every row is this employee.';

        return $out;
    }

    /** Cancel a queued sync at once; a running one stops at its next step. */
    public function cancel(SyncRequest $syncRequest)
    {
        if ($syncRequest->status === 'queued') {
            $syncRequest->update(['status' => 'cancelled', 'message' => 'Cancelled before it started.', 'finished_at' => now()]);

            return back()->with('status', 'Sync cancelled.');
        }
        if ($syncRequest->status === 'running') {
            // A run nothing has touched for a while belongs to a process that is gone.
            $gone = SyncRun::where('status', 'running')->where('updated_at', '>', now()->subMinutes(SyncRecorder::STALE_MINUTES))->doesntExist()
                && $syncRequest->updated_at->lt(now()->subMinutes(SyncRecorder::STALE_MINUTES));
            $syncRequest->update($gone ? ['status' => 'cancelled', 'message' => 'Cancelled.', 'finished_at' => now()] : ['status' => 'cancelling']);

            return back()->with('status', $gone ? 'Sync cancelled.' : 'Cancelling: it stops after the step it is on (usually within a minute).');
        }

        return back();
    }

    /** Why cron may not be able to write its log: free space and a write test in storage/logs. */
    private function storageCheck(): array
    {
        $dir = storage_path('logs');
        $probe = $dir.'/.write-test-'.getmypid();
        error_clear_last();
        $ok = @file_put_contents($probe, 'ok') !== false;
        $error = $ok ? null : (error_get_last()['message'] ?? 'unknown error');
        @unlink($probe);
        $free = @disk_free_space($dir);

        return ['writable' => $ok, 'error' => $error, 'free_mb' => $free === false ? null : (int) round($free / 1048576)];
    }

    /** The command-line PHP matching the version this site runs on (LiteSpeed's lsphp sits next to it). */
    private function cliPhp(): string
    {
        $bin = (string) PHP_BINARY;
        if ($bin && preg_match('/(lsphp|php-fpm|php-cgi)[\d.]*$/', $bin)) {
            $sibling = dirname($bin).'/php';
            $bin = @is_file($sibling) ? $sibling : '';
        }

        return $bin ?: '/usr/bin/php';
    }

    public function sync(Request $request, string $provider, IntegrationManager $integrations)
    {
        $source = $integrations->get($provider);
        if (! $source->isConfigured()) {
            return back()->withErrors(['sync' => "{$source->label()} is not configured yet. Add its keys to .env."]);
        }
        $entity = $request->input('entity') ?: null;
        $days = $request->integer('days') ?: null;
        $scope = $request->validate(['branch_id' => 'nullable|integer|exists:branches,id', 'tag' => 'nullable|string|max:60|exists:tags,name']);
        $active = SyncRequest::whereIn('status', ['queued', 'running'])->where('provider', $provider)->exists();
        if (! $active) {
            SyncRequest::create(['provider' => $provider, 'entity' => $entity, 'days' => $days ? min($days, 1095) : null,
                'branch_id' => $provider === 'zenoti' ? ($scope['branch_id'] ?? null) : null, 'tag' => $provider === 'zenoti' ? ($scope['tag'] ?? null) : null, 'requested_by' => $request->user()->id]);
        }

        return back()->with('status', $active
            ? "A {$source->label()} sync is already queued or running. This page updates on its own."
            : "{$source->label()} sync queued. It starts within a minute and runs in the background; this page updates on its own.");
    }
}
