<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Profiles marked failed by Zenoti's 429 quota error were not really failing: let them be fetched again. */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('guests')->whereNotNull('profile_failed_at')->whereNull('profile_synced_at')->update(['profile_failed_at' => null]);
    }

    public function down(): void {}
};
