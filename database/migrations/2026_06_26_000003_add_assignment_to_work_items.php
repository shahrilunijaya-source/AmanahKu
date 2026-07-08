<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_items', function (Blueprint $table) {
            // assigned_by_id = the superior who created this tac. Null = a normal
            // self-created board card (unchanged behaviour). This single column is
            // the marker that a work item is an assigned task.
            $table->foreignId('assigned_by_id')->nullable()->after('employee_id')
                ->constrained('employees')->nullOnDelete();
            $table->timestamp('assigned_at')->nullable()->after('assigned_by_id');
        });
    }

    public function down(): void
    {
        Schema::table('work_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('assigned_by_id');
            $table->dropColumn('assigned_at');
        });
    }
};
