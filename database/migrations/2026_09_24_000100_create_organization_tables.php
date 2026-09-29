<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->nullable()->unique();
            $table->string('zenoti_id')->nullable()->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code')->nullable();
            $table->string('zenoti_center_id')->nullable()->unique();
            $table->string('callgear_site_id')->nullable();
            $table->string('address')->nullable();
            $table->string('phone')->nullable();
            $table->boolean('is_warehouse')->default(false); // main stock location for inventory
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('zenoti_id')->nullable()->unique();
            $table->string('callgear_id')->nullable()->unique();
            $table->string('first_name');
            $table->string('last_name')->nullable();
            $table->string('email')->nullable()->index();
            $table->string('phone')->nullable();
            $table->string('job_title')->nullable();
            $table->string('source')->default('manual');
            $table->boolean('is_active')->default(true);
            $table->json('raw')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
        });

        // Modules that can be switched on per organization (and overridden per user).
        Schema::create('modules', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('name');
            $table->string('description')->nullable();
            $table->boolean('is_global')->default(false); // visible to every organization
            $table->timestamps();
        });

        Schema::create('module_organization', function (Blueprint $table) {
            $table->foreignId('module_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->primary(['module_id', 'organization_id']);
        });

        Schema::create('module_user', function (Blueprint $table) {
            $table->foreignId('module_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->boolean('allowed')->default(true); // explicit per-user allow / deny
            $table->primary(['module_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('module_user');
        Schema::dropIfExists('module_organization');
        Schema::dropIfExists('modules');
        Schema::dropIfExists('employees');
        Schema::dropIfExists('branches');
        Schema::dropIfExists('organizations');
    }
};
