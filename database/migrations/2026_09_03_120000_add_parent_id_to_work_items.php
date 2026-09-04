<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_items', function (Blueprint $table) {
            // A child card (subtask) points at its parent. One level only, enforced in
            // code (WorkItemController::store). Deleting the parent deletes the children.
            $table->foreignId('parent_id')->nullable()->after('employee_id')
                ->constrained('work_items')->cascadeOnDelete();
        });

        // The JSON checklist tried on the same day never shipped; a dev database that
        // ran its migration still carries the column.
        if (Schema::hasColumn('work_items', 'subtasks')) {
            Schema::table('work_items', fn (Blueprint $table) => $table->dropColumn('subtasks'));
        }
    }

    public function down(): void
    {
        Schema::table('work_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_id');
        });
    }
};
