<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** "Sync now" clicks, queued so the cron job runs them (long syncs time out in the browser on shared hosting). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_requests', function (Blueprint $table) {
            $table->id();
            $table->string('provider');
            $table->string('entity')->nullable();
            $table->unsignedSmallInteger('days')->nullable();
            $table->string('status')->default('queued')->index(); // queued | running | done | failed
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_requests');
    }
};
