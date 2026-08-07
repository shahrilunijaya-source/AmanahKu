<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Give late_grace_minutes a real default.
 *
 * The column shipped nullable with no default, and ClockService::isLate() falls back to
 * `?? 0`, so every tenant has been running on zero grace: a punch at 09:00:15 against an
 * 09:00 start is already late. That was harmless while lateness was only a silent flag.
 * It stops being harmless the moment a late punch demands a typed reason, which would
 * otherwise fire on essentially every arrival, every morning.
 *
 * Two halves, and both are needed: the default covers tenants created from now on, the
 * backfill covers the ones that already exist. HR can still set any value from 0 to 120
 * on the Attendance Setup screen; this only decides where a tenant starts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->unsignedTinyInteger('late_grace_minutes')->nullable()->default(15)->change();
        });

        DB::table('tenants')->whereNull('late_grace_minutes')->update(['late_grace_minutes' => 15]);
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->unsignedTinyInteger('late_grace_minutes')->nullable()->default(null)->change();
        });
    }
};
