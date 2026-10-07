<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\Guest;
use App\Models\User;
use App\Models\WebhookEvent;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ZenotiSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        config([
            'zenoti.enabled' => true, 'zenoti.api_key' => 'test-key', 'zenoti.webhook_secret' => 'hook-secret',
            'zenoti.base_url' => 'https://api.zenoti.test/v1', 'zenoti.sales_source' => 'salesreport',
        ]);
    }

    public function test_sync_imports_centers_employees_and_appointments_and_deactivates_removed_staff(): void
    {
        $employees = [
            ['id' => 'e1', 'personal_info' => ['first_name' => 'Asha', 'last_name' => 'K', 'email' => 'asha@spa.test'], 'job_info' => ['name' => 'Therapist']],
            ['id' => 'e2', 'personal_info' => ['first_name' => 'Ravi', 'last_name' => 'M', 'email' => 'ravi@spa.test'], 'job_info' => ['name' => 'Front desk']],
        ];
        Http::fake([
            'api.zenoti.test/v1/centers?*' => Http::response(['centers' => [['id' => 'c1', 'name' => 'Juhu', 'code' => 'JH']], 'page_info' => ['total' => 1]]),
            'api.zenoti.test/v1/centers/c1/employees*' => Http::sequence()
                ->push(['employees' => $employees, 'page_info' => ['total' => 2]])
                ->push(['employees' => [$employees[0]], 'page_info' => ['total' => 1]]),
            'api.zenoti.test/v1/appointments*' => Http::response(['appointments' => [[
                'appointment_id' => 'a1', 'service' => ['name' => 'Facial'], 'status' => 'Closed', 'price' => ['final' => 1500],
                'start_time' => now()->toIso8601String(), 'therapist' => ['id' => 'e1'], 'guest' => ['id' => 'g1'],
            ]]]),
        ]);

        $this->artisan('integrations:sync zenoti --entity=centers')->assertSuccessful();
        $this->artisan('integrations:sync zenoti --entity=employees')->assertSuccessful();
        $this->artisan('integrations:sync zenoti --entity=appointments')->assertSuccessful();

        $branch = Branch::where('zenoti_center_id', 'c1')->first();
        $this->assertNotNull($branch);
        $this->assertEquals(2, Employee::where('branch_id', $branch->id)->count());
        $asha = Employee::where('zenoti_id', 'e1')->first();
        $this->assertNotNull($asha->user_id, 'employee login auto-created');
        $this->assertTrue(User::find($asha->user_id)->hasRole('employee'));
        $this->assertFalse(User::find($asha->user_id)->is_active);
        $appt = Appointment::where('zenoti_id', 'a1')->first();
        $this->assertEquals($asha->id, $appt->employee_id);
        $this->assertEquals('Serviced', $appt->status);
        $this->assertEquals('Closed', $appt->raw_status);

        // Ravi removed in Zenoti
        $this->artisan('integrations:sync zenoti --entity=employees')->assertSuccessful();
        $this->assertFalse(Employee::where('zenoti_id', 'e2')->value('is_active'));
    }

    public function test_webhook_requires_token_and_upserts_guest(): void
    {
        $this->postJson('/webhooks/zenoti', ['event_type' => 'Guest.Created'])->assertStatus(401);

        $this->postJson('/webhooks/zenoti?token=hook-secret', [
            'event_type' => 'Guest.Created',
            'data' => ['id' => 'g9', 'personal_info' => ['first_name' => 'Neha', 'email' => 'neha@x.test']],
        ])->assertOk()->assertJson(['status' => 'processed']);
        $this->assertEquals('Neha', Guest::where('zenoti_id', 'g9')->value('first_name'));

        $this->postJson('/webhooks/zenoti?token=hook-secret', ['event_type' => 'Guest.Deleted', 'data' => ['id' => 'g9']])->assertOk();
        $this->assertNull(Guest::where('zenoti_id', 'g9')->first());
        $this->assertEquals(2, WebhookEvent::count());
    }

    public function test_long_history_is_fetched_in_weekly_chunks_and_one_bad_center_does_not_stop_others(): void
    {
        Branch::create(['organization_id' => \App\Models\Organization::create(['name' => 'O'])->id, 'name' => 'Good', 'zenoti_center_id' => 'good']);
        Branch::create(['organization_id' => \App\Models\Organization::first()->id, 'name' => 'Bad', 'zenoti_center_id' => 'bad']);
        Http::fake(function ($request) {
            parse_str(parse_url($request->url(), PHP_URL_QUERY), $q);
            if ($q['center_id'] === 'bad') {
                return Http::response('nope', 500);
            }
            $days = (strtotime($q['end_date']) - strtotime($q['start_date'])) / 86400;
            $this->assertLessThanOrEqual(7, $days, 'each request covers at most 7 days (end_date exclusive)');

            return Http::response(['appointments' => [[
                'appointment_id' => 'a-'.$q['start_date'], 'status' => 'NoShow', 'start_time' => $q['start_date'].'T10:00:00',
            ]]]);
        });

        $this->artisan('integrations:sync zenoti --entity=appointments --days=30')->assertSuccessful();
        $run = \App\Models\SyncRun::latest('id')->first();
        $this->assertEquals('success', $run->status);
        $this->assertStringContainsString('Bad', $run->message);
        $this->assertGreaterThanOrEqual(8, Appointment::count());
        $this->assertEquals(Appointment::count(), Appointment::where('status', 'No-show')->count());
    }

    public function test_callgear_is_skipped_until_configured(): void
    {
        $this->artisan('integrations:sync callgear')->expectsOutputToContain('not configured')->assertSuccessful();
    }

    public function test_callgear_calls_land_on_the_zenoti_employee_matched_by_name(): void
    {
        config(['callgear.enabled' => true, 'callgear.access_token' => 'cg', 'callgear.webhook_secret' => null, 'callgear.base_url' => 'https://cg.test/v2.0']);
        $zenoti = Employee::create(['source' => 'zenoti', 'first_name' => 'Hadeer', 'last_name' => 'Ali']);
        // A duplicate an earlier sync created before name matching existed.
        $dup = Employee::create(['source' => 'callgear', 'first_name' => 'Hadeer', 'last_name' => 'Ali', 'callgear_id' => '77']);
        $old = \App\Models\Call::create(['external_id' => 'old', 'employee_id' => $dup->id, 'started_at' => now()->subDay()]);
        $reports = 0;
        Http::fake(['cg.test/*' => function ($request) use (&$reports) {
            if ($request['method'] === 'get.employees') {
                return Http::response(['result' => ['data' => [['id' => 77, 'first_name' => 'Hadeer', 'last_name' => ' ALI']]]]);
            }
            $reports++;

            return Http::response(['result' => ['data' => $reports === 1 ? [[
                'id' => 5001, 'start_time' => now()->subDays(20)->toDateTimeString(), 'direction' => 'in', 'is_lost' => false,
                'total_duration' => 95, 'employees' => [['employee_id' => 77]],
            ]] : []]]);
        }]);

        $this->artisan('integrations:sync callgear --days=30')->assertSuccessful();

        $this->assertSame('77', $zenoti->fresh()->callgear_id);
        $this->assertNull($dup->fresh()->callgear_id);
        $this->assertSame($zenoti->id, \App\Models\Call::where('external_id', '5001')->value('employee_id'));
        $this->assertSame($zenoti->id, $old->fresh()->employee_id);
        $this->assertSame(5, $reports); // 30 days in weekly chunks
        $this->actingAs(User::where('email', 'admin@your-domain.com')->first() ?? User::first())
            ->get(route('admin.integrations.index'))->assertOk()->assertDontSee('CallGear sync is off');
    }

    public function test_web_runs_fetch_guest_profiles_in_short_rounds_that_continue(): void
    {
        foreach (range(1, 5) as $i) {
            Guest::create(['source' => 'zenoti', 'zenoti_id' => "w$i", 'first_name' => 'G']);
        }
        Http::fake(fn ($request) => Http::response(['id' => basename(parse_url($request->url(), PHP_URL_PATH)), 'personal_info' => ['first_name' => 'N']]));
        config(['zenoti.web_guest_seconds' => 0]); // one profile per round
        $request = \App\Models\SyncRequest::create(['provider' => 'zenoti', 'entity' => 'guests', 'status' => 'queued']);

        $this->artisan('integrations:run-requests --web')->assertSuccessful();
        $request->refresh();
        $this->assertSame('queued', $request->status);
        $this->assertSame(1, $request->progress);
        $this->assertStringContainsString('4 left', $request->message);

        foreach (range(1, 4) as $round) {
            $this->artisan('integrations:run-requests --web')->assertSuccessful();
        }
        $this->assertSame('done', $request->fresh()->status);
        $this->assertSame(5, Guest::whereNotNull('profile_synced_at')->count());

        // A round the host killed goes back in the queue instead of sitting at "running".
        $dead = \App\Models\SyncRequest::create(['provider' => 'zenoti', 'entity' => 'guests', 'status' => 'running']);
        \App\Models\SyncRequest::whereKey($dead->id)->update(['updated_at' => now()->subMinutes(10)]);
        $this->artisan('integrations:run-requests --web')->assertSuccessful();
        $this->assertSame('done', $dead->fresh()->status);
    }

    public function test_callgear_request_details_for_support_mask_the_key(): void
    {
        config(['callgear.enabled' => true, 'callgear.access_token' => 'abcd1234secretvalue9876', 'callgear.base_url' => 'https://cg.test/v2.0']);
        Http::fake(['cg.test/*' => Http::response(['jsonrpc' => '2.0', 'error' => ['code' => -32001, 'message' => 'Access token is invalid', 'echo' => 'abcd1234secretvalue9876']]), '*' => Http::response('1.2.3.4')]);

        $this->actingAs(User::where('email', 'admin@your-domain.com')->first() ?? User::first())
            ->get(route('admin.integrations.test', ['call' => 'callgear_debug']))
            ->assertOk()->assertSee('https://cg.test/v2.0')->assertSee('abcd***************9876')
            ->assertSee('Access token is invalid')->assertDontSee('secretvalue');
    }

    public function test_callgear_call_finished_notification_pulls_latest_calls(): void
    {
        config(['callgear.enabled' => true, 'callgear.access_token' => 'cg', 'callgear.webhook_secret' => 'cg-secret', 'callgear.base_url' => 'https://cg.test/v2.0']);
        Http::fake(['cg.test/*' => Http::response(['result' => ['data' => [[
            'id' => 7001, 'start_time' => now()->subMinutes(3)->toDateTimeString(), 'direction' => 'in', 'total_duration' => 60,
        ]]]])]);

        // A notification with fields we don't know still brings the call in through the API.
        $this->postJson('/webhooks/callgear?token=cg-secret', ['notification_name' => 'Call finished', 'foo' => 'bar'])->assertOk();
        $this->assertTrue(\App\Models\Call::where('external_id', '7001')->exists());
        $this->assertSame('processed', WebhookEvent::latest('id')->value('status'));

        // Notification fields are read directly too.
        $this->postJson('/webhooks/callgear?token=cg-secret', ['call_session_id' => 7002, 'total_time_duration' => 45, 'start_time' => now()->toDateTimeString()])->assertOk();
        $this->assertSame(45, \App\Models\Call::where('external_id', '7002')->value('duration_seconds'));
    }

    public function test_automatic_sync_runs_without_cron_from_the_web(): void
    {
        config(['callgear.enabled' => true, 'callgear.access_token' => 'cg', 'callgear.base_url' => 'https://cg.test/v2.0']);
        Http::fake(['cg.test/*' => Http::response(['result' => ['data' => [['id' => 8801, 'start_time' => now()->toDateTimeString()]]]]), '*' => Http::response([])]);

        $this->get('/cron/run?token=wrong')->assertForbidden();
        $this->get('/cron/run?token='.\App\Support\AutoSync::token())->assertOk();
        $this->assertTrue(\App\Models\Call::where('external_id', '8801')->exists());
        $this->assertNotNull(\App\Support\AutoSync::lastTick());
        $this->assertFalse(\App\Support\AutoSync::due());

        $admin = User::where('email', 'admin@your-domain.com')->first() ?? User::first();
        $this->actingAs($admin)->get(route('admin.integrations.index'))->assertOk()->assertSee('Automatic sync')->assertSee('cron-job.org');
        $this->actingAs($admin)->post(route('autosync.nudge'))->assertOk();
    }

    public function test_automatic_sales_sync_takes_one_branch_per_round_and_rereads_two_weeks(): void
    {
        config(['zenoti.sales_source' => 'accrual']);
        $org = \App\Models\Organization::firstOrCreate(['name' => 'GF']);
        $m2 = Branch::create(['organization_id' => $org->id, 'name' => 'M2', 'zenoti_center_id' => 'c-m2', 'is_active' => true]);
        $jum = Branch::create(['organization_id' => $org->id, 'name' => 'Jumeirah', 'zenoti_center_id' => 'c-jum', 'is_active' => true]);
        Http::fake(function ($request) {
            if (str_contains($request->url(), 'accrual_basis')) {
                $center = $request['center_ids'][0];

                return Http::response(['sales' => (int) ($request->data()['page'] ?? 1) > 1 || str_contains($request->url(), 'page=2') ? [] : [[
                    'invoice_item_id' => 'L-'.$center.'-'.$request['start_date'], 'invoice_no' => 'S-'.$center, 'center_id' => $center, 'sale_date' => now()->toDateString(),
                    'item_type' => 'Service', 'sales_exc_tax' => 100, 'sales_inc_tax' => 105, 'status' => 'Closed']], 'total' => 1]);
            }

            return Http::response([]);
        });
        \Illuminate\Support\Facades\Cache::forget('autosync.sales_cursor');

        $this->assertSame((string) $m2->id, \App\Support\AutoSync::salesRound());
        $this->assertTrue(\App\Models\Sale::where('branch_id', $m2->id)->exists());
        $this->assertFalse(\App\Models\Sale::where('branch_id', $jum->id)->exists());
        // The first round of a branch re-reads 14 days (two 7-day pages of the report).
        Http::assertSent(fn ($r) => str_contains($r->url(), 'accrual_basis') && $r['center_ids'] === ['c-m2'] && str_starts_with($r['start_date'], now()->subDays(14)->toDateString()));

        $this->assertSame((string) $jum->id, \App\Support\AutoSync::salesRound());
        $this->assertTrue(\App\Models\Sale::where('branch_id', $jum->id)->exists());
        $this->assertSame((string) $m2->id, \App\Support\AutoSync::salesRound()); // back to the first
    }

    public function test_callgear_agents_match_partial_names_and_can_be_linked_by_hand(): void
    {
        config(['callgear.enabled' => true, 'callgear.access_token' => 'cg', 'callgear.base_url' => 'https://cg.test/v2.0']);
        $hadeer = Employee::create(['source' => 'zenoti', 'first_name' => 'Hadeer', 'last_name' => 'Gaber Saad Hassan']);
        $maria = Employee::create(['source' => 'zenoti', 'first_name' => 'Mahdjouba', 'last_name' => 'Mezeddek']);
        Http::fake(['cg.test/*' => Http::response(['result' => ['data' => [
            ['id' => 11, 'first_name' => 'Hadeer', 'last_name' => 'Gaber'],
            ['id' => 12, 'full_name' => 'Maria CC'],
        ]]])]);
        $this->artisan('integrations:sync callgear --entity=employees')->assertSuccessful();

        $this->assertSame('11', $hadeer->fresh()->callgear_id);
        $this->assertNull($maria->fresh()->callgear_id);
        $agent = Employee::where('callgear_id', '12')->firstOrFail();
        $this->assertSame('callgear', $agent->source);
        \App\Models\Call::create(['external_id' => 'm1', 'employee_id' => $agent->id, 'started_at' => now()]);

        $admin = User::where('email', 'admin@your-domain.com')->first() ?? User::first();
        $this->actingAs($admin)->get(route('employees.show', $maria))->assertOk()->assertSee('Maria CC');
        $this->actingAs($admin)->put(route('employees.callgear', $maria), ['agent_id' => $agent->id])->assertRedirect();
        $this->assertSame('12', $maria->fresh()->callgear_id);
        $this->assertSame($maria->id, \App\Models\Call::where('external_id', 'm1')->value('employee_id'));
        $this->assertFalse((bool) $agent->fresh()->is_active);

        // The next sync keeps the hand-made link.
        $this->artisan('integrations:sync callgear --entity=employees')->assertSuccessful();
        $this->assertSame('12', $maria->fresh()->callgear_id);
    }

    public function test_sync_now_is_queued_and_run_by_the_scheduler(): void
    {
        Http::fake([
            'api.zenoti.test/v1/centers?*' => Http::response(['centers' => [['id' => 'c1', 'name' => 'Juhu', 'code' => 'JH']], 'page_info' => ['total' => 1]]),
            'api.zenoti.test/v1/appointments*' => Http::response(['appointments' => [[
                'appointment_id' => 'q1', 'status' => 'Closed', 'start_time' => now()->subDays(40)->toIso8601String(),
            ]]]),
        ]);
        $this->artisan('integrations:sync zenoti --entity=centers')->assertSuccessful();
        $admin = User::role('super-admin')->first();
        @unlink(\App\Console\Commands\RunSyncRequests::heartbeatFile());

        $this->actingAs($admin)->get(route('admin.integrations.index'))->assertOk()->assertSee('cron');
        $this->actingAs($admin)->post(route('admin.integrations.sync', 'zenoti'), ['entity' => 'appointments', 'days' => 90])->assertRedirect();
        $this->actingAs($admin)->post(route('admin.integrations.sync', 'zenoti'), ['days' => 90]); // ignored while one is queued
        $this->assertEquals(1, \App\Models\SyncRequest::count());
        $this->assertEquals(0, Appointment::count(), 'nothing runs inside the web request');

        $this->artisan('integrations:run-requests')->assertSuccessful();
        $req = \App\Models\SyncRequest::first();
        $this->assertEquals('done', $req->status, (string) $req->message);
        $this->assertEquals(90, $req->days);
        $this->assertEquals(1, Appointment::count());
        $this->actingAs($admin)->get(route('admin.integrations.index'))->assertOk()->assertDontSee("isn't running", false)->assertSee('last 90 days');
    }

    public function test_guests_come_from_appointments_and_stale_runs_are_cleared(): void
    {
        Http::fake([
            'api.zenoti.test/v1/centers?*' => Http::response(['centers' => [['id' => 'c1', 'name' => 'Urban Look', 'code' => 'UL']], 'page_info' => ['total' => 1]]),
            'api.zenoti.test/v1/appointments*' => Http::response(['appointments' => [[
                'appointment_id' => 'ap1', 'status' => 'Closed', 'start_time' => now()->subDays(2)->toIso8601String(),
                'guest' => ['id' => 'g-77', 'first_name' => 'Mariam', 'last_name' => 'A', 'mobile' => ['number' => '0551234567']],
            ]]]),
        ]);
        $stale = \App\Models\SyncRun::create(['provider' => 'zenoti', 'entity' => 'appointments', 'status' => 'running', 'started_at' => now()->subHour()]);
        \App\Models\SyncRun::whereKey($stale->id)->update(['updated_at' => now()->subHour()]);

        $this->artisan('integrations:sync zenoti --entity=centers')->assertSuccessful();
        $this->assertEquals('failed', $stale->fresh()->status);

        $this->artisan('integrations:sync zenoti --entity=guests')->assertSuccessful();
        $this->assertEquals('success', \App\Models\SyncRun::where('entity', 'guests')->latest('id')->first()->status);

        $this->artisan('integrations:sync zenoti --entity=appointments')->assertSuccessful();
        $guest = Guest::where('zenoti_id', 'g-77')->first();
        $this->assertNotNull($guest);
        $this->assertEquals('0551234567', $guest->phone);
        $this->assertEquals($guest->id, Appointment::first()->guest_id);
        $this->assertEquals(now()->subDays(2)->toDateString(), $guest->registered_at->toDateString());
        $this->assertNull(\App\Models\SyncRun::where('entity', 'appointments')->latest('id')->first()->message, 'progress text is replaced by the result');
    }

    public function test_guest_profiles_are_filled_in_by_guest_id(): void
    {
        $guest = Guest::create(['zenoti_id' => '179ff0d2', 'first_name' => 'Mariam', 'source' => 'zenoti', 'registered_at' => now()]);
        Http::fake(['api.zenoti.test/v1/guests/179ff0d2' => Http::response(['id' => '179ff0d2', 'created_date' => '2025-03-01T10:00:00',
            'personal_info' => ['first_name' => 'Mariam', 'last_name' => 'Ali', 'email' => 'm@example.test', 'mobile_phone' => ['number' => '0559998888'], 'gender' => 'Female']])]);

        $this->artisan('integrations:sync zenoti --entity=guests')->assertSuccessful();
        $guest->refresh();
        $this->assertEquals('success', \App\Models\SyncRun::where('entity', 'guests')->latest('id')->first()->status);
        $this->assertEquals(['Ali', 'm@example.test', '0559998888', '2025-03-01'], [$guest->last_name, $guest->email, $guest->phone, $guest->registered_at->toDateString()]);
        $this->assertNotNull($guest->profile_synced_at);
        $this->assertStringContainsString('GuestProfileV2.aspx?UserId=179ff0d2', $guest->zenoti_url);

        Http::fake(['*' => Http::response('nope', 500)]);
        Guest::create(['zenoti_id' => 'bad', 'source' => 'zenoti']);
        $this->artisan('integrations:sync zenoti --entity=guests')->assertSuccessful();
        $run = \App\Models\SyncRun::where('entity', 'guests')->latest('id')->first();
        $this->assertEquals('failed', $run->status);
        $this->assertStringContainsString('500', $run->message);
    }

    public function test_all_guest_profiles_are_fetched_and_failures_do_not_block(): void
    {
        foreach (range(1, 450) as $i) {
            Guest::create(['zenoti_id' => "g$i", 'source' => 'zenoti']);
        }
        Http::fake(function ($request) {
            $id = basename(parse_url($request->url(), PHP_URL_PATH));

            return in_array($id, ['g1', 'g2'], true) ? Http::response('boom', 500)
                : Http::response(['id' => $id, 'personal_info' => ['first_name' => 'N'.$id]]);
        });

        // A scheduled-size run stops at its limit; --all-guests goes through all of them.
        config(['zenoti.guest_details_per_run' => 100]);
        $this->artisan('integrations:sync zenoti --entity=guests')->assertSuccessful();
        $this->assertSame(98, Guest::whereNotNull('profile_synced_at')->count());
        $this->artisan('integrations:sync zenoti --entity=guests --all-guests')->assertSuccessful();
        $this->assertSame(448, Guest::whereNotNull('profile_synced_at')->count());
        $this->assertNotNull(Guest::where('zenoti_id', 'g1')->value('profile_failed_at'));
        $this->assertSame("2 earlier failure(s) wait for tomorrow's retry.", \App\Models\SyncRun::where('entity', 'guests')->latest('id')->value('message'));
    }

    public function test_tag_sync_pulls_every_branch_but_only_tagged_staff_guest_profiles(): void
    {
        $this->artisan('integrations:sync zenoti --entity=sales --tag=Nobody')->assertFailed();
        $b = \App\Models\Branch::create(['name' => 'Q1', 'zenoti_center_id' => 'c-q1', 'is_active' => true, 'organization_id' => \App\Models\Organization::firstOrCreate(['name' => 'GF'])->id]);
        $m = \App\Models\Branch::create(['name' => 'M2', 'zenoti_center_id' => 'c-m2', 'is_active' => true, 'organization_id' => $b->organization_id]);
        $e = \App\Models\Employee::create(['first_name' => 'Agent', 'branch_id' => $b->id, 'source' => 'zenoti', 'organization_id' => $b->organization_id]);
        $e->tags()->attach(\App\Models\Tag::idsFor(['Callgear']));
        Http::fake(['*' => Http::response([])]);
        $this->artisan('integrations:sync zenoti --entity=sales --days=1 --tag=callgear')->expectsOutputToContain('1 employee(s) tagged Callgear')->assertSuccessful();
        Http::assertSent(fn ($r) => str_contains($r->url(), 'c-m2'));
        Http::assertSent(fn ($r) => str_contains($r->url(), 'c-q1'));

        // Guest profiles: only guests the agent booked (even in another branch), not everyone's.
        $booked = Guest::create(['zenoti_id' => 'g-booked', 'source' => 'zenoti']);
        $other = Guest::create(['zenoti_id' => 'g-other', 'source' => 'zenoti']);
        Appointment::create(['zenoti_id' => 'z1', 'branch_id' => $m->id, 'booked_by_employee_id' => $e->id, 'guest_id' => $booked->id, 'start_time' => now(), 'status' => 'Booked']);
        Appointment::create(['zenoti_id' => 'z2', 'branch_id' => $m->id, 'guest_id' => $other->id, 'start_time' => now(), 'status' => 'Booked']);
        Http::fake(['api.zenoti.test/v1/guests/*' => Http::response(['id' => 'g-booked', 'personal_info' => ['first_name' => 'Bo']]), '*' => Http::response([])]);
        $this->artisan('integrations:sync zenoti --entity=guests --all-guests --tag=Callgear')->assertSuccessful();
        $this->assertNotNull($booked->fresh()->profile_synced_at);
        $this->assertNull($other->fresh()->profile_synced_at);
    }

    public function test_sync_now_with_branch_and_tag_only_pulls_that_branch_and_those_guests(): void
    {
        $b = Branch::create(['name' => 'Q1', 'zenoti_center_id' => 'c-q1', 'is_active' => true, 'organization_id' => \App\Models\Organization::firstOrCreate(['name' => 'GF'])->id]);
        $m = Branch::create(['name' => 'M2', 'zenoti_center_id' => 'c-m2', 'is_active' => true, 'organization_id' => $b->organization_id]);
        $agent = Employee::create(['first_name' => 'Agent', 'branch_id' => $b->id, 'source' => 'zenoti', 'organization_id' => $b->organization_id]);
        $other = Employee::create(['first_name' => 'Other', 'branch_id' => $b->id, 'source' => 'zenoti', 'organization_id' => $b->organization_id]);
        $agent->tags()->attach(\App\Models\Tag::idsFor(['Callgear']));
        $mine = Guest::create(['zenoti_id' => 'gm', 'source' => 'zenoti']);
        $theirs = Guest::create(['zenoti_id' => 'gt', 'source' => 'zenoti']);
        Appointment::create(['zenoti_id' => 'x1', 'branch_id' => $b->id, 'employee_id' => $agent->id, 'guest_id' => $mine->id, 'start_time' => now(), 'status' => 'Serviced']);
        Appointment::create(['zenoti_id' => 'x2', 'branch_id' => $b->id, 'employee_id' => $other->id, 'guest_id' => $theirs->id, 'start_time' => now(), 'status' => 'Serviced']);
        Http::fake(['api.zenoti.test/v1/guests/*' => Http::response(['id' => 'gm', 'personal_info' => ['first_name' => 'Mina']]), '*' => Http::response([])]);

        $admin = User::role('super-admin')->first();
        $this->actingAs($admin)->post(route('admin.integrations.sync', 'zenoti'), ['entity' => 'guests', 'branch_id' => $b->id, 'tag' => 'Callgear'])->assertRedirect();
        $this->assertDatabaseHas('sync_requests', ['branch_id' => $b->id, 'tag' => 'Callgear']);
        $this->actingAs($admin)->get(route('admin.integrations.index'))->assertSee('Q1, tag Callgear');
        $this->artisan('integrations:run-requests')->assertSuccessful();

        $this->assertNotNull($mine->fresh()->profile_synced_at);
        $this->assertNull($theirs->fresh()->profile_synced_at);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'c-m2') || str_contains($r->url(), 'guests/gt'));
        $this->actingAs($admin)->post(route('admin.integrations.sync', 'zenoti'), ['tag' => 'Made up'])->assertSessionHasErrors('tag');
    }

    public function test_guest_profiles_pause_when_zenoti_quota_is_reached(): void
    {
        foreach (range(1, 10) as $i) {
            Guest::create(['zenoti_id' => "q$i", 'source' => 'zenoti']);
        }
        Http::fake(function ($request) {
            $id = basename(parse_url($request->url(), PHP_URL_PATH));

            return $id === 'q4' ? Http::response(['code' => 429, 'message' => 'Account quota exceeded!'], 429)
                : Http::response(['id' => $id, 'personal_info' => ['first_name' => 'N'.$id]]);
        });
        $this->artisan('integrations:sync zenoti --entity=guests --all-guests')->assertSuccessful();

        $this->assertSame(3, Guest::whereNotNull('profile_synced_at')->count());
        $this->assertSame(0, Guest::whereNotNull('profile_failed_at')->count()); // q4 is not blamed
        $run = \App\Models\SyncRun::where('entity', 'guests')->latest('id')->first();
        $this->assertSame('success', $run->status);
        $this->assertStringContainsString("Paused: Zenoti's API quota was reached after 3 profile(s)", $run->message);
        Http::assertSentCount(4); // no retries of the 429
    }

    public function test_appointments_as_a_bare_list_are_saved_and_empty_replies_are_explained(): void
    {
        Http::fake([
            'api.zenoti.test/v1/centers?*' => Http::response(['centers' => [['id' => 'c1', 'name' => 'Q1', 'code' => 'Q1']], 'page_info' => ['total' => 1]]),
            'api.zenoti.test/v1/appointments*' => Http::response([[
                'appointment_id' => 'bare1', 'status' => 1, 'start_time' => now()->toIso8601String(),
                'guest' => ['id' => 'g-bare', 'first_name' => 'Noor', 'last_name' => 'H'],
            ]]),
            'api.zenoti.test/v1/sales/*' => Http::response(['error' => null, 'total' => 0]),
        ]);
        $this->artisan('integrations:sync zenoti --entity=centers')->assertSuccessful();
        $this->artisan('integrations:sync zenoti --entity=appointments')->assertSuccessful();
        $this->assertEquals(1, Appointment::where('zenoti_id', 'bare1')->where('status', 'Serviced')->count());
        $this->assertEquals(1, Guest::where('zenoti_id', 'g-bare')->count());

        $this->artisan('integrations:sync zenoti --entity=sales')->assertSuccessful();
        $this->assertStringContainsString('Zenoti returned no rows', (string) \App\Models\SyncRun::where('entity', 'sales')->latest('id')->first()->message);

        $admin = User::role('super-admin')->first();
        $this->actingAs($admin)->get(route('admin.integrations.test', ['call' => 'appointments', 'center' => 'c1']))->assertOk()->assertSee('bare1');
    }

    public function test_sales_come_from_the_accrual_report_with_sold_by_and_created_by(): void
    {
        config(['zenoti.sales_source' => 'accrual', 'zenoti.page_size' => 2]);
        $org = \App\Models\Organization::firstOrCreate(['name' => 'GF']);
        $b = Branch::create(['organization_id' => $org->id, 'name' => 'Hamriyah', 'zenoti_center_id' => 'c1', 'is_active' => true]);
        $agent = Employee::create(['first_name' => 'Nejma', 'last_name' => 'D', 'zenoti_id' => 'e-agent', 'branch_id' => $b->id, 'organization_id' => $org->id, 'source' => 'zenoti']);
        $therapist = Employee::create(['first_name' => 'Tia', 'last_name' => 'T', 'zenoti_id' => 'e-ther', 'branch_id' => $b->id, 'organization_id' => $org->id, 'source' => 'zenoti']);
        $line = fn ($id, $center = 'c1') => ['invoice_item_id' => $id, 'invoice_no' => 'INV'.$id, 'center_id' => $center, 'sale_date' => now()->toDateString().'T10:00:00',
            'item_name' => 'Facial', 'item_type' => 'Service', 'qty' => 1, 'sales_exc_tax' => 200, 'sales_inc_tax' => 210, 'discount' => 0, 'price' => 200,
            'sold_by' => 'Tia T', 'sold_by_id' => 'e-ther', 'created_by' => 'Nejma D', 'created_by_id' => 'e-agent', 'guest_id' => 'g1'];
        Http::fake(function ($request) use ($line) {
            if ($request->method() !== 'POST') {
                return Http::response([]);
            }
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $q);

            return Http::response(match ((int) ($q['page'] ?? 1)) {
                1 => ['sales' => [$line('L1'), $line('L2')], 'total' => 3, 'page_info' => null, 'error' => null],
                2 => ['sales' => [$line('L3'), $line('X9', 'other-center')], 'total' => 3, 'page_info' => null, 'error' => null],
                default => ['sales' => [], 'total' => 3, 'error' => null],
            });
        });

        $this->artisan('integrations:sync zenoti --entity=sales --days=1')->assertSuccessful();

        $this->assertSame(3, \App\Models\Sale::count());
        $sale = \App\Models\Sale::where('zenoti_id', 'L1')->first();
        $this->assertEquals(200, $sale->net_amount);
        $this->assertSame('Service', $sale->category);
        $this->assertSame($therapist->id, $sale->employee_id);
        $this->assertSame($agent->id, $sale->created_by_employee_id);
        Http::assertSent(fn ($r) => $r->method() === 'POST' && str_contains($r->url(), 'reports/sales/accrual_basis/flat_file') && $r['center_ids'] === ['c1']);
    }

    public function test_appointments_remember_who_booked_them_and_show_on_employee_pages(): void
    {
        $org = \App\Models\Organization::firstOrCreate(['name' => 'GF']);
        $b = Branch::create(['organization_id' => $org->id, 'name' => 'Warqa', 'zenoti_center_id' => 'c1', 'is_active' => true]);
        $agent = Employee::create(['first_name' => 'Hadeer', 'last_name' => 'G', 'zenoti_id' => 'e-agent', 'branch_id' => $b->id, 'organization_id' => $org->id, 'source' => 'zenoti']);
        Employee::create(['first_name' => 'Asma', 'last_name' => 'F', 'zenoti_id' => 'e-ther', 'branch_id' => $b->id, 'organization_id' => $org->id, 'source' => 'zenoti']);
        $agent->tags()->attach(\App\Models\Tag::idsFor(['Callgear']));
        Http::fake(['api.zenoti.test/v1/appointments*' => Http::response(['appointments' => [[
            'appointment_id' => 'b1', 'service' => ['name' => 'Wax'], 'status' => 0, 'price' => ['final' => 150], 'center_id' => 'c1',
            'start_time' => now()->addDay()->toIso8601String(), 'creation_date' => now()->toIso8601String(),
            'therapist' => ['id' => 'e-ther'], 'guest' => ['id' => 'g1'], 'created_by' => ['id' => 'e-agent', 'name' => 'Hadeer G'],
        ]]]), '*' => Http::response([])]);

        $this->artisan('integrations:sync zenoti --entity=appointments --days=1')->assertSuccessful();

        $appt = Appointment::where('zenoti_id', 'b1')->first();
        $this->assertSame($agent->id, $appt->booked_by_employee_id);
        $this->assertNotSame($agent->id, $appt->employee_id);
        $admin = User::role('super-admin')->first();
        $this->actingAs($admin)->get(route('employees.index', ['tag_id' => $agent->tags()->first()->id]))->assertOk()->assertSee('booked 1');
        $this->actingAs($admin)->get(route('employees.show', $agent))->assertOk()->assertSee('Booked this month (any provider)');

        $this->seed(\Database\Seeders\CallgearPerformanceDashboardSeeder::class);
        $cg = \App\Models\Dashboard::where('name', 'Callgear Performance')->first();
        $w = $cg->widgets()->where('title', 'Appointments booked')->sole();
        $data = $this->actingAs($admin)->getJson(route('dashboards.widget-data', [$cg, $w]))->json();
        $this->assertSame([$agent->full_name], $data['labels']);
        $this->assertEquals(1, $data['values'][0]);
    }

    public function test_sales_probe_counts_rows_per_zenoti_sales_call(): void
    {
        $org = \App\Models\Organization::firstOrCreate(['name' => 'GF']);
        $b = Branch::create(['organization_id' => $org->id, 'name' => 'Juhu', 'zenoti_center_id' => 'c1']);
        $emp = Employee::create(['first_name' => 'Cara', 'last_name' => 'Gear', 'zenoti_id' => 'e1', 'branch_id' => $b->id, 'organization_id' => $org->id, 'source' => 'zenoti']);
        $emp->tags()->sync(\App\Models\Tag::idsFor(['Callgear']));
        Http::fake(function ($request) {
            if ($request->method() === 'POST' && str_contains($request->url(), 'accrual_basis')) {
                return Http::response(['data' => [['invoice_no' => 'I1', 'sold_by' => 'Cara Gear'], ['invoice_no' => 'I2', 'sold_by' => 'Someone']]]);
            }

            return Http::response(['center_sales_report' => []]);
        });

        $admin = User::role('super-admin')->first();
        $this->actingAs($admin)->get(route('admin.integrations.test', ['call' => 'sales_probe', 'center' => 'c1']))
            ->assertOk()->assertSee('"rows": 2')->assertSee('"rows_naming_callgear_staff": 1')->assertSee('sold_by');
    }

    public function test_sales_check_shows_accrual_lines_and_how_agent_revenue_counts_them(): void
    {
        config(['zenoti.sales_source' => 'accrual']);
        $org = \App\Models\Organization::firstOrCreate(['name' => 'GF']);
        Branch::create(['organization_id' => $org->id, 'name' => 'Juhu', 'zenoti_center_id' => 'c1']);
        Http::fake(['*accrual_basis*' => Http::sequence()
            ->push(['sales' => [
                ['invoice_no' => 'I1', 'item_type' => 'Service', 'sales_exc_tax' => 100, 'cash' => 60, 'gift_card' => 40, 'created_by' => 'Mary'],
                ['invoice_no' => 'I2', 'item_type' => 'Product', 'sales_exc_tax' => 50],
            ], 'total' => 2])->whenEmpty(Http::response(['sales' => []])), '*' => Http::response([])]);
        $admin = User::role('super-admin')->first();
        $res = $this->actingAs($admin)->get(route('admin.integrations.test', ['call' => 'sales', 'center' => 'c1']));
        $res->assertOk()->assertSee('"counted_for_agents_total": 60')->assertSee('"amount_from": "cash"')->assertSee('gift_card')->assertSee('Not a service');
    }

    public function test_employee_filter_check_reports_which_parameter_narrows_rows(): void
    {
        $org = \App\Models\Organization::firstOrCreate(['name' => 'GF']);
        $b = Branch::create(['organization_id' => $org->id, 'name' => 'Juhu', 'zenoti_center_id' => 'c1']);
        $emp = Employee::create(['first_name' => 'Cara', 'last_name' => 'Gear', 'zenoti_id' => 'e1', 'branch_id' => $b->id, 'organization_id' => $org->id, 'source' => 'zenoti']);
        $emp->tags()->sync(\App\Models\Tag::idsFor(['Callgear']));
        $row = fn ($id, $e) => ['appointment_id' => $id, 'start_time' => now()->toIso8601String(), 'therapist' => ['id' => $e]];
        Http::fake(function ($request) use ($row) {
            if (str_contains($request->url(), '/appointments')) {
                return Http::response(['appointments' => str_contains($request->url(), 'employee_id=e1')
                    ? [$row('a1', 'e1')] : [$row('a1', 'e1'), $row('a2', 'e2')]]);
            }

            return Http::response(['center_sales_report' => []]);
        });

        $admin = User::role('super-admin')->first();
        $this->actingAs($admin)->get(route('admin.integrations.test', ['call' => 'employee_filter', 'center' => 'c1']))
            ->assertOk()->assertSee('Cara Gear')->assertSee('2 rows, 1 of them this employee')->assertSee('1 rows, 1 of them this employee');
    }

    public function test_callgear_incoming_call_is_logged_and_matched_to_a_guest(): void
    {
        config(['callgear.webhook_secret' => 'cg-secret', 'callgear.incoming_reply' => '{"returned_code": 1}']);
        $org = \App\Models\Organization::firstOrCreate(['name' => 'Main Organization']);
        $branch = Branch::create(['organization_id' => $org->id, 'name' => 'Jumeirah', 'phone' => '04 123 4567']);
        $guest = Guest::create(['first_name' => 'Adam', 'last_name' => 'Amira', 'phone' => '55 655 5675', 'source' => 'zenoti', 'zenoti_id' => 'g1']);
        $this->assertEquals('556555675', $guest->phone_key);

        $this->get('/webhooks/callgear/incoming?token=wrong&numa=971556555675')->assertStatus(401);
        $this->get('/webhooks/callgear/incoming?token=cg-secret&cdr_id=900&start_time=1790000000&numa=971556555675&numb=97141234567')
            ->assertOk()->assertExactJson(['returned_code' => 1]);

        $call = \App\Models\Call::where('external_id', '900')->first();
        $this->assertEquals([$guest->id, $branch->id, 'in'], [$call->guest_id, $call->branch_id, $call->direction]);

        config(['callgear.incoming_reply' => '']);
        $this->post('/webhooks/callgear/incoming?token=cg-secret', ['cdr_id' => '901', 'numa' => '0500000000'])->assertOk()->assertExactJson([]);
        $this->assertNull(\App\Models\Call::where('external_id', '901')->value('guest_id'));

        $admin = User::role('super-admin')->first();
        $this->actingAs($admin)->get(route('calls.index', ['period' => 'last_90_days']))->assertOk()->assertSee('Adam');
    }

    public function test_sales_fall_back_to_one_call_per_item_type(): void
    {
        Http::fake([
            'api.zenoti.test/v1/centers?*' => Http::response(['centers' => [['id' => 'c1', 'name' => 'Urban Look', 'code' => 'UL']], 'page_info' => ['total' => 1]]),
            'api.zenoti.test/v1/sales/*' => function ($request) {
                parse_str(parse_url($request->url(), PHP_URL_QUERY), $q);
                if (! isset($q['item_type'])) {
                    return Http::response(['StatusCode' => 502, 'Message' => 'Invalid item_type'], 400);
                }
                if ($q['item_type'] === '5') {
                    return Http::response(['Message' => 'Invalid item_type'], 400);
                }

                return Http::response(['center_sales_report' => [['invoice_item_id' => 's-'.$q['item_type'].'-'.$q['start_date'], 'item_type' => $q['item_type'], 'final_sale_price' => 10]]]);
            },
        ]);
        $this->artisan('integrations:sync zenoti --entity=centers')->assertSuccessful();
        $this->artisan('integrations:sync zenoti --entity=sales')->assertSuccessful();
        $run = \App\Models\SyncRun::where('entity', 'sales')->latest('id')->first();
        $this->assertEquals('success', $run->status, (string) $run->message);
        $this->assertGreaterThan(0, \App\Models\Sale::count());

        \App\Console\Commands\RunSyncRequests::beat();
        $this->assertNotNull(\App\Console\Commands\RunSyncRequests::lastBeat());
    }

    public function test_changes_cancellations_and_deletions_are_logged_and_admins_notified(): void
    {
        $start = now()->addDay()->setTime(11, 0);
        $rows = [
            ['appointment_id' => 'A1', 'status' => 0, 'start_time' => $start->toIso8601String(), 'service' => ['name' => 'Facial'], 'therapist' => ['id' => 'e1'],
                'creation_date' => now()->toIso8601String(), 'guest' => ['id' => 'g1', 'first_name' => 'Sara', 'last_name' => 'K']],
            ['appointment_id' => 'A2', 'status' => 0, 'start_time' => $start->toIso8601String(), 'service' => ['name' => 'Manicure'], 'therapist' => ['id' => 'e1'],
                'creation_date' => now()->toIso8601String(), 'guest' => ['id' => 'g2', 'first_name' => 'Huda', 'last_name' => 'M']],
        ];
        $reply = $rows;
        Http::fake([
            'api.zenoti.test/v1/centers?*' => Http::response(['centers' => [['id' => 'c1', 'name' => 'Juhu', 'code' => 'JH']], 'page_info' => ['total' => 1]]),
            'api.zenoti.test/v1/centers/c1/employees*' => Http::response(['employees' => [
                ['id' => 'e1', 'personal_info' => ['first_name' => 'Asha', 'last_name' => 'K']],
                ['id' => 'e9', 'personal_info' => ['first_name' => 'Front', 'last_name' => 'Desk']],
            ], 'page_info' => ['total' => 2]]),
            'api.zenoti.test/v1/appointments*' => function () use (&$reply) {
                return Http::response($reply);
            },
        ]);
        $this->artisan('integrations:sync zenoti --entity=centers')->assertSuccessful();
        $this->artisan('integrations:sync zenoti --entity=employees')->assertSuccessful();
        $this->artisan('integrations:sync zenoti --entity=appointments')->assertSuccessful();
        $this->assertEquals(2, \App\Models\ActivityLog::where('action', 'booked')->count());

        // A1 cancelled by the front desk, A2 deleted in Zenoti.
        $reply = [array_merge($rows[0], ['status' => -1, 'cancelled_by' => ['id' => 'e9', 'name' => 'Front Desk']])];
        $this->artisan('integrations:sync zenoti --entity=appointments')->assertSuccessful();

        $cancel = \App\Models\ActivityLog::where('action', 'cancelled')->first();
        $this->assertEquals(Employee::where('zenoti_id', 'e9')->value('id'), $cancel->actor_employee_id);
        $this->assertEquals(['Open & confirmed', 'Cancelled'], $cancel->changes['status']);
        $this->assertEquals(1, \App\Models\ActivityLog::where('action', 'deleted')->count());
        $this->assertEquals(1, Appointment::count(), 'deleted appointment drops out of lists');
        $this->assertEquals(2, Appointment::withoutGlobalScope('live')->count());

        $admin = User::role('super-admin')->first();
        $this->assertEquals(1, $admin->unreadNotifications()->count());
        $this->assertStringContainsString('Deleted appointment', $admin->unreadNotifications()->first()->data['title']);

        $asha = Employee::where('zenoti_id', 'e1')->first();
        $this->actingAs($admin)->get(route('employees.show', $asha))->assertOk()->assertSee('Cancelled appointment')->assertSee('Front Desk');
        $this->actingAs($admin)->get(route('activity.index', ['action' => 'deleted']))->assertOk()->assertSee('Manicure');
        $this->actingAs($admin)->get(route('notifications'))->assertOk()->assertSee('Deleted appointment');
        $this->assertEquals(0, $admin->unreadNotifications()->count());

        // An empty reply for a busy week is not a mass deletion.
        $reply = [];
        Appointment::withoutGlobalScope('live')->update(['deleted_in_zenoti_at' => null]);
        Appointment::create(['zenoti_id' => 'A3', 'branch_id' => $asha->branch_id, 'start_time' => $start]);
        Appointment::create(['zenoti_id' => 'A4', 'branch_id' => $asha->branch_id, 'start_time' => $start]);
        $this->artisan('integrations:sync zenoti --entity=appointments')->assertSuccessful();
        $this->assertEquals(4, Appointment::count());
    }
}
