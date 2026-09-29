<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_updates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('filename');
            $table->string('version')->nullable();
            $table->string('status')->default('uploaded'); // uploaded | applied | failed | rolled_back
            $table->json('files')->nullable();       // files the zip will write
            $table->json('new_files')->nullable();   // files that did not exist before (removed on rollback)
            $table->string('zip_path')->nullable();
            $table->string('backup_path')->nullable();
            $table->longText('output')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_updates');
    }
};
