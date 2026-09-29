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
}
