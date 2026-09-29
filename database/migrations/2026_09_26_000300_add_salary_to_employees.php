<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('employees', 'salary')) {
            Schema::table('employees', function (Blueprint $table) {
                $table->decimal('salary', 12, 2)->nullable();
                $table->decimal('target_multiplier', 5, 2)->nullable(); // blank = default (7)
                $table->string('section')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::table('employees', fn (Blueprint $table) => $table->dropColumn(['salary', 'target_multiplier', 'section']));
    }
};
