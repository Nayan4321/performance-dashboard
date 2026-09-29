<?php

use App\Models\GuideNote;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/** CallGear guide notes become editable: move them from resources/guides/*.md into the database once. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guide_notes', function (Blueprint $table) {
            $table->id();
            $table->string('guide', 60)->index();
            $table->string('title', 200);
            $table->text('important')->nullable(); // one red banner per line
            $table->text('body')->nullable();      // markdown
            $table->unsignedInteger('position')->default(0);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
        foreach (array_keys(\App\Http\Controllers\GuideController::GUIDES) as $guide) {
            GuideNote::importFile($guide);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $manage = Permission::findOrCreate('guides.manage', 'web');
        Role::where('name', 'super-admin')->first()?->givePermissionTo($manage);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::dropIfExists('guide_notes');
    }
};
