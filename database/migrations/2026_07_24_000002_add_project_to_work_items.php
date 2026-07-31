<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Let a board card name the project it belongs to. Optional (nullable): only
     * dev cards tend to carry one, HR/finance cards leave it null. Reuses the same
     * projects table the timesheet books time to, so board plans and timesheet
     * actuals share one project vocabulary. nullOnDelete: dropping a project just
     * unlinks its cards, never deletes them.
     */
    public function up(): void
    {
        Schema::table('work_items', function (Blueprint $table) {
            $table->foreignId('project_id')->nullable()->after('employee_id')
                ->constrained('projects')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('work_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('project_id');
        });
    }
};
