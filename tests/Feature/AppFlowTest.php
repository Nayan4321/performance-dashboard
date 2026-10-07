<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Dashboard;
use App\Models\Employee;
use App\Models\Module;
use App\Models\Organization;
use App\Models\Product;
use App\Models\Sale;
use App\Models\StockOrder;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AppFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->seed(DemoDataSeeder::class);
    }

    private function admin(): User
    {
        return User::role('super-admin')->first();
    }

    public function test_guests_are_sent_to_login_and_can_log_in(): void
    {
        $this->get('/')->assertRedirect('/login');
        $this->post('/login', ['email' => 'manager@demo.test', 'password' => 'password'])->assertRedirect(route('home'));
        $this->assertAuthenticated();
    }

    public function test_disabled_users_cannot_log_in(): void
    {
        User::where('email', 'manager@demo.test')->update(['is_active' => false]);
        $this->post('/login', ['email' => 'manager@demo.test', 'password' => 'password']);
        $this->assertGuest();
    }

    public function test_every_admin_page_renders_for_super_admin(): void
    {
        $admin = $this->admin();
        $dash = Dashboard::first();
        $order = $this->makeOrder();
        $pages = [
            '/', route('dashboards.show', $dash), route('dashboards.create'), route('dashboards.edit', $dash),
            route('employees.index'), route('leads.index'), route('leads.create'),
            route('guests.index'), route('guests.index', ['period' => 'all', 'q' => 'a']), route('appointments.index', ['period' => 'last_90_days', 'status' => 'Serviced']),
            route('sales.index', ['from' => now()->subYear()->toDateString(), 'category' => 'Product']), route('invoices.index', ['period' => 'last_90_days', 'q' => 'x']),
            route('admin.users.index'), route('admin.users.create'), route('admin.users.edit', User::where('email', 'stock@demo.test')->first()),
            route('admin.roles.index'), route('admin.organizations.index'), route('admin.modules.index'), route('admin.integrations.index'),
            route('inventory.orders.index'), route('inventory.orders.create'), route('inventory.orders.show', $order),
            route('inventory.products.index'), route('inventory.products.create'), route('inventory.stock'), route('profile'),
        ];
        foreach ($pages as $url) {
            $res = $this->actingAs($admin)->get($url);
            $this->assertContains($res->status(), [200, 302], "$url returned {$res->status()}");
        }
    }

    public function test_appointments_page_shows_who_created_each_appointment(): void
    {
        $maker = \App\Models\Employee::create(['source' => 'zenoti', 'first_name' => 'Booker', 'last_name' => 'Person']);
        \App\Models\Appointment::create(['branch_id' => Branch::first()->id, 'zenoti_id' => 'A-1', 'service_name' => 'Facial', 'status' => 'Serviced',
            'start_time' => now(), 'booked_at' => now()->subDay(), 'booked_by_employee_id' => $maker->id, 'price' => 100]);
        $this->actingAs($this->admin())->get(route('appointments.index'))->assertOk()->assertSee('Created by')->assertSee('Booker Person');
    }

    public function test_invoices_page_groups_synced_sales_lines_per_invoice(): void
    {
        $branch = Branch::first();
        $emp = \App\Models\Employee::create(['source' => 'zenoti', 'first_name' => 'Inv', 'last_name' => 'Maker']);
        foreach ([['L1', 100, 105], ['L2', 50, 52.5]] as [$id, $exc, $inc]) {
            \App\Models\Sale::create(['branch_id' => $branch->id, 'zenoti_id' => $id, 'invoice_no' => 'INV-77', 'item_name' => "Item $id", 'item_type' => 'Service',
                'net_amount' => $exc, 'sold_at' => now(), 'created_by_employee_id' => $emp->id,
                'raw' => ['sales_inc_tax' => $inc, 'payment_type' => 'Cash', 'invoice_status' => 'Closed']]);
        }
        $this->actingAs($this->admin())->get(route('invoices.index'))->assertOk()
            ->assertSee('INV-77')->assertSee('Inv Maker')->assertSee('157.50')->assertSee('150.00');
    }

    public function test_callgear_admin_manages_only_callgear_agents_and_their_allowed_permissions(): void
    {
        $org = \App\Models\Organization::first();
        $lead = User::create(['name' => 'CG Lead', 'email' => 'cglead@demo.test', 'password' => 'password', 'organization_id' => $org->id, 'is_active' => true]);
        $lead->assignRole('callgear-admin');
        $other = User::where('email', 'stock@demo.test')->first();

        $this->actingAs($lead)->get(route('admin.users.index'))->assertOk()->assertDontSee('stock@demo.test');
        $this->actingAs($lead)->get(route('admin.users.edit', $other))->assertForbidden();
        $this->actingAs($lead)->get(route('admin.users.create'))->assertOk()->assertSee('complaints.manage')->assertDontSee('roles.manage');

        $this->actingAs($lead)->post(route('admin.users.store'), [
            'name' => 'Agent One', 'email' => 'agent1@demo.test', 'password' => 'password123', 'is_active' => 1,
            'roles' => ['callgear-agent'], 'permissions' => ['complaints.manage', 'users.manage'],
        ])->assertRedirect(route('admin.users.index'));
        $agent = User::where('email', 'agent1@demo.test')->first();
        $this->assertTrue($agent->hasRole('callgear-agent'));
        $this->assertSame($org->id, $agent->organization_id);
        $this->assertTrue($agent->hasDirectPermission('complaints.manage'));
        $this->assertFalse($agent->can('users.manage'));

        // She cannot hand out other roles, and her agents appear in her list.
        $this->actingAs($lead)->post(route('admin.users.store'), ['name' => 'X', 'email' => 'x@demo.test', 'password' => 'password123', 'roles' => ['management']])->assertSessionHasErrors('roles.0');
        $this->actingAs($lead)->get(route('admin.users.index'))->assertSee('agent1@demo.test');
        $this->actingAs($lead)->put(route('admin.users.update', $agent), ['name' => 'Agent One', 'email' => 'agent1@demo.test', 'is_active' => 0, 'roles' => []])->assertRedirect();
        $agent->refresh();
        $this->assertFalse((bool) $agent->is_active);
        $this->assertTrue($agent->hasRole('callgear-agent'));
        $this->assertFalse($agent->hasDirectPermission('complaints.manage'));
    }

    public function test_widget_data_supports_all_types_and_filters(): void
    {
        $admin = $this->admin();
        $dash = Dashboard::first()->load('widgets');
        $branch = Branch::where('name', 'Andheri')->first();
        foreach ($dash->widgets as $w) {
            $this->actingAs($admin)->getJson(route('dashboards.widget-data', [$dash, $w]).'?from='.now()->subDays(90)->toDateString())
                ->assertOk()->assertJsonMissing(['error']);
        }

        $revenue = $dash->widgets->firstWhere('title', 'Sales');
        $all = $this->actingAs($admin)->getJson(route('dashboards.widget-data', [$dash, $revenue]).'?from=2000-01-01')->json('value');
        $one = $this->actingAs($admin)->getJson(route('dashboards.widget-data', [$dash, $revenue])."?from=2000-01-01&branch_id={$branch->id}")->json('value');
        $this->assertEqualsWithDelta(Sale::sum('net_amount'), $all, 1);
        $this->assertEqualsWithDelta(Sale::where('branch_id', $branch->id)->sum('net_amount'), $one, 1);
        $this->assertLessThan($all, $one);

        $liab = $dash->widgets->firstWhere('title', 'Liabilities sold');
        $v = $this->actingAs($admin)->getJson(route('dashboards.widget-data', [$dash, $liab]).'?from=2000-01-01')->json('value');
        $this->assertEqualsWithDelta(Sale::whereIn('category', Sale::LIABILITIES)->sum('net_amount'), $v, 1);

        $byStatus = $dash->widgets->firstWhere('title', 'Appointments by status');
        $labels = $this->actingAs($admin)->getJson(route('dashboards.widget-data', [$dash, $byStatus]).'?from=2000-01-01')->json('labels');
        $this->assertSame(\App\Models\Appointment::STATUSES, $labels, 'statuses in Zenoti order');
    }

    public function test_branch_manager_only_sees_own_branch_numbers(): void
    {
        $manager = User::where('email', 'andheri@demo.test')->first();
        $dash = Dashboard::first()->load('widgets');
        $revenue = $dash->widgets->firstWhere('title', 'Sales');
        $value = $this->actingAs($manager)->getJson(route('dashboards.widget-data', [$dash, $revenue]).'?from=2000-01-01')->json('value');
        $this->assertEqualsWithDelta(Sale::where('branch_id', $manager->branch_id)->sum('net_amount'), $value, 1);
    }

    public function test_employee_only_sees_own_numbers(): void
    {
        $emp = Employee::first();
        $user = User::create(['name' => 'E', 'email' => 'e@x.test', 'password' => 'password', 'branch_id' => $emp->branch_id, 'organization_id' => $emp->organization_id]);
        $user->assignRole('employee');
        $emp->update(['user_id' => $user->id]);
        $dash = Dashboard::first()->load('widgets');
        $revenue = $dash->widgets->firstWhere('title', 'Sales');
        $value = $this->actingAs($user)->getJson(route('dashboards.widget-data', [$dash, $revenue]).'?from=2000-01-01')->json('value');
        $this->assertEqualsWithDelta(Sale::where('employee_id', $emp->id)->sum('net_amount'), $value, 1);
    }

    public function test_module_visibility_follows_organization_and_overrides(): void
    {
        $org = Organization::create(['name' => 'Other org']);
        $user = User::create(['name' => 'X', 'email' => 'x@x.test', 'password' => 'password', 'organization_id' => $org->id]);
        $user->assignRole('branch-manager');
        $this->assertFalse($user->canAccessModule(Module::INVENTORY));
        $this->actingAs($user)->get(route('inventory.orders.index'))->assertForbidden();

        $org->modules()->attach(Module::where('key', Module::INVENTORY)->value('id'));
        $this->assertTrue($user->fresh()->canAccessModule(Module::INVENTORY));

        $user->moduleOverrides()->attach(Module::where('key', Module::INVENTORY)->value('id'), ['allowed' => false]);
        $this->assertFalse($user->fresh()->canAccessModule(Module::INVENTORY));

        $this->assertTrue($this->admin()->canAccessModule(Module::INVENTORY));
    }

    public function test_admin_can_create_user_with_roles_and_module_override(): void
    {
        $org = Organization::first();
        $inv = Module::where('key', Module::INVENTORY)->first();
        $this->actingAs($this->admin())->post(route('admin.users.store'), [
            'name' => 'New Person', 'email' => 'new@x.test', 'password' => 'secret123', 'organization_id' => $org->id,
            'is_active' => 1, 'roles' => ['branch-stock-admin'], 'permissions' => ['leads.manage'], 'modules' => [$inv->id => 'deny'],
        ])->assertRedirect(route('admin.users.index'));
        $u = User::where('email', 'new@x.test')->first();
        $this->assertTrue($u->hasRole('branch-stock-admin'));
        $this->assertTrue($u->hasDirectPermission('leads.manage'));
        $this->assertFalse($u->canAccessModule(Module::INVENTORY));
    }

    public function test_org_admin_cannot_grant_super_admin(): void
    {
        $org = Organization::first();
        $orgAdmin = User::create(['name' => 'OA', 'email' => 'oa@x.test', 'password' => 'password', 'organization_id' => $org->id]);
        $orgAdmin->assignRole('org-admin');
        $this->actingAs($orgAdmin)->post(route('admin.users.store'), [
            'name' => 'Sneaky', 'email' => 'sneaky@x.test', 'password' => 'secret123', 'roles' => ['super-admin'],
        ])->assertSessionHasErrors('roles.0');
        $this->actingAs($orgAdmin)->get(route('admin.users.edit', $this->admin()))->assertForbidden();
    }

    private function makeOrder(): StockOrder
    {
        $requester = User::where('email', 'andheri@demo.test')->first();
        $p = Product::first();

        return app(\App\Services\Inventory\StockOrderService::class)->create(
            $requester, $requester->branch_id, Branch::where('is_warehouse', true)->value('id'), [['product_id' => $p->id, 'quantity' => 2]], null
        );
    }

    public function test_inventory_order_flow_with_pricing_approval_stock_and_invoice(): void
    {
        $requester = User::where('email', 'andheri@demo.test')->first();
        $stockManager = User::where('email', 'stock@demo.test')->first();
        $warehouse = Branch::where('is_warehouse', true)->first();
        [$p1, $p2] = Product::orderBy('id')->take(2)->get();

        $this->actingAs($requester)->post(route('inventory.orders.store'), [
            'requesting_branch_id' => $requester->branch_id, 'supplying_branch_id' => $warehouse->id,
            'items' => [['product_id' => $p1->id, 'quantity' => 3], ['product_id' => $p2->id, 'quantity' => 2], ['product_id' => '', 'quantity' => 1]],
        ])->assertRedirect();
        $order = StockOrder::latest('id')->first();
        $expected = collect([[$p1, 3], [$p2, 2]])->sum(fn ($x) => round($x[1] * $x[0]->unit_price * (1 + $x[0]->tax_rate / 100), 2));
        $this->assertEquals(StockOrder::SUBMITTED, $order->status);
        $this->assertEqualsWithDelta($expected, (float) $order->total, 0.02);

        // requester can't approve their own order
        $this->actingAs($requester)->post(route('inventory.orders.status', $order), ['status' => 'approved'])->assertForbidden();

        foreach (['approval_in_process', 'approved', 'payment_requested', 'payment_completed', 'completed'] as $status) {
            $this->actingAs($stockManager)->post(route('inventory.orders.status', $order), ['status' => $status])->assertSessionHasNoErrors();
            $this->assertEquals($status, $order->fresh()->status);
            if ($status === 'approved') {
                $this->actingAs($stockManager)->post(route('inventory.invoices.generate', $order))->assertRedirect();
            }
        }

        // illegal transition
        $this->actingAs($stockManager)->post(route('inventory.orders.status', $order), ['status' => 'approved'])->assertSessionHasErrors('status');

        $this->assertEquals(497, (float) \App\Models\BranchStock::where(['branch_id' => $warehouse->id, 'product_id' => $p1->id])->value('quantity'));
        $this->assertEquals(3, (float) \App\Models\BranchStock::where(['branch_id' => $requester->branch_id, 'product_id' => $p1->id])->value('quantity'));

        $invoice = $order->fresh()->invoice;
        $this->assertNotNull($invoice);
        $this->actingAs($requester)->get(route('inventory.invoices.show', $invoice))->assertOk()->assertSee($invoice->invoice_number);
        $this->actingAs($requester)->get(route('inventory.invoices.pdf', $invoice))->assertOk()->assertHeader('content-type', 'application/pdf');
    }

    public function test_reject_requires_reason_and_other_branches_cannot_see_order(): void
    {
        $order = $this->makeOrder();
        $stockManager = User::where('email', 'stock@demo.test')->first();
        $this->actingAs($stockManager)->post(route('inventory.orders.status', $order), ['status' => 'rejected'])->assertSessionHasErrors('note');
        $this->actingAs($stockManager)->post(route('inventory.orders.status', $order), ['status' => 'rejected', 'note' => 'Out of budget'])->assertSessionHasNoErrors();

        $other = User::create(['name' => 'B', 'email' => 'b@x.test', 'password' => 'password', 'organization_id' => Organization::first()->id, 'branch_id' => Branch::where('name', 'Powai')->value('id')]);
        $other->assignRole('branch-stock-admin');
        $this->actingAs($other)->get(route('inventory.orders.show', $order))->assertForbidden();
    }

    public function test_dashboard_builder_adds_widgets(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post(route('dashboards.store'), ['name' => 'Calls'])->assertRedirect();
        $d = Dashboard::where('name', 'Calls')->first();
        $this->actingAs($admin)->post(route('widgets.store', $d), [
            'title' => 'Missed calls by branch', 'type' => 'bar', 'dataset' => 'calls', 'aggregate' => 'count', 'group_by' => 'branch',
            'date_range' => 'all_time', 'width' => 6, 'filters' => [['field' => 'status', 'operator' => '=', 'value' => 'missed']],
        ])->assertSessionHasNoErrors();
        $w = $d->widgets()->first();
        $data = $this->actingAs($admin)->getJson(route('dashboards.widget-data', [$d, $w]))->assertOk()->json();
        $this->assertEquals(\App\Models\Call::where('status', 'missed')->count(), $data['total']);

        $this->actingAs($admin)->post(route('widgets.store', $d), [
            'title' => 'Bad', 'type' => 'bar', 'dataset' => 'calls', 'aggregate' => 'count', 'date_range' => 'all_time', 'width' => 6,
        ])->assertSessionHasErrors('group_by');
    }

    public function test_dashboard_role_restriction(): void
    {
        $d = Dashboard::create(['name' => 'Mgmt only', 'visible_to_roles' => ['management']]);
        $manager = User::where('email', 'andheri@demo.test')->first();
        $this->actingAs($manager)->get(route('dashboards.show', $d))->assertRedirect(route('home'));
        $this->actingAs(User::where('email', 'manager@demo.test')->first())->get(route('dashboards.show', $d))->assertOk();
    }

    public function test_records_pages_list_synced_data_scoped_to_branch(): void
    {
        $branch = Branch::first();
        $other = Branch::where('id', '!=', $branch->id)->first();
        $guest = \App\Models\Guest::create(['branch_id' => $branch->id, 'first_name' => 'Zara', 'last_name' => 'Visible', 'phone' => '0501112222', 'registered_at' => now()]);
        \App\Models\Guest::create(['branch_id' => $other->id, 'first_name' => 'Hidden', 'last_name' => 'Elsewhere', 'registered_at' => now()]);
        \App\Models\Appointment::create(['branch_id' => $branch->id, 'guest_id' => $guest->id, 'service_name' => 'Keratin treatment', 'status' => 'Serviced', 'start_time' => now()]);

        $this->actingAs($this->admin())->get(route('guests.index'))->assertOk()->assertSee('Zara')->assertSee('Hidden');
        $this->actingAs($this->admin())->get(route('appointments.index', ['q' => 'Zara']))->assertOk()->assertSee('Keratin treatment');

        $manager = User::factory()->create(['branch_id' => $branch->id, 'organization_id' => $branch->organization_id]);
        $manager->assignRole('branch-manager');
        $this->actingAs($manager)->get(route('guests.index'))->assertOk()->assertSee('Zara')->assertDontSee('Hidden');
    }
}
