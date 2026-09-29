<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('dashboard_widgets', 'options')) {
            Schema::table('dashboard_widgets', function (Blueprint $table) {
                $table->json('options')->nullable()->after('filters');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('dashboard_widgets', 'options')) {
            Schema::table('dashboard_widgets', fn (Blueprint $table) => $table->dropColumn('options'));
        }
    }
};
