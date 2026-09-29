<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Call;
use App\Models\Complaint;
use App\Models\Employee;
use App\Models\Guest;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ComplaintTest extends TestCase
{
    use RefreshDatabase;

    public function test_agent_logs_a_complaint_from_a_call_and_a_manager_resolves_it(): void
    {
        $this->seed(DatabaseSeeder::class);
        $employee = Employee::create(['source' => 'zenoti', 'first_name' => 'Nejma', 'last_name' => 'D']);
        $agent = User::create(['name' => 'Nejma', 'email' => 'nejma@demo.test', 'password' => bcrypt('x'), 'is_active' => true]);
        $agent->assignRole('callgear-agent');
        $employee->forceFill(['user_id' => $agent->id])->save();
        $guest = Guest::create(['source' => 'zenoti', 'zenoti_id' => 'g-1', 'first_name' => 'Amna', 'last_name' => 'K', 'phone' => '+971 50 123 4567']);
        Appointment::create(['guest_id' => $guest->id, 'status' => 'Closed', 'start_time' => now()->subDays(5), 'price' => 200, 'service_name' => 'Hair']);
        $call = Call::create(['external_id' => 'c1', 'employee_id' => $employee->id, 'caller' => '0501234567', 'started_at' => now()->subHour(), 'direction' => 'in']);

        $this->actingAs($agent)->get(route('calls.index'))->assertOk()->assertSee(route('complaints.create', ['call' => $call->id]), false);
        $this->actingAs($agent)->get(route('complaints.create', ['call' => $call->id]))->assertOk()->assertSee('0501234567');
        $this->actingAs($agent)->post(route('complaints.store'), ['call_id' => $call->id, 'message' => 'Stylist was late, colour uneven', 'category' => 'C'])
            ->assertRedirect(route('complaints.index'));

        $c = Complaint::firstOrFail();
        $this->assertSame($employee->id, $c->employee_id);
        $this->assertSame($guest->id, $c->guest_id); // found by phone
        $this->assertSame('C-00001', $c->number());
        $this->assertSame(1, $c->clientSummary()['visits']);

        $this->actingAs($agent)->get(route('complaints.index'))->assertOk()->assertSee('C-00001')->assertSee('Amna K')->assertSee('colour uneven')->assertDontSee('What was done for the client');
        $this->actingAs($agent)->put(route('complaints.update', $c), ['status' => 'resolved'])->assertForbidden();

        $admin = User::where('email', 'admin@your-domain.com')->first() ?? User::first();
        $this->actingAs($admin)->put(route('complaints.update', $c), ['status' => 'resolved', 'resolution' => 'Free touch-up booked'])->assertRedirect();
        $this->assertNotNull($c->fresh()->resolved_at);
        $this->actingAs($admin)->get(route('complaints.index', ['status' => 'resolved']))->assertOk()->assertSee('Free touch-up booked');

        // Plain employees don't see complaints.
        $plain = User::create(['name' => 'P', 'email' => 'p@demo.test', 'password' => bcrypt('x'), 'is_active' => true]);
        $plain->assignRole('employee');
        $this->actingAs($plain)->get(route('complaints.index'))->assertForbidden();
    }

    public function test_calls_tagged_complaint_in_callgear_become_complaints(): void
    {
        $this->seed(DatabaseSeeder::class);
        config(['callgear.enabled' => true, 'callgear.access_token' => 'cg', 'callgear.base_url' => 'https://cg.test/v2.0']);
        $agent = Employee::create(['source' => 'zenoti', 'first_name' => 'Kawther', 'last_name' => 'A', 'callgear_id' => '55']);
        \Illuminate\Support\Facades\Http::fake(['cg.test/*' => function ($request) {
            $rows = $request['params']['offset'] > 0 ? [] : [
                ['id' => 901, 'start_time' => now()->subHour()->toDateTimeString(), 'contact_phone_number' => '971501234567', 'employees' => [['employee_id' => 55]],
                    'tags' => [['tag_id' => 1, 'tag_name' => 'Debatable call'], ['tag_id' => 2, 'tag_name' => 'Complaint', 'tag_user_login' => 'kawther']]],
                ['id' => 902, 'start_time' => now()->subHour()->toDateTimeString(), 'tags' => [['tag_name' => 'Booked']]],
            ];

            return \Illuminate\Support\Facades\Http::response(['result' => ['data' => in_array('tags', $request['params']['fields'] ?? [], true) ? $rows : []]]);
        }]);

        $this->artisan('integrations:sync callgear --entity=calls --days=1')->assertSuccessful();
        $this->artisan('integrations:sync callgear --entity=calls --days=1')->assertSuccessful(); // no duplicate

        $this->assertSame(1, Complaint::count());
        $c = Complaint::first();
        $this->assertSame('callgear', $c->source);
        $this->assertSame($agent->id, $c->employee_id);
        $this->assertStringContainsString('by kawther', $c->message);

        $admin = User::where('email', 'admin@your-domain.com')->first() ?? User::first();
        $this->actingAs($admin)->put(route('complaints.note', $c), ['message' => 'Client unhappy with nails'])->assertRedirect();
        $this->assertSame('Client unhappy with nails', $c->fresh()->message);
    }

    public function test_calls_still_sync_when_callgear_refuses_the_tags_field(): void
    {
        $this->seed(DatabaseSeeder::class);
        config(['callgear.enabled' => true, 'callgear.access_token' => 'cg', 'callgear.base_url' => 'https://cg.test/v2.0']);
        \Illuminate\Support\Facades\Http::fake(['cg.test/*' => fn ($request) => in_array('tags', $request['params']['fields'] ?? [], true)
            ? \Illuminate\Support\Facades\Http::response(['error' => ['code' => -32602, 'message' => 'Invalid params']])
            : \Illuminate\Support\Facades\Http::response(['result' => ['data' => $request['params']['offset'] > 0 ? [] : [['id' => 903, 'start_time' => now()->toDateTimeString()]]]])]);

        $this->artisan('integrations:sync callgear --entity=calls --days=1')->assertSuccessful();
        $this->assertTrue(Call::where('external_id', '903')->exists());
    }
}
