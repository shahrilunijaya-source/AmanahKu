<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-staff reusable allocation presets. A staff member saves a named
     * allocation (e.g. "Full-time KDN dev") once and applies it to any day,
     * skipping the category/project/sub-pillar picking each week.
     */
    public function up(): void
    {
        Schema::create('timesheet_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->foreignId('category_id')->nullable()->constrained('timesheet_categories')->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->foreignId('sub_pillar_id')->nullable()->constrained('project_sub_pillars')->nullOnDelete();
            $table->decimal('percentage', 5, 2)->nullable();
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();

            $table->unique(['employee_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('timesheet_templates');
    }
};
