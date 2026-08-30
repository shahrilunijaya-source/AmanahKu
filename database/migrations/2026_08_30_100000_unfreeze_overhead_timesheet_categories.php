<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Put the overhead effort types back in the picker. 2026_08_28_120200 froze them on the
 * assumption that a card's category could be read off its project, so anything without a
 * project was overhead and "Others" covered it. The board says otherwise: 39 of 82 live
 * cards have no project, and roughly half of those are audits, payment vouchers, daily
 * reconciliation, salary listings and hiring — Account and Finance and HR and Admin work,
 * which between them already hold 70 timesheet entries. Filing all of it under Others put
 * real, nameable overhead in an unnamed bucket.
 *
 * Not a revert of that migration — it has already run on staging and production, so this
 * is a forward step of the same shape.
 *
 * Medical Leave stays frozen for its original reason, which the flip does not touch:
 * approved leave arrives as a locked row written by HR (LockedDays), so a hand-picked
 * leave category is only ever a second, unapproved copy of a day HR already decided.
 */
return new class extends Migration
{
    private const RESTORED = [
        'Account and Finance',
        'HR and Admin',
        'Administration',
        'Study & Research',
        'Charity',
        'Marketing',
        'Continuous Improvement (CI)',
    ];

    public function up(): void
    {
        DB::table('timesheet_categories')->whereIn('name', self::RESTORED)->update(['is_active' => true]);
    }

    public function down(): void
    {
        DB::table('timesheet_categories')->whereIn('name', self::RESTORED)->update(['is_active' => false]);
    }
};
