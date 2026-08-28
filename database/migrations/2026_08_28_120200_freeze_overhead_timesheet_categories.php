<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Cut the pickable effort types down to the five the director actually costs:
 * Development, Maintenance, InHouse Project, Sales, and Others as the one overhead
 * bucket. Everything else is deactivated, not deleted — old entries keep resolving
 * their category name in past reports, they just cannot be chosen again.
 *
 * Leave categories go for a second reason: approved leave already arrives as a locked
 * row written by HR, so a staffer picking "On Leave" was a second, unreliable copy of
 * a fact the system owns.
 */
return new class extends Migration
{
    private const FROZEN = [
        'Account and Finance',
        'HR and Admin',
        'Administration',
        'Study & Research',
        'Charity',
        'Marketing',
        'Continuous Improvement (CI)',
        'On Leave',
        'Medical Leave',
    ];

    public function up(): void
    {
        DB::table('timesheet_categories')->whereIn('name', self::FROZEN)->update(['is_active' => false]);
    }

    public function down(): void
    {
        DB::table('timesheet_categories')->whereIn('name', self::FROZEN)->update(['is_active' => true]);
    }
};
