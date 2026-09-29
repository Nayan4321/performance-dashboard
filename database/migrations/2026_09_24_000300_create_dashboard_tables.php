<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dashboards', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('description')->nullable();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete(); // null = all organizations
            $table->json('visible_to_roles')->nullable(); // null = everyone who can view dashboards
            $table->unsignedInteger('sort_order')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('dashboard_widgets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dashboard_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('type'); // kpi | bar | line | pie | doughnut | funnel | table
            $table->string('dataset'); // appointments | sales | leads | calls | guests | employees
            $table->string('aggregate')->default('count'); // count | sum | avg | min | max
            $table->string('metric_field')->nullable();
            $table->string('group_by')->nullable();
            $table->json('filters')->nullable(); // [{field, operator, value}]
            $table->string('date_range')->default('this_month');
            $table->unsignedTinyInteger('width')->default(6); // bootstrap columns 3..12
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dashboard_widgets');
        Schema::dropIfExists('dashboards');
    }
};
