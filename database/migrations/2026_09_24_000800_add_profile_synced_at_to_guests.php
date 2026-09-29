<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** When the guest's full Zenoti profile was last fetched (guests first arrive with an appointment). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('guests', function (Blueprint $table) {
            $table->timestamp('profile_synced_at')->nullable()->index()->after('registered_at');
        });
    }

    public function down(): void
    {
        Schema::table('guests', fn (Blueprint $t) => $t->dropColumn('profile_synced_at'));
    }
};
