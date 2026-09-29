<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Fields needed to mirror Zenoti's Admin Dashboard (bookings, sale categories, collections). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->string('raw_status')->nullable()->after('status');
            $table->timestamp('booked_at')->nullable()->index()->after('end_time'); // when the booking was made
        });

        Schema::table('sales', function (Blueprint $table) {
            // Normalised: Service, Product, Package, Gift card, Membership, Prepaid card, Other
            $table->string('category')->nullable()->index()->after('item_type');
        });

        Schema::create('collections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('guest_id')->nullable()->constrained()->nullOnDelete();
            $table->string('zenoti_id')->nullable()->unique();
            $table->string('invoice_no')->nullable();
            $table->string('payment_type')->nullable(); // cash, card, ...
            $table->decimal('amount', 12, 2)->default(0);
            $table->timestamp('collected_at')->nullable()->index();
            $table->json('raw')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('collections');
        Schema::table('sales', fn (Blueprint $t) => $t->dropColumn('category'));
        Schema::table('appointments', fn (Blueprint $t) => $t->dropColumn(['raw_status', 'booked_at']));
    }
};
