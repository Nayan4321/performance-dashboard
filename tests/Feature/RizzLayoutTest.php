<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Dashboard;
use App\Models\Sale;
use App\Models\User;
use App\Notifications\RecordDeletedInZenoti;
use App\Support\Branding;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoDataSeeder;
use Database\Seeders\OverviewDashboardSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class RizzLayoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->seed(DemoDataSeeder::class);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path('app/branding'));
        File::deleteDirectory(storage_path('app/avatars'));
        parent::tearDown();
    }

    private function admin(): User
    {
        return User::role('super-admin')->first();
    }

    public function test_layout_has_topbar_notification_tabs_and_profile_menu(): void
    {
        $admin = $this->admin();
        $log = ActivityLog::create(['action' => 'deleted', 'subject_type' => 'appointment', 'subject_id' => 1, 'subject_label' => 'Facial for Jane', 'source' => 'test', 'occurred_at' => now()]);
        $admin->notify(new RecordDeletedInZenoti($log));

        $html = $this->actingAs($admin)->get(route('help'))->assertOk()->getContent();
        foreach (['class="topbar', 'class="startbar', 'notif-alerts', 'notif-activity', 'notif-system', 'Deleted appointment', 'Account Settings', 'Security', 'Help Center', 'Logout', 'Branding &amp; logo'] as $needle) {
            $this->assertStringContainsString($needle, $html, $needle);
        }

        $this->actingAs($admin)->postJson(route('notifications.read'))->assertOk();
        $this->assertSame(0, $admin->unreadNotifications()->count());
    }

    public function test_login_page_uses_new_design(): void
    {
        $this->get('/login')->assertOk()->assertSee('auth-header-box', false)->assertSee("Let's Get Started", false);
    }

    public function test_super_admin_can_upload_logo_and_it_shows_everywhere(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->get(route('admin.branding.index'))->assertOk();
        $this->actingAs($admin)->put(route('admin.branding.update'), [
            'brand_name' => 'Grand Flora', 'logo' => UploadedFile::fake()->image('logo.png', 300, 80),
        ])->assertSessionHasNoErrors();

        $this->assertSame('Grand Flora', Branding::name());
        $this->assertNotNull(Branding::logo());
        $this->get(route('branding.asset', 'logo'))->assertOk();
        $this->actingAs($admin)->get(route('help'))->assertSee('brand-logo-lg', false)->assertSee('Grand Flora');
        auth()->logout();
        $this->get('/login')->assertSee(Branding::logo(), false);

        $this->actingAs($admin)->put(route('admin.branding.update'), ['brand_name' => '', 'remove_logo' => 1]);
        $this->assertNull(Branding::logo());
        $this->get(route('branding.asset', 'logo'))->assertNotFound();

        $manager = User::where('email', 'manager@demo.test')->first();
        $this->actingAs($manager)->get(route('admin.branding.index'))->assertForbidden();
    }

    public function test_profile_photo_upload(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->put(route('profile'), ['name' => $admin->name, 'avatar' => UploadedFile::fake()->image('me.jpg', 200, 200)])
            ->assertSessionHasNoErrors();
        $this->assertNotNull($admin->fresh()->avatarUrl());
        $this->actingAs($admin)->get(route('avatars.show', $admin))->assertOk();
    }

    public function test_rizz_widget_types_return_trend_series_and_target(): void
    {
        $admin = $this->admin();
        $this->seed(OverviewDashboardSeeder::class);
        $d = Dashboard::where('name', OverviewDashboardSeeder::NAME)->first()->load('widgets');
        $this->assertCount(8, $d->widgets->pluck('type')->unique());

        foreach ($d->widgets as $w) {
            $this->actingAs($admin)->getJson(route('dashboards.widget-data', [$d, $w]))->assertOk()->assertJsonMissing(['error']);
        }

        $stat = $d->widgets->firstWhere('type', 'stat');
        $from = now()->subDays(9)->toDateString();
        $to = now()->toDateString();
        $data = $this->actingAs($admin)->getJson(route('dashboards.widget-data', [$d, $stat])."?from=$from&to=$to")->json();
        $prev = Sale::whereBetween('sold_at', [now()->subDays(19)->startOfDay(), now()->subDays(10)->endOfDay()])->sum('net_amount');
        $this->assertEqualsWithDelta($prev, $data['previous'], 1);
        $this->assertArrayHasKey('change', $data);

        $spark = $d->widgets->firstWhere('title', 'Sales trend');
        $data = $this->actingAs($admin)->getJson(route('dashboards.widget-data', [$d, $spark])."?from=$from&to=$to")->json();
        $this->assertEqualsWithDelta($data['value'], array_sum($data['values']), 1);

        $radial = $d->widgets->firstWhere('type', 'radial');
        $data = $this->actingAs($admin)->getJson(route('dashboards.widget-data', [$d, $radial]))->json();
        $this->assertEquals(100000, $data['target']);
        $this->assertEqualsWithDelta($data['value'] / 1000, $data['percent'], 0.1);

        $this->actingAs($admin)->get(route('dashboards.show', $d))->assertOk()->assertSee('apexcharts.min.js', false);
        $this->actingAs($admin)->get(route('dashboards.edit', $d))->assertOk()->assertSee('Rizz style');
    }

    public function test_builder_saves_rizz_widget_options(): void
    {
        $admin = $this->admin();
        $d = Dashboard::first();
        $this->actingAs($admin)->post(route('widgets.store', $d), [
            'title' => 'Revenue', 'type' => 'stat', 'dataset' => 'sales', 'aggregate' => 'sum', 'metric_field' => 'net_amount',
            'date_range' => 'this_month', 'width' => 3, 'options' => ['icon' => 'cash-stack', 'color' => 'info', 'target' => ''],
        ])->assertSessionHasNoErrors();
        $w = $d->widgets()->where('title', 'Revenue')->first();
        $this->assertSame(['icon' => 'cash-stack', 'color' => 'info'], $w->options);

        $this->actingAs($admin)->post(route('widgets.store', $d), [
            'title' => 'Bad icon', 'type' => 'stat', 'dataset' => 'sales', 'aggregate' => 'count',
            'date_range' => 'this_month', 'width' => 3, 'options' => ['icon' => '"><script>'],
        ])->assertSessionHasErrors('options.icon');

        $this->actingAs($admin)->post(route('widgets.store', $d), [
            'title' => 'Area', 'type' => 'area', 'dataset' => 'sales', 'aggregate' => 'count', 'date_range' => 'this_month', 'width' => 6,
        ])->assertSessionHasErrors('group_by');
    }

    public function test_management_dashboard_follows_metric_definitions(): void
    {
        $admin = $this->admin();
        $d = Dashboard::where('name', \Database\Seeders\ManagementDashboardSeeder::NAME)->first()->load('widgets');
        $get = fn ($title) => $this->actingAs($admin)->getJson(route('dashboards.widget-data', [$d, $d->widgets->firstWhere('title', $title)]).'?from=2000-01-01')->assertOk()->json();
        foreach ($d->widgets as $w) {
            $this->actingAs($admin)->getJson(route('dashboards.widget-data', [$d, $w]))->assertOk()->assertJsonMissing(['error']);
        }

        $sales = (float) Sale::sum('net_amount');
        $visits = Sale::selectRaw('COUNT(DISTINCT COALESCE(invoice_no, CAST(id AS CHAR))) as c')->value('c');
        $this->assertEqualsWithDelta($sales, $get('Total Sales (AED, ex VAT)')['value'], 1);
        $this->assertEquals($visits, $get('Footfall (distinct visits)')['value']);
        $this->assertEqualsWithDelta(round($sales / max($visits, 1), 2), $get('Average Ticket Value')['value'], 0.05);
        $disc = (float) Sale::sum('discount');
        $this->assertEqualsWithDelta($disc * 100 / ($sales + $disc), $get('Discount %')['value'], 0.05);

        $guests = Sale::whereNotNull('guest_id')->distinct()->count('guest_id');
        $this->assertEquals($guests, $get('New guests')['value'], 'all-time: every guest is new once');
        $week = $get('Day of week: sales');
        $this->assertEqualsWithDelta($sales, array_sum($week['values']), 1);
        $table = $get('Branch detail')['rows'];
        $this->assertEqualsWithDelta($sales, array_sum(array_column($table, 'sales')), 1);
        $this->assertEquals($table[0]['sales'] - 1000000, $table[0]['variance']);
        $this->assertEmpty(array_diff($week['labels'], ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat']));
    }

    public function test_employee_targets_are_salary_times_seven(): void
    {
        $admin = $this->admin();
        $e = \App\Models\Employee::whereHas('sales')->first();
        $this->actingAs($admin)->put(route('employees.target', $e), ['salary' => 5000, 'section' => 'Hair'])->assertSessionHasNoErrors();
        $this->assertEquals(35000, $e->fresh()->monthlyTarget());
        $this->actingAs($admin)->get(route('employees.show', $e))->assertOk()->assertSee('Monthly target');

        $d = Dashboard::where('name', \Database\Seeders\EmployeePerformanceDashboardSeeder::NAME)->first()->load('widgets');
        foreach ($d->widgets as $w) {
            $this->actingAs($admin)->getJson(route('dashboards.widget-data', [$d, $w]))->assertOk()->assertJsonMissing(['error']);
        }
        $rows = collect($this->actingAs($admin)->getJson(route('dashboards.widget-data', [$d, $d->widgets->firstWhere('type', 'employee_table')]).'?from=2000-01-01')->json('rows'));
        $row = $rows->firstWhere('employee', $e->full_name);
        $sales = (float) \App\Models\Sale::where('employee_id', $e->id)->sum('net_amount');
        $this->assertEquals(35000, $row['target']);
        $this->assertEqualsWithDelta(round($sales / 35000 * 100, 1), $row['achievement'], 0.1);
        $this->assertSame('Hair', $row['section']);
    }

    public function test_employee_tags_can_be_edited_and_filter_dashboards(): void
    {
        $admin = $this->admin();
        [$a, $b] = \App\Models\Employee::whereHas('sales')->take(2)->get()->all();

        $this->actingAs($admin)->put(route('employees.tags', $a), ['tags' => 'Senior stylist, senior STYLIST ,Night shift'])->assertSessionHasNoErrors();
        $this->assertSame(['Night shift', 'Senior stylist'], $a->fresh()->tags->pluck('name')->all());
        $this->actingAs($admin)->post(route('employees.bulk-tags'), ['employee_ids' => [$b->id], 'tag' => 'senior stylist', 'action' => 'add'])->assertSessionHasNoErrors();
        $this->assertSame(2, \App\Models\Tag::count());
        $tag = \App\Models\Tag::where('name', 'Senior stylist')->first();
        $this->assertSame(2, $tag->employees()->count());

        $this->actingAs($admin)->get(route('employees.index', ['tag_id' => $tag->id]))->assertOk()
            ->assertSee($a->full_name)->assertSee($b->full_name)->assertSee('Add tag');
        $this->actingAs($admin)->get(route('employees.show', $a))->assertOk()->assertSee('Senior stylist');

        $d = Dashboard::where('name', \Database\Seeders\EmployeePerformanceDashboardSeeder::NAME)->first()->load('widgets');
        $this->actingAs($admin)->get(route('dashboards.show', $d))->assertOk()->assertSee('id="tagFilter"', false);
        $table = $d->widgets->firstWhere('type', 'employee_table');
        $rows = collect($this->actingAs($admin)->getJson(route('dashboards.widget-data', [$d, $table]).'?from=2000-01-01&tag_id='.$tag->id)->json('rows'));
        $this->assertEqualsCanonicalizing([$a->full_name, $b->full_name], $rows->pluck('employee')->all());
        $list = $this->actingAs($admin)->getJson(route('dashboards.employees', $d).'?tag_id='.$tag->id)->json();
        $this->assertCount(2, $list);

        // Removing the last use of a tag deletes it.
        $this->actingAs($admin)->put(route('employees.tags', $a), ['tags' => 'Senior stylist']);
        $this->assertFalse(\App\Models\Tag::where('name', 'Night shift')->exists());

        $manager = User::role('branch-manager')->first();
        if ($manager && ! $manager->can('dashboards.manage')) {
            $this->actingAs($manager)->put(route('employees.tags', $a), ['tags' => 'x'])->assertForbidden();
        }
    }

    public function test_callgear_dashboard_scores_tagged_agents_against_daily_targets(): void
    {
        $admin = $this->admin();
        [$a, $c] = \App\Models\Employee::whereHas('calls')->take(1)->get()->concat(\App\Models\Employee::doesntHave('calls')->take(1)->get())->all()
            + [null, null];
        $c ??= \App\Models\Employee::create(['first_name' => 'Quiet', 'last_name' => 'Agent', 'branch_id' => $a->branch_id, 'organization_id' => $a->organization_id, 'is_active' => true, 'source' => 'manual']);
        $this->actingAs($admin)->post(route('employees.bulk-tags'), ['employee_ids' => [$a->id, $c->id], 'tag' => 'callgear', 'action' => 'add']);
        \App\Models\Call::create(['employee_id' => $a->id, 'branch_id' => $a->branch_id, 'status' => 'answered', 'duration_seconds' => 600, 'started_at' => now()]);
        $today = now()->toDateString();

        $d = Dashboard::where('name', \Database\Seeders\CallgearPerformanceDashboardSeeder::NAME)->first()->load('widgets');
        $this->assertSame('Callgear', $d->employee_tag);
        $this->assertTrue($d->isCallgearOnly());
        foreach ($d->widgets as $w) {
            $this->actingAs($admin)->getJson(route('dashboards.widget-data', [$d, $w]))->assertOk()->assertJsonMissing(['error']);
        }

        $table = $this->actingAs($admin)->getJson(route('dashboards.widget-data', [$d, $d->widgets->firstWhere('type', 'agent_table')])."?from=$today&to=$today")->json();
        $rows = collect($table['rows'])->keyBy('key');
        $this->assertEqualsCanonicalizing([$a->id, $c->id], $rows->keys()->all());
        $callsToday = \App\Models\Call::where('employee_id', $a->id)->whereDate('started_at', $today)->count();
        $this->assertSame(1, $table['days']);
        $this->assertSame($callsToday, $rows[$a->id]['calls']);
        $this->assertEquals(round($callsToday / 100 * 100, 1), $rows[$a->id]['calls_pct']);
        $this->assertSame(0, $rows[$c->id]['calls']);
        $this->assertSame('Low', $rows[$c->id]['status']);

        $bars = $this->actingAs($admin)->getJson(route('dashboards.widget-data', [$d, $d->widgets->firstWhere('title', 'Talk minutes per day vs target (120)')])."?from=$today&to=$today")->json();
        $this->assertEquals(120, $bars['target']);
        $this->assertSame('employee', $bars['field']);
        $this->assertCount(2, $bars['labels']);

        // A dashboard tag nobody has yet shows no one rather than everyone.
        $d->update(['employee_tag' => 'Nobody yet']);
        $this->assertSame([], $this->actingAs($admin)->getJson(route('dashboards.widget-data', [$d, $d->widgets->firstWhere('type', 'agent_table')]))->json('rows'));
    }

    public function test_callgear_guides_show_as_sticky_notes_only_to_callgear_staff(): void
    {
        $agent = User::create(['name' => 'Agent', 'email' => 'guide-agent@x.test', 'password' => 'password']);
        $agent->assignRole('callgear-agent');
        $other = User::create(['name' => 'Manager', 'email' => 'guide-mgr@x.test', 'password' => 'password']);
        $other->assignRole('employee');

        $this->actingAs($agent)->get(route('guides.show', 'call-flow'))->assertOk()->assertSee('vendor/mermaid/mermaid.min.js')
            ->assertSee('No-Show Recovery Engine')->assertSee('Validate via')->assertDontSee('Edit chart');

        // The chart is editable (steps and arrows) by guides.manage; agents only read it.
        $chart = \App\Http\Controllers\GuideController::chart('call-flow');
        $this->assertGreaterThan(30, count($chart['steps']));
        $chart['steps'][] = ['id' => 'step99', 'label' => "Offer <b>loyalty</b>\npoints", 'shape' => 'box', 'group' => 'book'];
        $chart['links'][] = ['from' => 'confirm', 'to' => 'step99', 'label' => 'Upsell', 'dotted' => true];
        $this->actingAs($agent)->put(route('guides.flow.save', 'call-flow'), ['chart' => json_encode($chart)])->assertForbidden();
        $this->actingAs($this->admin())->get(route('guides.show', 'call-flow'))->assertSee('Edit chart')->assertDontSee('Reset to original');
        $this->actingAs($this->admin())->put(route('guides.flow.save', 'call-flow'), ['chart' => json_encode($chart)])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('step99', \App\Http\Controllers\GuideController::chart('call-flow')['steps'][count($chart['steps']) - 1]['id']);
        $page = $this->actingAs($agent)->get(route('guides.show', 'call-flow'))->assertSee('Upsell');
        $this->assertStringNotContainsString('<b>loyalty', $page->getContent());
        $bad = $chart;
        $bad['links'][] = ['from' => 'confirm', 'to' => 'ghost', 'label' => '', 'dotted' => false];
        $this->actingAs($this->admin())->put(route('guides.flow.save', 'call-flow'), ['chart' => json_encode($bad)])->assertSessionHasErrors('links');
        $this->actingAs($this->admin())->get(route('guides.show', 'call-flow'))->assertSee('Reset to original');
        $this->actingAs($this->admin())->delete(route('guides.flow.reset', 'call-flow'))->assertRedirect();
        $this->assertCount(count($chart['steps']) - 1, \App\Http\Controllers\GuideController::chart('call-flow')['steps']);
        $this->assertFileExists(public_path('vendor/mermaid/mermaid.min.js'));
        $this->actingAs($other)->get(route('guides.show', 'call-flow'))->assertForbidden();
        foreach (array_keys(array_filter(\App\Http\Controllers\GuideController::GUIDES, fn ($g) => ! isset($g['flow']))) as $slug) {
            $this->assertFileExists(resource_path("guides/$slug.md"));
            $page = $this->actingAs($agent)->get(route('guides.show', $slug))->assertOk()->assertSee('sticky-note', false);
            $this->assertGreaterThan(2, substr_count($page->getContent(), 'class="sticky-note"'));
            $this->actingAs($other)->get(route('guides.show', $slug))->assertForbidden();
            $this->actingAs($this->admin())->get(route('guides.show', $slug))->assertOk();
        }
        $this->actingAs($agent)->get(route('guides.show', 'call-quality-audit'))->assertSee('Auto-fail')->assertSee('note-important', false)->assertSee('Call center');
        $this->actingAs($other)->get(route('profile'))->assertOk()->assertDontSee(route('guides.show', 'call-quality-audit'));

        // Temporary main-admin role under super admin: permissions come from its role, set only by super admin.
        $main = User::create(['name' => 'Main', 'email' => 'main-admin@x.test', 'password' => 'password']);
        $main->assignRole('main-admin');
        $this->assertFalse($main->seesEverything());
        $this->assertFalse($main->isSuperAdmin());
        $this->assertFalse($main->callgearOnly());
        $this->assertTrue($main->can('users.manage') && $main->can('guides.manage'));
        $this->assertFalse($main->can('roles.manage'));
        $this->actingAs($main)->get(route('guides.show', 'call-quality-audit'))->assertOk()->assertSee('Add note');
        $role = \Spatie\Permission\Models\Role::findByName('main-admin');
        $this->actingAs($this->admin())->put(route('admin.roles.update', $role), ['permissions' => ['dashboards.view']])->assertRedirect();
        $this->assertFalse($main->fresh()->can('guides.manage'));
        $this->actingAs($main)->get(route('guides.show', 'call-quality-audit'))->assertForbidden();
        $mgr = User::role('management')->first() ?? tap(User::create(['name' => 'M', 'email' => 'mgmt@x.test', 'password' => 'password']))->assignRole('management');
        $mgr->givePermissionTo('roles.manage');
        $this->actingAs($mgr)->put(route('admin.roles.update', $role), ['permissions' => ['users.manage']])->assertForbidden();
        $this->actingAs($agent)->get(route('guides.show', 'nope'))->assertNotFound();

        // Agents read; guides.manage (super admin) adds, edits, reorders and removes notes.
        $this->actingAs($agent)->get(route('guides.show', 'call-quality-audit'))->assertDontSee('Add note');
        $this->actingAs($agent)->post(route('guides.notes.store', 'call-quality-audit'), ['title' => 'Hack'])->assertForbidden();
        $admin = $this->admin();
        $this->actingAs($admin)->get(route('guides.show', 'call-quality-audit'))->assertSee('Add note');
        $this->actingAs($admin)->post(route('guides.notes.store', 'call-quality-audit'), ['title' => 'Eid timings', 'important' => "Branches close at 10pm\nNo walk-ins", 'body' => "- Book early\n- **Confirm** by SMS"])->assertRedirect();
        $note = \App\Models\GuideNote::where('title', 'Eid timings')->sole();
        $this->assertSame(\App\Models\GuideNote::where('guide', 'call-quality-audit')->max('position'), $note->position);
        $page = $this->actingAs($agent)->get(route('guides.show', 'call-quality-audit'))->assertSee('Eid timings')->assertSee('No walk-ins')->assertSee('<strong>Confirm</strong>', false);
        $this->actingAs($admin)->post(route('guides.notes.move', $note), ['direction' => 'up'])->assertRedirect();
        $this->assertSame($note->position - 1, $note->fresh()->position);
        $this->actingAs($admin)->put(route('guides.notes.update', $note), ['title' => 'Eid hours', 'important' => '', 'body' => '<script>x</script>'])->assertRedirect();
        $this->assertNull($note->fresh()->important);
        $this->actingAs($agent)->get(route('guides.show', 'call-quality-audit'))->assertSee('Eid hours')->assertDontSee('<script>x</script>', false);
        $this->actingAs($agent)->delete(route('guides.notes.destroy', $note))->assertForbidden();
        $this->actingAs($admin)->delete(route('guides.notes.destroy', $note))->assertRedirect();
        $this->assertModelMissing($note);
    }

    public function test_callgear_agents_only_see_callgear_data(): void
    {
        $agent = User::create(['name' => 'Agent', 'email' => 'agent@x.test', 'password' => 'password']);
        $agent->assignRole('callgear-agent');
        $this->assertTrue($agent->callgearOnly());
        $this->assertFalse($this->admin()->callgearOnly());

        $visible = Dashboard::visibleTo($agent)->pluck('name')->all();
        $this->assertSame([\Database\Seeders\CallgearPerformanceDashboardSeeder::NAME], $visible);
        $this->actingAs($agent)->get(route('home'))->assertRedirect();
        $html = $this->actingAs($agent)->get(route('calls.index'))->assertOk()->getContent();
        $this->assertStringNotContainsString(route('sales.index'), $html);
        $this->assertStringNotContainsString('Search guests', $html);
        foreach (['sales.index', 'guests.index', 'appointments.index', 'employees.index', 'activity.index'] as $r) {
            $this->actingAs($agent)->get(route($r))->assertForbidden();
        }

        $sales = Dashboard::where('name', \Database\Seeders\ManagementDashboardSeeder::NAME)->first()->load('widgets');
        $this->actingAs($agent)->get(route('dashboards.show', $sales))->assertRedirect(route('home'))->assertSessionHas('status');
        $cg = Dashboard::where('name', \Database\Seeders\CallgearPerformanceDashboardSeeder::NAME)->first()->load('widgets');
        $this->actingAs($agent)->get(route('dashboards.show', $cg))->assertOk();
        $this->actingAs($agent)->getJson(route('dashboards.widget-data', [$cg, $cg->widgets->first()]))->assertOk()->assertJsonMissing(['error']);

        // Zenoti booking value of the tagged team is allowed; guest data is not, even on this dashboard.
        $revenue = $cg->widgets->firstWhere('title', \Database\Seeders\CallgearPerformanceDashboardSeeder::REVENUE);
        $this->assertSame('appointments', $revenue->dataset);
        $this->actingAs($agent)->getJson(route('dashboards.widget-data', [$cg, $revenue]))->assertOk()->assertJsonMissing(['error']);
        $guests = $cg->widgets()->create(['title' => 'Guests', 'type' => 'kpi', 'dataset' => 'guests', 'aggregate' => 'count', 'date_range' => 'all_time', 'width' => 3, 'position' => 99]);
        $this->assertFalse($cg->fresh()->isCallgearOnly());
        $this->actingAs($agent)->getJson(route('dashboards.widget-data', [$cg, $guests]))->assertNotFound();
        $this->assertFalse($guests->allowedForCallgearOnly());
        $guests->delete();

        // Re-running the seeder on an existing dashboard adds the revenue widget once, after the scorecard.
        $revenue->delete();
        $this->seed(\Database\Seeders\CallgearPerformanceDashboardSeeder::class);
        $this->seed(\Database\Seeders\CallgearPerformanceDashboardSeeder::class);
        $w = $cg->widgets()->orderBy('position')->pluck('title')->all();
        $this->assertSame(1, count(array_keys($w, \Database\Seeders\CallgearPerformanceDashboardSeeder::REVENUE)));
        $this->assertSame(array_search('Agent scorecard (who is high or low)', $w) + 1, array_search(\Database\Seeders\CallgearPerformanceDashboardSeeder::REVENUE, $w));

        // Earlier versions (per-agent chart titled differently, single all-time card) become the per-employee Revenue chart.
        $cg->widgets()->where('title', 'Revenue')->delete();
        $cg->widgets()->create(['title' => 'Revenue', 'type' => 'stat', 'dataset' => 'sales', 'aggregate' => 'sum', 'metric_field' => 'net_amount', 'date_range' => 'all_time', 'width' => 12, 'position' => 7, 'options' => ['all_employees' => true]]);
        $this->seed(\Database\Seeders\CallgearPerformanceDashboardSeeder::class);
        $card = $cg->widgets()->where('title', 'Revenue')->sole();
        $this->assertSame(['hbar', 'employee', 'this_month', 'appointments', 'sum', 'price', 'booked_by'], [$card->type, $card->group_by, $card->date_range, $card->dataset, $card->aggregate, $card->metric_field, $card->option('credit')]);
        $this->assertNull($card->option('all_employees'));

        // Revenue = value of the appointments each agent booked this month, whoever serves them.
        $agent = \App\Models\Employee::first();
        $agent->tags()->attach(\App\Models\Tag::idsFor(['Callgear']));
        $booked = \App\Models\Appointment::where('employee_id', '!=', $agent->id)->limit(3)->pluck('id');
        \App\Models\Appointment::whereIn('id', $booked)->update(['booked_by_employee_id' => $agent->id, 'booked_at' => now(), 'price' => 120]);
        $data = $this->actingAs($this->admin())->getJson(route('dashboards.widget-data', [$cg, $card]))->json();
        $this->assertSame([$agent->full_name], $data['labels']);
        $expected = 120.0 * $booked->count();
        $this->assertEquals($expected, $data['values'][0]);
    }

    public function test_sales_lines_sharing_an_id_are_kept_apart(): void
    {
        $branch = \App\Models\Branch::first();
        $source = app(\App\Services\Zenoti\ZenotiSource::class);
        $row = ['id' => 'svc-eyebrows', 'item' => ['name' => 'Eyebrows Shaping'], 'final_sale_price' => 42, 'sale_date' => '2026-09-24T17:56:00'];
        $source->upsertSale($row + ['invoice_no' => 'S1'], $branch);
        $source->upsertSale($row + ['invoice_no' => 'S2'], $branch);
        $source->upsertSale($row + ['invoice_no' => 'S2'], $branch); // same line again
        $this->assertSame(2, \App\Models\Sale::whereIn('invoice_no', ['S1', 'S2'])->count());
        $this->assertSame('L-1', \App\Services\Zenoti\ZenotiMapper::saleKey(['id' => 'x', 'invoice_item_id' => 'L-1']));
    }

    public function test_integrations_page_shows_storage_check_and_run_now(): void
    {
        $admin = $this->admin();
        @unlink(\App\Console\Commands\RunSyncRequests::heartbeatFile());
        \App\Models\SyncRequest::create(['provider' => 'zenoti', 'requested_by' => $admin->id]);
        $this->actingAs($admin)->get(route('admin.integrations.index'))->assertOk()
            ->assertSee('Storage folder')->assertSee('writable')->assertSee('Run queued syncs now');
        \App\Models\SyncRequest::query()->update(['status' => 'done']);
        $this->actingAs($admin)->post(route('admin.integrations.run-now'))->assertSessionHas('status', 'Nothing is queued. Click "Sync now" first.');
    }

    public function test_appointment_price_is_read_from_nested_fields_and_fields_are_listed(): void
    {
        $m = \App\Services\Zenoti\ZenotiMapper::class;
        $this->assertSame(120.0, $m::appointment(['id' => 'a', 'price' => ['currency_id' => 148, 'sales' => 0], 'service' => ['name' => 'Hammam', 'price' => ['sales' => 120]]])['price']);
        $this->assertSame(0.0, $m::appointment(['id' => 'a', 'price' => ['currency_id' => 148]])['price']);
        $this->assertSame(95.5, $m::appointment(['id' => 'a', 'final_price' => '95.5'])['price']);

        $map = $m::fieldMap(['guest' => ['first_name' => 'Mona', 'mobile' => ['number' => '0509111133']], 'status' => 'Closed', 'price' => ['final' => 10], 'start_time' => '2026-09-30T21:05:00']);
        $this->assertSame('text(4)', $map['guest.first_name']);
        $this->assertSame('text(10)', $map['guest.mobile.number']);
        $this->assertSame('"Closed"', $map['status']);
        $this->assertSame('10', $map['price.final']);

        // Syncing a row saves its field names for the Integrations page; a price read for the first time is not logged as a change.
        $source = app(\App\Services\Zenoti\ZenotiSource::class);
        $branch = \App\Models\Branch::first();
        $row = ['id' => 'appt-1', 'status' => 1, 'start_time' => now()->addDay()->toIso8601String(), 'service' => ['name' => 'Hammam']];
        $source->upsertAppointment($row, $branch);
        $logs = \App\Models\ActivityLog::count();
        $source->upsertAppointment($row + ['price' => ['sales' => 150]], $branch);
        $this->assertEquals(150, \App\Models\Appointment::where('zenoti_id', 'appt-1')->value('price'));
        $this->assertSame($logs, \App\Models\ActivityLog::count());
        $this->actingAs($this->admin())->get(route('admin.integrations.index'))->assertOk()->assertSee('Fields Zenoti sends')->assertSee('service.name');
    }

    public function test_syncs_can_be_cancelled(): void
    {
        $admin = $this->admin();
        $queued = \App\Models\SyncRequest::create(['provider' => 'zenoti', 'requested_by' => $admin->id]);
        $this->actingAs($admin)->get(route('admin.integrations.index'))->assertSee('Cancel');
        $this->actingAs($admin)->post(route('admin.integrations.cancel', $queued))->assertSessionHas('status', 'Sync cancelled.');
        $this->assertSame('cancelled', $queued->fresh()->status);

        // A running sync is asked to stop and stops at its next step.
        $running = \App\Models\SyncRequest::create(['provider' => 'zenoti', 'requested_by' => $admin->id, 'status' => 'running', 'started_at' => now()]);
        \App\Models\SyncRun::create(['provider' => 'zenoti', 'entity' => 'sales', 'status' => 'running', 'started_at' => now()]);
        $this->actingAs($admin)->post(route('admin.integrations.cancel', $running));
        $this->assertSame('cancelling', $running->fresh()->status);
        \App\Services\Integrations\SyncRecorder::$stopCheck = fn () => \App\Models\SyncRequest::whereKey($running->id)->value('status') === 'cancelling';
        $ran = false;
        try {
            \App\Services\Integrations\SyncRecorder::run('zenoti', 'appointments', function () use (&$ran) { $ran = true; return []; });
            $this->fail('should have stopped');
        } catch (\App\Services\Integrations\SyncCancelled) {
        } finally {
            \App\Services\Integrations\SyncRecorder::$stopCheck = null;
        }
        $this->assertFalse($ran);
    }

    public function test_sales_are_linked_to_employees_by_code_or_name(): void
    {
        $e = \App\Models\Employee::first();
        $e->update(['first_name' => 'Maria', 'last_name' => 'Lopez  Garcia', 'raw' => ['code' => 'GF-017']]);
        $m = new \App\Services\Zenoti\EmployeeMatcher;
        $this->assertSame($e->id, $m->match(\App\Services\Zenoti\ZenotiMapper::sale(['id' => 'x1', 'employee' => ['code' => 'gf-017', 'name' => 'Someone Else']])));
        $m = new \App\Services\Zenoti\EmployeeMatcher;
        $this->assertSame($e->id, $m->match(\App\Services\Zenoti\ZenotiMapper::sale(['id' => 'x2', 'sold_by' => ['first_name' => 'maria', 'last_name' => 'Lopez Garcia']])));
        $this->assertSame($e->id, $m->match(\App\Services\Zenoti\ZenotiMapper::sale(['id' => 'x3', 'employee_name' => 'MARIA LOPEZ GARCIA'])));
        $this->assertNull($m->match(\App\Services\Zenoti\ZenotiMapper::sale(['id' => 'x4', 'employee_name' => 'Nobody Here'])));

        // Existing unlinked sales are relinked from their stored Zenoti row.
        $sale = \App\Models\Sale::first();
        $sale->update(['employee_id' => null, 'raw' => ['id' => 'x5', 'therapist_name' => 'Maria Lopez Garcia']]);
        (require database_path('migrations/2026_09_26_000700_link_sales_to_employees.php'))->up();
        $this->assertSame($e->id, $sale->fresh()->employee_id);
    }

    public function test_clicking_a_chart_lists_the_records_behind_it(): void
    {
        $admin = $this->admin();
        $d = Dashboard::where('name', \Database\Seeders\ManagementDashboardSeeder::NAME)->first()->load('widgets');
        $w = $d->widgets->firstWhere('title', 'Branch comparison: sales');
        $data = $this->actingAs($admin)->getJson(route('dashboards.widget-data', [$d, $w]).'?from=2000-01-01')->json();
        $key = $data['keys'][0];
        $res = $this->actingAs($admin)->getJson(route('dashboards.widget-records', [$d, $w]).'?from=2000-01-01&key='.$key)->assertOk()->json();
        $this->assertEquals(Sale::where('branch_id', $key)->count(), $res['total']);
        $this->assertEqualsWithDelta($data['values'][0], collect($res['rows'])->sum(fn ($r) => $r[8]), 1);

        $week = $d->widgets->firstWhere('title', 'Day of week: sales');
        $wd = $this->actingAs($admin)->getJson(route('dashboards.widget-data', [$d, $week]).'?from=2000-01-01')->json();
        $res = $this->actingAs($admin)->getJson(route('dashboards.widget-records', [$d, $week]).'?from=2000-01-01&key='.$wd['keys'][0])->json();
        $this->assertEqualsWithDelta($wd['values'][0], collect($res['rows'])->sum(fn ($r) => $r[8]), 1);

        $table = $d->widgets->firstWhere('type', 'branch_table');
        $this->actingAs($admin)->getJson(route('dashboards.widget-records', [$d, $table]).'?from=2000-01-01&field=branch&key='.$key)
            ->assertOk()->assertJsonPath('total', Sale::where('branch_id', $key)->count());
        $this->actingAs($admin)->get(route('dashboards.widget-records', [$d, $w]).'?from=2000-01-01&format=csv')->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $appts = Dashboard::where('name', 'Admin dashboard')->first()->widgets()->where('title', 'Appointments by status')->first();
        $this->actingAs($admin)->getJson(route('dashboards.widget-records', [$appts->dashboard, $appts]).'?from=2000-01-01&key=Cancelled')
            ->assertOk()->assertJsonPath('total', \App\Models\Appointment::where('status', 'Cancelled')->count());
    }

    public function test_sale_date_is_found_under_other_field_names(): void
    {
        $m = \App\Services\Zenoti\ZenotiMapper::class;
        $this->assertSame('2026-09-20', $m::sale(['invoice' => ['invoice_date' => '2026-09-20T10:00:00']])['sold_at']->toDateString());
        $this->assertSame('2026-09-21', $m::sale(['item' => ['name' => 'Cut'], 'SaleDateTime' => '2026-09-21 09:00'])['sold_at']->toDateString());
        $this->assertNull($m::sale(['item' => ['name' => 'Cut']])['sold_at']);
    }
}
