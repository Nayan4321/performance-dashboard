<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/** Complaint calls: agents mark a call as a complaint with a note; managers follow them up. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('complaints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('call_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained()->nullOnDelete(); // agent who took the call
            $table->foreignId('guest_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('phone', 40)->nullable();
            $table->string('category', 40)->nullable();
            $table->text('message');
            $table->string('status', 20)->default('open')->index();
            $table->text('resolution')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source', 20)->default('dashboard');
            $table->string('external_id', 100)->nullable()->unique(); // CallGear call + tag, for imported ones
            $table->timestamp('called_at')->nullable()->index();
            $table->timestamps();
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $manage = Permission::findOrCreate('complaints.manage', 'web');
        foreach (['super-admin', 'management', 'main-admin'] as $name) {
            $role = Role::where('name', $name)->first();
            // An empty main-admin is filled by the seeder later; giving it one permission now would stop that.
            if ($role && ($name !== 'main-admin' || $role->permissions()->exists())) {
                $role->givePermissionTo($manage);
            }
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::dropIfExists('complaints');
    }
};
