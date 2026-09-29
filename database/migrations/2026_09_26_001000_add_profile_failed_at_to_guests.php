<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Guests whose profile call failed are retried a day later instead of blocking the queue of profiles. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('guests', 'profile_failed_at')) {
            Schema::table('guests', fn (Blueprint $t) => $t->timestamp('profile_failed_at')->nullable()->after('profile_synced_at'));
        }
    }

    public function down(): void
    {
        Schema::table('guests', fn (Blueprint $t) => $t->dropColumn('profile_failed_at'));
    }
};
