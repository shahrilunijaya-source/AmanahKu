<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Company-wide late-clock-in grace period. ClockService::isLate() previously flagged a
 * punch late the instant it crossed the shift start second, so an exact-time clock-in
 * (10:00:01) still read as late. This tenant-level minute buffer applies to every
 * arrangement (office, client, WFH, hybrid) — same "one company-wide setting" pattern
 * as wfh_work_start (see 2026_06_29_100000_add_wfh_policy_to_tenants.php).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->unsignedTinyInteger('late_grace_minutes')->nullable()->after('wfh_min_hours');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('late_grace_minutes');
        });
    }
};
