<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('sync_requests', 'progress')) {
            Schema::table('sync_requests', function (Blueprint $table) {
                $table->unsignedInteger('progress')->default(0)->after('message');
            });
        }
    }

    public function down(): void
    {
        Schema::table('sync_requests', fn (Blueprint $table) => $table->dropColumn('progress'));
    }
};
