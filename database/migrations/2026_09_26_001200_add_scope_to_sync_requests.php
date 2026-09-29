<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sync_requests', function (Blueprint $table) {
            $table->unsignedBigInteger('branch_id')->nullable()->after('days');
            $table->string('tag', 60)->nullable()->after('branch_id');
        });
    }

    public function down(): void
    {
        Schema::table('sync_requests', fn (Blueprint $t) => $t->dropColumn(['branch_id', 'tag']));
    }
};
