<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Retire the employees.is_director flag. Director is now a first-class tenant role
 * (Permissions::MANAGEMENT_TIER) assigned on the Roles & access screen — the org-chart
 * leadership band and board approval authority both key off that role, so the per-person
 * boolean is redundant and removed to keep a single source of truth. The employee_manager
 * pivot (additional/dotted-line managers) added alongside it stays.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('employees', 'is_director')) {
            Schema::table('employees', function (Blueprint $table) {
                $table->dropColumn('is_director');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('employees', 'is_director')) {
            Schema::table('employees', function (Blueprint $table) {
                $table->boolean('is_director')->default(false)->after('reports_to_id');
            });
        }
    }
};
