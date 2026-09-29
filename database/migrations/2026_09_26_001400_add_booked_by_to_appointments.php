<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->foreignId('booked_by_employee_id')->nullable()->after('employee_id')->constrained('employees')->nullOnDelete();
        });
        // Bookings already logged on the Activity page know who made them.
        DB::table('activity_logs')->where('subject_type', 'appointment')->where('action', 'booked')->whereNotNull('actor_employee_id')
            ->orderBy('id')->select('subject_id', 'actor_employee_id')
            ->chunk(500, function ($rows) {
                foreach ($rows as $r) {
                    DB::table('appointments')->where('id', $r->subject_id)->whereNull('booked_by_employee_id')->update(['booked_by_employee_id' => $r->actor_employee_id]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('appointments', fn (Blueprint $t) => $t->dropConstrainedForeignId('booked_by_employee_id'));
    }
};
