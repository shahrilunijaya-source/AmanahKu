<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CR-04 card roles (docs/build/contracts/roles.md). A tagged person is a Helper
 * or FYI (pivot role, existing rows become helpers), and a card may carry one
 * Reviewer who alone moves it from In Review to Done.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_item_participant', function (Blueprint $table) {
            $table->string('role', 10)->default('helper')->after('employee_id');
        });

        Schema::table('work_items', function (Blueprint $table) {
            $table->foreignId('reviewer_id')->nullable()->after('assigned_by_id')->constrained('employees')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('work_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reviewer_id');
        });

        Schema::table('work_item_participant', function (Blueprint $table) {
            $table->dropColumn('role');
        });
    }
};
