<?php

namespace App\Services\Zenoti;

use App\Models\Appointment;
use App\Models\Branch;
use App\Models\Collection;
use App\Models\Employee;
use App\Models\Guest;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\Sale;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Services\Integrations\PerformanceSource;
use App\Services\Activity\ActivityRecorder;
use App\Services\Integrations\SyncRecorder;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class ZenotiSource implements PerformanceSource
{
    private ?int $lookbackDays = null;

    public function __construct(private ZenotiClient $client) {}

    /** Pull a longer history on the next sync (used for the first backfill). */
    private bool $allGuestProfiles = false;

    /** Manual runs (Sync now, SSH) fetch every missing guest profile instead of a few minutes' worth. */
    public function withAllGuestProfiles(bool $all = true): static
    {
        $this->allGuestProfiles = $all;

        return $this;
    }

    private ?int $guestProfileSeconds = null;

    /** Guest profiles left after the last sync (web runs stop early and continue in the next round). */
    public ?int $guestProfilesLeft = null;

    /** Stop fetching guest profiles after this many seconds (short rounds the web host won't kill). */
    public function withGuestProfileSeconds(?int $seconds): static
    {
        $this->guestProfileSeconds = $seconds;

        return $this;
    }

    public function withLookbackDays(?int $days): static
    {
        $this->lookbackDays = $days;

        return $this;
    }

    public function key(): string
    {
        return 'zenoti';
    }

    public function label(): string
    {
        return 'Zenoti';
    }

    public function isConfigured(): bool
    {
        return $this->client->isConfigured();
    }

    public function entities(): array
    {
        return ['centers', 'employees', 'guests', 'appointments', 'sales', 'collections', 'leads'];
    }

    public function sync(?string $entity = null): array
    {
        $entities = $entity ? [$entity] : $this->entities();
        $runs = [];
        foreach ($entities as $e) {
            $this->client->lastEmptyResponse = null;
            $runs[] = SyncRecorder::run('zenoti', $e, fn () => $this->{'sync'.Str::studly($e)}());
        }

        return $runs;
    }

    public function verifyWebhookToken(?string $token): bool
    {
        $secret = config('zenoti.webhook_secret');

        return filled($secret) && is_string($token) && hash_equals($secret, $token);
    }

    // ---------------------------------------------------------------- centers

    protected function syncCenters(): array
    {
        $org = Organization::firstOrCreate(['name' => config('zenoti.default_organization')]);
        $counts = ['created' => 0, 'updated' => 0];

        foreach ($this->client->centers() as $row) {
            $data = ZenotiMapper::center($row);
            if (! $data['zenoti_center_id'] || ! $this->centerAllowed($data['zenoti_center_id'])) {
                continue;
            }
            $branch = Branch::firstOrNew(['zenoti_center_id' => $data['zenoti_center_id']]);
            $counts[$branch->exists ? 'updated' : 'created']++;
            $branch->organization_id ??= $org->id; // keep manual re-assignments to other organizations
            $branch->fill(Arr::only($data, ['name', 'code', 'address', 'phone']))->save();
        }

        return $counts;
    }

    protected function centerAllowed(string $centerId): bool
    {
        $only = config('zenoti.center_ids');

        return empty($only) || in_array($centerId, $only, true);
    }

    /** @return \Illuminate\Support\Collection<int, Branch> */
    private ?array $onlyBranchIds = null;

    /** Limit the sync to these branches (e.g. where the employees with one tag work). */
    public function onlyBranches(array $ids): static
    {
        $this->onlyBranchIds = $ids;

        return $this;
    }

    private ?array $onlyGuestsOfEmployeeIds = null;

    /**
     * Limit a sync to one branch and/or the employees with one tag. Zenoti can't filter its
     * sales and appointment calls by employee (checked on the Test page, 2026-09-26), so a tag
     * limits the guest profiles fetched (the slow, quota-bound part) to guests these employees
     * served, booked or billed. All branches are still pulled, since tagged staff book for any branch.
     * Returns a short description, or throws when the choice matches nothing.
     */
    public function scopeTo(?int $branchId, ?string $tag): string
    {
        $branches = $branchId ? [$branchId] : null;
        $parts = [];
        if (filled($tag)) {
            $t = \App\Models\Tag::whereRaw('LOWER(name) = ?', [mb_strtolower(trim($tag))])->first();
            $employees = $t ? $t->employees()->get(['employees.id', 'employees.branch_id']) : collect();
            if ($employees->isEmpty()) {
                throw new \InvalidArgumentException("No employees have the tag \"$tag\".");
            }
            // Branches are not narrowed: tagged staff (Callgear) book and bill for every branch.
            $this->onlyGuestsOfEmployeeIds = $employees->pluck('id')->all();
            $parts[] = $employees->count().' employee(s) tagged '.$t->name;
        }
        if ($branches !== null) {
            $this->onlyBranches($branches);
            $parts[] = 'branches: '.Branch::whereIn('id', $branches)->orderBy('name')->pluck('name')->implode(', ');
        }

        return implode('; ', $parts);
    }

    protected function syncedBranches()
    {
        return Branch::whereNotNull('zenoti_center_id')->where('is_active', true)
            ->when($this->onlyBranchIds !== null, fn ($q) => $q->whereIn('id', $this->onlyBranchIds ?: [0]))->get();
    }

    // -------------------------------------------------------------- employees

    protected function syncEmployees(): array
    {
        $counts = ['created' => 0, 'updated' => 0, 'deactivated' => 0];

        foreach ($this->syncedBranches() as $branch) {
            $this->perCenter($branch, $counts, function () use ($branch, &$counts) {
                $seen = [];
                foreach ($this->client->employees($branch->zenoti_center_id) as $row) {
                    $employee = $this->upsertEmployee($row, $branch, $counts);
                    $seen[] = $employee->zenoti_id;
                }

                // Anyone no longer returned for this center was removed in Zenoti.
                $counts['deactivated'] += Employee::where('branch_id', $branch->id)
                    ->whereNotNull('zenoti_id')->whereNotIn('zenoti_id', $seen)->where('is_active', true)
                    ->update(['is_active' => false]);
            });
        }

        return $this->withErrors($counts);
    }

    public function upsertEmployee(array $row, ?Branch $branch, array &$counts = []): Employee
    {
        $data = ZenotiMapper::employee($row);
        $employee = Employee::firstOrNew(['zenoti_id' => $data['zenoti_id']]);
        $isNew = ! $employee->exists;

        $employee->fill(Arr::except($data, ['zenoti_id']) + [
            'source' => $employee->source ?: 'zenoti',
            'is_active' => true,
            'raw' => $row,
            'synced_at' => now(),
        ]);
        if ($branch) {
            $employee->branch_id = $branch->id;
            $employee->organization_id = $branch->organization_id;
        }
        $employee->save();

        $counts[$isNew ? 'created' : 'updated'] = ($counts[$isNew ? 'created' : 'updated'] ?? 0) + 1;
        $this->linkUser($employee);

        return $employee;
    }

    /** Every synced employee gets (or is linked to) a login so permissions can be assigned. */
    protected function linkUser(Employee $employee): void
    {
        if ($employee->user_id || ! config('zenoti.auto_create_users') || ! $employee->email) {
            return;
        }

        $user = User::where('email', $employee->email)->first();
        if (! $user) {
            $user = User::create([
                'name' => $employee->full_name,
                'email' => $employee->email,
                'phone' => $employee->phone,
                'password' => Str::random(40),
                'organization_id' => $employee->organization_id,
                'branch_id' => $employee->branch_id,
                'source' => 'zenoti',
                'is_active' => (bool) config('zenoti.auto_user_active'),
            ]);
            if ($role = config('zenoti.auto_user_role')) {
                $user->assignRole($role);
            }
        }

        $employee->user_id = $user->id;
        $employee->save();
    }

    // ----------------------------------------------------------------- guests

    protected function syncGuests(): array
    {
        if (! config('zenoti.endpoints.guests')) {
            return $this->syncGuestDetails();
        }
        $counts = ['created' => 0, 'updated' => 0];
        [$from, $to] = $this->window();
        foreach ($this->syncedBranches() as $branch) {
            $this->perCenter($branch, $counts, function () use ($branch, $from, $to, &$counts) {
                foreach ($this->chunks($from, $to, branch: $branch) as [$a, $b]) {
                    foreach ($this->client->guests($branch->zenoti_center_id, $a, $b) as $row) {
                        $this->upsertGuest($row, $branch, $counts);
                    }
                }
            });
        }

        return $this->withErrors($counts);
    }

    /**
     * Guests arrive with each appointment (Zenoti's guest search needs a name/phone/email, so
     * there is no "all guests" list). This fills in each one's full profile by guest id.
     * Scheduled runs stop after a few minutes; "Sync now" and SSH runs keep going until all are done.
     * A profile that fails is retried a day later so it can't hold up the rest.
     */
    protected function syncGuestDetails(): array
    {
        $counts = ['created' => 0, 'updated' => 0];
        $failed = [];
        $started = microtime(true);
        $budget = $this->guestProfileSeconds ?? ($this->allGuestProfiles ? PHP_INT_MAX : (int) config('zenoti.guest_details_seconds', 240));
        $cap = $this->allGuestProfiles ? PHP_INT_MAX : (int) config('zenoti.guest_details_per_run', 500);
        $delay = (int) config('zenoti.guest_details_delay_ms', 1200) * 1000;
        $quotaHit = null;
        $pending = fn () => Guest::where('source', 'zenoti')->whereNotNull('zenoti_id')->whereNull('profile_synced_at')
            ->where(fn ($q) => $q->whereNull('profile_failed_at')->orWhere('profile_failed_at', '<', now()->subDay()))
            ->when($this->onlyGuestsOfEmployeeIds !== null, fn ($q) => $q->where(fn ($q) => $q
                ->whereIn('id', \App\Models\Appointment::where(fn ($a) => $a->whereIn('employee_id', $this->onlyGuestsOfEmployeeIds)->orWhereIn('booked_by_employee_id', $this->onlyGuestsOfEmployeeIds))->whereNotNull('guest_id')->select('guest_id'))
                ->orWhereIn('id', \App\Models\Sale::where(fn ($s) => $s->whereIn('employee_id', $this->onlyGuestsOfEmployeeIds)->orWhereIn('created_by_employee_id', $this->onlyGuestsOfEmployeeIds))->whereNotNull('guest_id')->select('guest_id'))))
            ->when($this->onlyGuestsOfEmployeeIds === null && $this->onlyBranchIds !== null, fn ($q) => $q->where(fn ($q) => $q
                ->whereIn('branch_id', $this->onlyBranchIds)
                ->orWhereIn('id', \App\Models\Appointment::whereIn('branch_id', $this->onlyBranchIds)->whereNotNull('guest_id')->select('guest_id'))));
        $total = $pending()->count();
        $done = 0;
        $lastId = 0;

        while ($done < $cap && ($done === 0 || microtime(true) - $started < $budget)) {
            $guests = $pending()->where('id', '>', $lastId)->orderBy('id')->limit(200)->get();
            if ($guests->isEmpty()) {
                break;
            }
            foreach ($guests as $guest) {
                $lastId = $guest->id;
                // Every profile, so a stalled run shows at once and a live one visibly moves.
                SyncRecorder::progress(sprintf('Working: guest profiles %d of %d', $done + 1, $total));
                $done++;
                try {
                    if ($delay && $done > 1) {
                        usleep($delay); // stay under Zenoti's calls-per-minute quota
                    }
                    $row = $this->client->guest($guest->zenoti_id);
                    $data = ZenotiMapper::guest($row + ['id' => $guest->zenoti_id]);
                    foreach (Arr::except($data, ['zenoti_id', 'registered_at']) as $k => $v) {
                        if (filled($v)) {
                            $guest->{$k} = $v;
                        }
                    }
                    if ($data['registered_at']) {
                        $guest->registered_at = $data['registered_at'];
                    }
                    $guest->raw = $row;
                    $guest->profile_synced_at = now();
                    $guest->profile_failed_at = null;
                    $guest->save();
                    $counts['updated']++;
                } catch (\App\Services\Integrations\SyncCancelled $e) {
                    throw $e;
                } catch (ZenotiQuotaExceeded $e) {
                    $quotaHit = $e->getMessage(); // not this guest's fault: stop and carry on in a later run

                    break 2;
                } catch (\Throwable $e) {
                    if (str_contains($e->getMessage(), 'failed (404)')) {
                        $this->markGuestDeleted($guest);
                        $counts['deactivated'] = ($counts['deactivated'] ?? 0) + 1;

                        continue;
                    }
                    $guest->forceFill(['profile_failed_at' => now()])->save();
                    $failed[] = $guest->zenoti_id.': '.mb_substr($e->getMessage(), 0, 300);
                    if (count($failed) >= 5 && $counts['updated'] === 0) {
                        break 2; // the endpoint itself is wrong; don't hammer Zenoti
                    }
                }
                if ($done >= $cap || microtime(true) - $started >= $budget) {
                    break 2;
                }
            }
        }
        $left = $this->guestProfilesLeft = $pending()->count();
        $retry = Guest::where('source', 'zenoti')->whereNull('profile_synced_at')->where('profile_failed_at', '>=', now()->subDay())->count() - count($failed);
        if ($failed && $counts['updated'] === 0) {
            throw new \RuntimeException('Guest profiles failed. '.implode(' | ', array_slice($failed, 0, 3)));
        }
        $counts['message'] = trim(($quotaHit ? "Paused: Zenoti's API quota was reached after {$counts['updated']} profile(s); the rest continue on later runs. " : '')
            .($failed ? count($failed).' profile(s) failed (retried tomorrow), e.g. '.$failed[0].'. ' : '')
            .($retry > 0 ? "$retry earlier failure(s) wait for tomorrow's retry. " : '')
            .($left ? "$left guest profile(s) still to fetch; they continue on the next runs." : ''), ' ') ?: null;

        return $counts;
    }

    protected function markGuestDeleted(?Guest $guest): void
    {
        if (! $guest || $guest->deleted_in_zenoti_at) {
            return;
        }
        $guest->forceFill(['deleted_in_zenoti_at' => now(), 'profile_synced_at' => now()])->save();
        ActivityRecorder::record([
            'branch_id' => $guest->branch_id, 'subject_type' => 'guest', 'subject_id' => $guest->id,
            'subject_label' => trim($guest->full_name.' '.$guest->phone), 'action' => 'guest_deleted', 'source' => $this->activitySource,
        ]);
    }

    public function upsertGuest(array $row, ?Branch $branch, array &$counts = []): Guest
    {
        $data = ZenotiMapper::guest($row);
        $guest = Guest::withoutGlobalScope('live')->firstOrNew(['zenoti_id' => $data['zenoti_id']]);
        $counts[$guest->exists ? 'updated' : 'created'] = ($counts[$guest->exists ? 'updated' : 'created'] ?? 0) + 1;
        $guest->fill(Arr::except($data, ['zenoti_id']) + ['raw' => $row, 'source' => 'zenoti']);
        $guest->branch_id ??= $branch?->id;
        $guest->registered_at ??= now();
        $guest->profile_synced_at = now();
        $guest->save();

        return $guest;
    }

    /**
     * Guest details that come inside an appointment. Only fills blanks, and keeps the
     * earliest booking as the guest's first-seen date (drives "new guests").
     */
    protected function upsertEmbeddedGuest(array $row, ?Branch $branch, $seenAt): Guest
    {
        $data = ZenotiMapper::guest($row);
        $guest = Guest::withoutGlobalScope('live')->firstOrNew(['zenoti_id' => $data['zenoti_id']]);
        foreach (Arr::except($data, ['zenoti_id', 'registered_at']) as $k => $v) {
            if (filled($v)) {
                $guest->{$k} = $v;
            }
        }
        $guest->source = 'zenoti';
        $guest->branch_id ??= $branch?->id;
        $first = $data['registered_at'] ?? $seenAt ?? now();
        if (! $guest->registered_at || $guest->registered_at->gt($first)) {
            $guest->registered_at = $first;
        }
        $guest->raw ??= $row;
        $guest->save();

        return $guest;
    }

    // ----------------------------------------------------------- appointments

    protected function syncAppointments(): array
    {
        $counts = ['created' => 0, 'updated' => 0];
        [$from, $to] = $this->window(forwardDays: 30); // include upcoming bookings
        $seen = [];
        $fetched = []; // [branch, from, to, rows] for every week Zenoti answered
        foreach ($this->syncedBranches() as $branch) {
            $this->perCenter($branch, $counts, function () use ($branch, $from, $to, &$counts, &$seen, &$fetched) {
                foreach ($this->chunks($from, $to, branch: $branch) as [$a, $b]) {
                    $rows = $this->client->appointments($branch->zenoti_center_id, $a, $b);
                    foreach ($rows as $row) {
                        $seen[$this->upsertAppointment($row, $branch, $counts)->zenoti_id] = true;
                    }
                    $fetched[] = [$branch, $a, $b, count($rows)];
                }
            });
        }
        $deleted = 0;
        foreach ($fetched as [$branch, $a, $b, $n]) {
            $deleted += $this->markMissingAppointments($branch, $a, $b, $seen, $n);
        }
        if ($deleted) {
            $counts['deactivated'] = $deleted;
        }

        return $this->withErrors($counts);
    }

    /**
     * Appointments we have for a week Zenoti just answered, that Zenoti no longer returns,
     * were deleted there. An empty reply for a week we hold several bookings for is treated
     * as a Zenoti hiccup, not a mass deletion.
     */
    protected function markMissingAppointments(Branch $branch, string $from, string $to, array $seen, int $returned): int
    {
        $missing = Appointment::where('branch_id', $branch->id)->whereNotNull('zenoti_id')
            ->whereBetween('start_time', [\Carbon\Carbon::parse($from)->startOfDay(), \Carbon\Carbon::parse($to)->endOfDay()])
            ->get()->reject(fn ($a) => isset($seen[$a->zenoti_id]));
        if ($missing->isEmpty() || ($returned === 0 && $missing->count() > 3)) {
            return 0;
        }
        foreach ($missing as $appt) {
            $appt->forceFill(['deleted_in_zenoti_at' => now()])->save();
            ActivityRecorder::record([
                'branch_id' => $appt->branch_id, 'employee_id' => $appt->employee_id,
                'subject_type' => 'appointment', 'subject_id' => $appt->id, 'subject_label' => $this->appointmentLabel($appt),
                'action' => 'deleted', 'changes' => ['status' => [$appt->status, 'Deleted in Zenoti']],
                'source' => $this->activitySource,
            ]);
        }

        return $missing->count();
    }

    protected function appointmentLabel(Appointment $appt): string
    {
        $guest = $appt->guest_id ? Guest::withoutGlobalScope('live')->find($appt->guest_id) : null;

        return trim(($appt->service_name ?: 'Appointment').($guest ? ' for '.$guest->full_name : '').($appt->start_time ? ' on '.$appt->start_time->format('j M Y H:i') : ''));
    }

    /** Log what changed on an appointment since the last sync. */
    protected function trackAppointment(Appointment $appt, ?array $before, bool $wasDeleted, array $row): void
    {
        $track = ['status', 'start_time', 'employee_id', 'service_name', 'price'];
        $action = null;
        $changes = [];
        if ($before === null) {
            // Only fresh bookings: a history import would otherwise log thousands of old ones.
            if ($appt->booked_at && $appt->booked_at->gt(now()->subDays(2))) {
                $action = 'booked';
            }
        } else {
            foreach ($track as $f) {
                $old = $before[$f] instanceof \DateTimeInterface ? $before[$f]->format('Y-m-d H:i') : (string) $before[$f];
                $new = $appt->{$f} instanceof \DateTimeInterface ? $appt->{$f}->format('Y-m-d H:i') : (string) $appt->{$f};
                if ($f === 'price') {
                    // 0 -> a price is the price being read for the first time, not a change in Zenoti.
                    [$old, $new] = (float) $old == 0.0 ? [$new, $new] : [number_format((float) $old, 2, '.', ''), number_format((float) $new, 2, '.', '')];
                }
                if ($old !== $new) {
                    $changes[$f] = [$old, $new];
                }
            }
            if ($wasDeleted) {
                $action = 'restored';
            } elseif (isset($changes['status']) && $appt->status === 'Cancelled') {
                $action = 'cancelled';
            } elseif (isset($changes['status']) && $appt->status === 'No-show') {
                $action = 'no-show';
            } elseif ($changes) {
                $action = 'changed';
            }
        }
        if (! $action) {
            return;
        }
        [$actorId, $actorName] = ZenotiMapper::actor($row, $action === 'booked' ? 'created' : 'changed');
        if (isset($changes['employee_id'])) {
            $changes['employee'] = array_map(fn ($id) => $id ? Employee::find($id)?->full_name : null, $changes['employee_id']);
            unset($changes['employee_id']);
        }
        ActivityRecorder::record([
            'branch_id' => $appt->branch_id, 'employee_id' => $appt->employee_id,
            'actor_employee_id' => $this->employeeId($actorId), 'actor_name' => $actorName,
            'subject_type' => 'appointment', 'subject_id' => $appt->id, 'subject_label' => $this->appointmentLabel($appt),
            'action' => $action, 'changes' => $changes ?: null, 'source' => $this->activitySource,
        ]);
    }

    public function upsertAppointment(array $row, ?Branch $branch, array &$counts = []): Appointment
    {
        $data = ZenotiMapper::appointment($row);
        // The row's own center wins over the center we asked for (guests can book at other branches).
        if ($data['_center'] && $branch?->zenoti_center_id !== (string) $data['_center']) {
            $branch = $this->branchFor((string) $data['_center']) ?? $branch;
        }
        $guestId = null;
        if (is_array($row['guest'] ?? null) && filled($row['guest']['id'] ?? null)) {
            $guestId = $this->upsertEmbeddedGuest($row['guest'], $branch, $data['booked_at'] ?? $data['start_time'])->id;
        }

        $this->rememberFields('appointments', $row);
        $appt = Appointment::withoutGlobalScope('live')->firstOrNew(['zenoti_id' => $data['zenoti_id']]);
        $counts[$appt->exists ? 'updated' : 'created'] = ($counts[$appt->exists ? 'updated' : 'created'] ?? 0) + 1;
        $before = $appt->exists ? ['status' => $appt->status, 'start_time' => $appt->start_time, 'employee_id' => $appt->employee_id, 'service_name' => $appt->service_name, 'price' => $appt->price] : null;
        $wasDeleted = (bool) $appt->deleted_in_zenoti_at;
        $data['booked_at'] ??= $appt->booked_at ?? $data['start_time'];
        $appt->fill(Arr::only($data, ['service_name', 'status', 'raw_status', 'price', 'start_time', 'end_time', 'booked_at']) + [
            'branch_id' => $branch?->id ?? $appt->branch_id,
            'employee_id' => $this->employeeId($data['_employee']) ?? $appt->employee_id,
            // Who booked it (Callgear staff book for other branches' providers).
            'booked_by_employee_id' => $this->employeeId(ZenotiMapper::actor($row, 'created')[0]) ?? $appt->booked_by_employee_id,
            'guest_id' => $guestId ?? $this->guestId($data['_guest']) ?? $appt->guest_id,
            // Only the ids and status: full rows (~3 KB each, thousands a week) would fill the database.
            'raw' => Arr::only($row, ['appointment_id', 'appointment_group_id', 'invoice_id', 'invoice_item_id', 'status', 'center_id', 'start_time', 'end_time', 'price', 'final_price', 'sale_price'])
                + (is_array($row['service'] ?? null) ? ['service' => Arr::only($row['service'], ['id', 'name', 'price', 'code', 'category'])] : []),
            'deleted_in_zenoti_at' => null,
        ])->save();
        $this->trackAppointment($appt, $before, $wasDeleted, $row);

        return $appt;
    }

    // ------------------------------------------------------------------ sales

    protected function syncSales(): array
    {
        $counts = ['created' => 0, 'updated' => 0];
        [$from, $to] = $this->window();
        $accrual = config('zenoti.sales_source', 'accrual') === 'accrual';
        $short = [];
        foreach ($this->syncedBranches() as $branch) {
            $this->perCenter($branch, $counts, function () use ($branch, $from, $to, &$counts, $accrual, &$short) {
                foreach ($this->chunks($from, $to, branch: $branch) as [$a, $b]) {
                    $rows = $accrual ? $this->client->salesAccrual($branch->zenoti_center_id, $a, $b) : $this->client->sales($branch->zenoti_center_id, $a, $b);
                    if ($accrual && $this->client->lastShortfall) {
                        $short[] = $branch->name.' '.$this->client->lastShortfall;
                    }
                    foreach ($rows as $row) {
                        $this->upsertSale($row, $branch, $counts, $a);
                    }
                }
            });
        }
        if ($short) {
            $counts['message'] = 'Zenoti stopped paging early: '.implode('; ', array_slice($short, 0, 3));
        }

        return $this->withErrors($counts);
    }

    public function upsertSale(array $row, ?Branch $branch, array &$counts = [], ?string $fallbackDate = null): Sale
    {
        $this->rememberFields('sales', $row);
        $data = ZenotiMapper::sale($row);
        // A line with no date we can read still belongs to the day range it was fetched for.
        $data['sold_at'] ??= $fallbackDate ? \Illuminate\Support\Carbon::parse($fallbackDate) : null;
        $sale = Sale::firstOrNew(['zenoti_id' => $data['zenoti_id']]);
        $counts[$sale->exists ? 'updated' : 'created'] = ($counts[$sale->exists ? 'updated' : 'created'] ?? 0) + 1;
        $this->matcher ??= new EmployeeMatcher;
        $sale->fill(Arr::except($data, ['zenoti_id', '_employee', '_employee_code', '_employee_name', '_guest', '_created_by', '_created_by_name']) + [
            'branch_id' => $branch?->id ?? $sale->branch_id,
            'employee_id' => $this->matcher->match($data) ?? $sale->employee_id,
            'created_by_employee_id' => $this->matcher->match(['_employee' => $data['_created_by'], '_employee_name' => $data['_created_by_name']]) ?? $sale->created_by_employee_id,
            'guest_id' => $this->guestId($data['_guest']) ?? $sale->guest_id,
            'raw' => $row,
        ])->save();

        return $sale;
    }

    // ------------------------------------------------------------ collections

    protected function syncCollections(): array
    {
        if (! config('zenoti.endpoints.collections')) {
            return ['message' => 'Skipped: ZENOTI_EP_COLLECTIONS not set'];
        }
        $counts = ['created' => 0, 'updated' => 0];
        [$from, $to] = $this->window();
        foreach ($this->syncedBranches() as $branch) {
            $this->perCenter($branch, $counts, function () use ($branch, $from, $to, &$counts) {
                foreach ($this->chunks($from, $to, branch: $branch) as [$a, $b]) {
                    foreach ($this->client->collections($branch->zenoti_center_id, $a, $b) as $row) {
                        $data = ZenotiMapper::collection($row);
                        $c = Collection::firstOrNew(['zenoti_id' => $data['zenoti_id']]);
                        $counts[$c->exists ? 'updated' : 'created']++;
                        $c->fill(Arr::except($data, ['zenoti_id', '_employee', '_guest']) + [
                            'branch_id' => $branch->id,
                            'employee_id' => $this->employeeId($data['_employee']),
                            'guest_id' => $this->guestId($data['_guest']),
                            'raw' => $row,
                        ]);
                        $c->collected_at ??= now();
                        $c->save();
                    }
                }
            });
        }

        return $this->withErrors($counts);
    }

    // ------------------------------------------------------------------ leads

    protected function syncLeads(): array
    {
        if (! config('zenoti.endpoints.leads')) {
            return ['message' => 'Skipped: ZENOTI_EP_LEADS not set'];
        }
        $counts = ['created' => 0, 'updated' => 0];
        [$from, $to] = $this->window();
        foreach ($this->syncedBranches() as $branch) {
            $this->perCenter($branch, $counts, function () use ($branch, $from, $to, &$counts) {
                foreach ($this->chunks($from, $to, branch: $branch) as [$a, $b]) {
                    foreach ($this->client->leads($branch->zenoti_center_id, $a, $b) as $row) {
                        $this->upsertLead($row, $branch, $counts);
                    }
                }
            });
        }

        return $this->withErrors($counts);
    }

    public function upsertLead(array $row, ?Branch $branch, array &$counts = []): Lead
    {
        $data = ZenotiMapper::lead($row);
        $lead = Lead::firstOrNew(['source' => 'zenoti', 'external_id' => $data['external_id']]);
        $counts[$lead->exists ? 'updated' : 'created'] = ($counts[$lead->exists ? 'updated' : 'created'] ?? 0) + 1;
        $lead->fill(Arr::except($data, ['external_id', '_employee']) + [
            'branch_id' => $branch?->id ?? $lead->branch_id,
            'employee_id' => $this->employeeId($data['_employee']) ?? $lead->employee_id,
            'raw' => $row,
        ]);
        $lead->lead_at ??= now();
        $lead->save();

        return $lead;
    }

    // --------------------------------------------------------------- webhooks

    /**
     * Zenoti webhooks carry an event name such as "Guest.Created",
     * "Appointment.Updated" or "Employee.Deleted" and the object under "data".
     */
    public function handleWebhook(WebhookEvent $event): void
    {
        $payload = $event->payload;
        $type = strtolower((string) ($event->event_type ?? ''));
        $data = $payload['data'] ?? $payload['Data'] ?? $payload;
        $data = is_array($data) ? $data : [];
        $centerId = ZenotiMapper::pick($data, ['center_id', 'center.id']) ?? ZenotiMapper::pick($payload, ['center_id']);
        $branch = $centerId ? Branch::where('zenoti_center_id', $centerId)->first() : null;
        $deleted = Str::contains($type, ['delete', 'remove', 'cancel']);

        $status = 'processed';
        $this->activitySource = 'webhook';
        match (true) {
            Str::contains($type, 'employee') => $deleted
                ? Employee::where('zenoti_id', ZenotiMapper::employee($data)['zenoti_id'])->update(['is_active' => false])
                : $this->upsertEmployee($data, $branch),
            Str::contains($type, 'guest') => $deleted
                ? $this->markGuestDeleted(Guest::where('zenoti_id', ZenotiMapper::guest($data)['zenoti_id'])->first())
                : $this->upsertGuest($data, $branch),
            Str::contains($type, 'appointment') => $this->upsertAppointment(
                $deleted ? array_merge($data, ['status' => 'cancelled']) : $data, $branch
            ),
            Str::contains($type, ['invoice', 'sale', 'collection']) => $this->handleInvoiceEvent($data, $branch),
            Str::contains($type, ['opportunit', 'lead']) => $this->upsertLead($data, $branch),
            default => $status = 'ignored',
        };

        $event->update(['status' => $status, 'processed_at' => now()]);
    }

    /** Invoice payloads rarely carry line detail, so re-pull the day's sales for that center. */
    protected function handleInvoiceEvent(array $data, ?Branch $branch): void
    {
        $items = $data['items'] ?? $data['invoice_items'] ?? null;
        if (is_array($items) && $items) {
            foreach ($items as $item) {
                $this->upsertSale($item + ['invoice_no' => $data['invoice_no'] ?? null, 'sale_date' => $data['invoice_date'] ?? now()], $branch);
            }

            return;
        }
        if ($branch && $this->isConfigured()) {
            $today = now()->toDateString();
            foreach ($this->client->sales($branch->zenoti_center_id, $today, $today) as $row) {
                $this->upsertSale($row, $branch);
            }
        }
    }

    // ---------------------------------------------------------------- helpers

    /**
     * Zenoti rejects long date ranges on list/report endpoints, so history is
     * fetched in 7-day slices.
     */
    protected function chunks(string $from, string $to, int $days = 7, ?Branch $branch = null): iterable
    {
        $out = [];
        $start = \Carbon\Carbon::parse($from)->startOfDay();
        $end = \Carbon\Carbon::parse($to)->startOfDay();
        while ($start <= $end) {
            $sliceEnd = $start->copy()->addDays($days - 1)->min($end);
            $out[] = [$start->toDateString(), $sliceEnd->toDateString()];
            $start = $sliceEnd->copy()->addDay();
        }
        if (! $branch) {
            return $out;
        }

        return (function () use ($out, $branch) {
            foreach ($out as $i => $slice) {
                SyncRecorder::progress(sprintf('Working: %s, %s to %s (%d of %d)', $branch->name, $slice[0], $slice[1], $i + 1, count($out)));
                yield $slice;
            }
        })();
    }

    /** One failing center should not stop the others; errors are reported on the sync run. */
    protected function perCenter(Branch $branch, array &$counts, callable $work): void
    {
        try {
            $work();
        } catch (\App\Services\Integrations\SyncCancelled $e) {
            throw $e;
        } catch (\Throwable $e) {
            report($e);
            $counts['errors'][] = $branch->name.': '.mb_substr($e->getMessage(), 0, 300);
        }
    }

    protected function withErrors(array $counts): array
    {
        if (($counts['created'] ?? 0) + ($counts['updated'] ?? 0) === 0 && $this->client->lastEmptyResponse && empty($counts['message'])) {
            $counts['message'] = 'Zenoti returned no rows. Last reply: '.$this->client->lastEmptyResponse;
        }
        if (! empty($counts['errors'])) {
            if (($counts['created'] ?? 0) + ($counts['updated'] ?? 0) === 0) {
                throw new \RuntimeException('All centers failed. '.implode(' | ', $counts['errors']));
            }
            $counts['message'] = count($counts['errors']).' center(s) failed. '.implode(' | ', $counts['errors']);
            unset($counts['errors']);
        }

        return $counts;
    }

    protected function window(int $forwardDays = 0): array
    {
        return [
            now()->subDays($this->lookbackDays ?? (int) config('zenoti.lookback_days', 3))->toDateString(),
            now()->addDays($forwardDays)->toDateString(),
        ];
    }

    /** @var array<string, int|null> */
    private array $employeeIds = [];

    private ?EmployeeMatcher $matcher = null;

    private array $fieldsSaved = [];

    /** Keep the field names of one row per kind and run (masked, see ZenotiMapper::fieldMap) for Admin › Integrations. */
    private function rememberFields(string $kind, array $row): void
    {
        if (isset($this->fieldsSaved[$kind])) {
            return;
        }
        $this->fieldsSaved[$kind] = true;
        try {
            \App\Models\Setting::put("zenoti.fields.$kind", json_encode(['at' => now()->toIso8601String(), 'fields' => ZenotiMapper::fieldMap($row)]));
        } catch (\Throwable) {
        }
    }

    /** "sync" or "webhook", stored on activity rows. */
    protected string $activitySource = 'sync';

    /** @var array<string, Branch|null> */
    private array $branches = [];

    protected function employeeId($zenotiId): ?int
    {
        if (! $zenotiId) {
            return null;
        }

        return $this->employeeIds[(string) $zenotiId] ??= Employee::where('zenoti_id', (string) $zenotiId)->value('id');
    }

    protected function branchFor(string $centerId): ?Branch
    {
        return array_key_exists($centerId, $this->branches)
            ? $this->branches[$centerId]
            : $this->branches[$centerId] = Branch::where('zenoti_center_id', $centerId)->first();
    }

    protected function guestId($zenotiId): ?int
    {
        return $zenotiId ? Guest::withoutGlobalScope('live')->where('zenoti_id', (string) $zenotiId)->value('id') : null;
    }
}
