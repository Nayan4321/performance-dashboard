<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Sale;
use App\Models\Tag;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class RevenueCompareTest extends TestCase
{
    use RefreshDatabase;

    public function test_export_is_compared_per_agent_and_invoice(): void
    {
        $this->seed(DatabaseSeeder::class);
        $nejma = Employee::create(['source' => 'zenoti', 'first_name' => 'Nejma', 'last_name' => 'Dahchache', 'is_active' => true]);
        $nejma->tags()->attach(Tag::idsFor([\App\Models\Tag::AGENTS]));
        $sale = fn ($inv, $amount, array $raw = []) => Sale::create(['zenoti_id' => uniqid(), 'invoice_no' => $inv, 'created_by_employee_id' => $nejma->id, 'item_type' => 'Service',
            'status' => 'Closed', 'net_amount' => $amount / 1.05, 'sold_at' => '2026-10-02', 'raw' => $raw + ['payment_type' => 'Card', 'sales_inc_tax' => $amount, 'invoice_closed_date' => '2026-10-02T12:00:00']]);
        $sale('S1', 105);
        $sale('S2', 50, ['payment_type' => 'Gift Card(1)']);   // we skip it, they count it -> shows why

        $csv = "\xEF\xBB\xBF\"Invoice created by\",\"Item Type\",\"Invoice No\",\"Item Name\",\"Sales(Inc. Tax)\",\"Payment Type\",\"Invoice status\",\"Invoice Closed Date\"\n"
            ."\"Nejma Dahchache\",Service,S1,Facial,105.0000,Card,Closed,02/10/2026\n"
            ."\"Nejma Dahchache\",Service,S2,Wax,50.0000,Card,Closed,02/10/2026\n"
            ."\"Nejma Dahchache\",Service,S3,Nails,30.0000,Custom - Qlub,Closed,03/10/2026\n"
            ."\"Nejma Dahchache\",Product,S4,Cream,99.0000,Card,Closed,03/10/2026\n"
            ."\"Someone Else\",Service,S5,Facial,500.0000,Card,Closed,03/10/2026\n";
        $file = UploadedFile::fake()->createWithContent('Sales-Accrual.csv', $csv);
        $admin = User::where('email', 'admin@your-domain.com')->first() ?? User::first();

        $this->actingAs($admin)->get(route('admin.integrations.compare'))->assertOk();
        $this->actingAs($admin)->post(route('admin.integrations.compare'), ['file' => $file])->assertOk()
            ->assertSee('2026-10-02')->assertSee('2026-10-03')
            ->assertSee('185.00')       // export: 105 + 50 + 30
            ->assertSee('105.00')       // ours
            ->assertSee('Paid by Gift Card(1)')
            ->assertSee('not in our data (not synced)')
            ->assertDontSee('Someone Else');
    }
}
