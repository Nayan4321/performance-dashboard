<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Employee activity: what changed in Zenoti between syncs (booked, changed, cancelled, deleted),
 * who did it when Zenoti says so, and in-app notifications for admins.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('actor_employee_id')->nullable()->constrained('employees')->nullOnDelete(); // who did it (when Zenoti tells us)
            $table->string('actor_name')->nullable();
            $table->foreignId('employee_id')->nullable()->constrained()->nullOnDelete(); // whose appointment it is
            $table->string('subject_type'); // appointment | guest
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('subject_label')->nullable();
            $table->string('action')->index(); // booked | changed | cancelled | no-show | deleted | restored
            $table->json('changes')->nullable(); // {field: [old, new]}
            $table->string('source')->default('sync'); // sync | webhook
            $table->timestamp('occurred_at')->index();
            $table->timestamps();
            $table->index(['subject_type', 'subject_id']);
        });

        Schema::table('appointments', function (Blueprint $table) {
            $table->timestamp('deleted_in_zenoti_at')->nullable()->index();
        });
        Schema::table('guests', function (Blueprint $table) {
            $table->timestamp('deleted_in_zenoti_at')->nullable()->index();
        });

        if (! Schema::hasTable('notifications')) {
            Schema::create('notifications', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('type');
                $table->morphs('notifiable');
                $table->text('data');
                $table->timestamp('read_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
        Schema::table('guests', fn (Blueprint $t) => $t->dropColumn('deleted_in_zenoti_at'));
        Schema::table('appointments', fn (Blueprint $t) => $t->dropColumn('deleted_in_zenoti_at'));
        Schema::dropIfExists('activity_logs');
    }
};
