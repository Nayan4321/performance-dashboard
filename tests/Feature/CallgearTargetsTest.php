<?php

namespace Tests\Feature;

use App\Models\Call;
use App\Models\Dashboard;
use App\Models\Employee;
use App\Models\Sale;
use App\Models\Tag;
use App\Models\User;
use Database\Seeders\CallgearPerformanceDashboardSeeder;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CallgearTargetsTest extends TestCase
{
    use RefreshDatabase;

    public function test_revenue_target_commission_and_call_tags_for_callgear_agents(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(CallgearPerformanceDashboardSeeder::class);
        $callgear = Tag::idsFor(['Callgear'])[0];
        $lead = Tag::idsFor(['Team lead'])[0];
        foreach (range(1, 5) as $i) {
            Employee::create(['source' => 'zenoti', 'first_name' => "Agent$i", 'last_name' => 'X', 'is_active' => true])->tags()->attach($callgear);
        }
        $ladies = Employee::where('first_name', 'like', 'Agent%')->orderBy('id')->get();
        $kawther = Employee::create(['source' => 'zenoti', 'first_name' => 'Kawther', 'last_name' => 'A', 'is_active' => true]);
        $kawther->tags()->attach([$callgear, $lead]);
        $a1 = $ladies[0];
        $line = fn (array $x) => Sale::create($x + ['zenoti_id' => uniqid(), 'sold_at' => now(), 'item_type' => 'Service', 'status' => 'Closed', 'net_amount' => 100000]);
        $line(['employee_id' => $a1->id, 'raw' => ['payment_type' => 'Card']]);                 // counts
        $line(['created_by_employee_id' => $a1->id, 'raw' => ['payment_type' => 'Cash']]);     // counts (entered by her)
        $line(['employee_id' => $a1->id, 'item_type' => 'Product']);                           // not a service
        $line(['employee_id' => $a1->id, 'status' => 'Open']);                                 // not closed
        $line(['employee_id' => $a1->id, 'raw' => ['payment_type' => 'Gift Cards']]);          // excluded payment
        $line(['employee_id' => $ladies[1]->id, 'raw' => ['payment_type' => 'Custom-Financial'], 'net_amount' => 650000]);
        $line(['employee_id' => $kawther->id, 'net_amount' => 50000]);                          // lead: shown, not in split

        $d = Dashboard::where('name', CallgearPerformanceDashboardSeeder::NAME)->firstOrFail();
        $admin = User::where('email', 'admin@your-domain.com')->first() ?? User::first();
        $get = fn (string $title) => $this->actingAs($admin)->getJson(route('dashboards.widget-data', [$d, $d->widgets()->where('title', $title)->firstOrFail()]))->assertOk()->json();

        $rev = $get('Revenue');
        $this->assertSame('agent_bars', $rev['type']);
        $this->assertEquals(160000, $rev['target_each']); // 800k / 5, team lead not counted
        $i = array_search($a1->id, $rev['keys'], true);
        $this->assertEquals(200000, $rev['values'][$i]);
        $this->assertEquals(125, $rev['percents'][$i]);
        $this->assertStringContainsString('(team lead)', implode(',', $rev['labels']));
        $this->assertEquals(850000, $rev['counted']);

        $com = $get('Commission');
        $this->assertEquals(0.6, $com['rate']); // the whole group (lead included) made 900k
        $row = collect($com['rows'])->firstWhere('key', $ladies[1]->id);
        $this->assertEquals(3900, $row['commission']);
        $this->assertNull(collect($com['rows'])->firstWhere('key', $kawther->id)['commission']);

        $group = $get('Group target and commission');
        $this->assertSame('group_target', $group['type']);
        $this->assertEquals(900000, $group['total']);
        $this->assertEquals(0.6, $group['rate']);
        $this->assertEquals(1000000, $group['next']);
        $this->assertEquals(0.7, $group['next_rate']);
        $this->assertEquals(100000, $group['to_next']);
        $this->assertEquals(900000, max(array_filter($group['values'], fn ($v) => $v !== null)));

        Call::create(['external_id' => 't1', 'employee_id' => $a1->id, 'started_at' => now(), 'tags' => 'Outgoing new sale, Processed']);
        Call::create(['external_id' => 't2', 'employee_id' => $a1->id, 'started_at' => now(), 'tags' => 'Outgoing new sale']);
        Call::create(['external_id' => 't3', 'employee_id' => $ladies[2]->id, 'started_at' => now(), 'tags' => 'Incoming booking call']);
        $tags = $get('Call results by tag');
        $series = collect($tags['series'])->keyBy('name');
        $this->assertEquals(2, array_sum($series['Outgoing new sale']['data']));
        $this->assertEquals(1, array_sum($series['Incoming booking call']['data']));

        // Tiers are editable on the widget form.
        $w = $d->widgets()->where('title', 'Commission')->first();
        $this->actingAs($admin)->put(route('widgets.update', [$d, $w]), ['title' => 'Commission', 'type' => 'agent_commission', 'dataset' => 'sales', 'aggregate' => 'sum',
            'metric_field' => 'net_amount', 'width' => 12, 'date_range' => 'this_month', 'options' => ['team_target' => 800000, 'lead_tag' => 'Team lead', 'tiers_text' => "700000 = 0.4\n900,000 = 0.6"]])->assertRedirect();
        $this->assertEquals([[700000, 0.4], [900000, 0.6]], $w->fresh()->option('tiers'));
        $this->assertEquals(0.6, $get('Commission')['rate']);

        $this->actingAs($admin)->get(route('dashboards.show', $d))->assertOk();
    }
}
