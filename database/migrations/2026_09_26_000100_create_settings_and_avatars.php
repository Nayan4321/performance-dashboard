<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('settings')) {
            Schema::create('settings', function (Blueprint $table) {
                $table->string('key')->primary();
                $table->text('value')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasColumn('users', 'avatar_path')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('avatar_path')->nullable()->after('phone');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
        if (Schema::hasColumn('users', 'avatar_path')) {
            Schema::table('users', fn (Blueprint $table) => $table->dropColumn('avatar_path'));
        }
    }
};
