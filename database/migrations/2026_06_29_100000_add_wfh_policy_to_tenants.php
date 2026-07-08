<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Company-wide work-from-home policy. Before this, WFH days borrowed the staff's branch
 * working hours and used a hardcoded 200m radius. These tenant-level columns let HR set
 * WFH hours/radius once for the whole company; ScheduleResolver::homeSite() reads them and
 * falls back to the branch only when a field is left blank.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->unsignedInteger('wfh_radius_m')->nullable()->after('subscription_end');
            $table->time('wfh_work_start')->nullable()->after('wfh_radius_m');
            $table->time('wfh_work_end')->nullable()->after('wfh_work_start');
            $table->decimal('wfh_min_hours', 4, 1)->nullable()->after('wfh_work_end');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['wfh_radius_m', 'wfh_work_start', 'wfh_work_end', 'wfh_min_hours']);
        });
    }
};
