<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Zenoti "guests" (customers).
        Schema::create('guests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('zenoti_id')->nullable()->unique();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('gender')->nullable();
            $table->string('source')->default('zenoti');
            $table->json('raw')->nullable();
            $table->timestamp('registered_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('appointments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('guest_id')->nullable()->constrained()->nullOnDelete();
            $table->string('zenoti_id')->nullable()->unique();
            $table->string('service_name')->nullable();
            $table->string('status')->nullable()->index();
            $table->decimal('price', 12, 2)->default(0);
            $table->timestamp('start_time')->nullable()->index();
            $table->timestamp('end_time')->nullable();
            $table->json('raw')->nullable();
            $table->timestamps();
        });

        Schema::create('sales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('guest_id')->nullable()->constrained()->nullOnDelete();
            $table->string('zenoti_id')->nullable()->unique();
            $table->string('invoice_no')->nullable();
            $table->string('item_type')->nullable(); // service | product | membership | package ...
            $table->string('item_name')->nullable();
            $table->string('status')->nullable();
            $table->decimal('quantity', 10, 2)->default(1);
            $table->decimal('gross_amount', 12, 2)->default(0);
            $table->decimal('discount', 12, 2)->default(0);
            $table->decimal('net_amount', 12, 2)->default(0);
            $table->timestamp('sold_at')->nullable()->index();
            $table->json('raw')->nullable();
            $table->timestamps();
        });

        // Leads from Zenoti / CallGear / manual entry; `stage` powers funnel charts.
        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('guest_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source')->default('manual'); // zenoti | callgear | manual
            $table->string('external_id')->nullable();
            $table->string('name')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('channel')->nullable(); // walk-in, phone, website, instagram ...
            $table->string('stage')->default('new')->index(); // new, contacted, booked, visited, converted, lost
            $table->decimal('value', 12, 2)->default(0);
            $table->timestamp('lead_at')->nullable()->index();
            $table->json('raw')->nullable();
            $table->timestamps();
            $table->unique(['source', 'external_id']);
        });

        // CallGear call records (populated once the CallGear API is connected).
        Schema::create('calls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();
            $table->string('external_id')->nullable()->unique();
            $table->string('direction')->nullable(); // in | out
            $table->string('status')->nullable()->index(); // answered | missed | ...
            $table->string('caller')->nullable();
            $table->string('callee')->nullable();
            $table->integer('duration_seconds')->default(0);
            $table->integer('wait_seconds')->default(0);
            $table->timestamp('started_at')->nullable()->index();
            $table->json('raw')->nullable();
            $table->timestamps();
        });

        // Audit of every sync run and every webhook received.
        Schema::create('sync_runs', function (Blueprint $table) {
            $table->id();
            $table->string('provider');
            $table->string('entity');
            $table->string('status')->default('running'); // running | success | failed
            $table->unsignedInteger('created_count')->default(0);
            $table->unsignedInteger('updated_count')->default(0);
            $table->unsignedInteger('deactivated_count')->default(0);
            $table->text('message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider');
            $table->string('event_type')->nullable();
            $table->json('payload');
            $table->string('status')->default('received'); // received | processed | ignored | failed
            $table->text('error')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['webhook_events', 'sync_runs', 'calls', 'leads', 'sales', 'appointments', 'guests'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
