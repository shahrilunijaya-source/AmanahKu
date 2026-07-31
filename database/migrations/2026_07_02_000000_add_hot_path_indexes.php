<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes for the fastest-growing filter columns on hot query paths. Purely
 * additive — no behavior change:
 *  - timesheet_entries.entry_date: per-day % totals (compliance + capture grid)
 *  - knowledge_entries.created_at: the unread-badge cutoff query on every render
 *  - attendance_records (tenant_id, date): tenant-wide day views (reports/admin)
 *  - employees.archived_at: every active()/archived() scope filter
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('timesheet_entries', function (Blueprint $table) {
            $table->index('entry_date');
        });
        Schema::table('knowledge_entries', function (Blueprint $table) {
            $table->index('created_at');
        });
        Schema::table('attendance_records', function (Blueprint $table) {
            $table->index(['tenant_id', 'date']);
        });
        Schema::table('employees', function (Blueprint $table) {
            $table->index('archived_at');
        });
    }

    public function down(): void
    {
        Schema::table('timesheet_entries', function (Blueprint $table) {
            $table->dropIndex(['entry_date']);
        });
        Schema::table('knowledge_entries', function (Blueprint $table) {
            $table->dropIndex(['created_at']);
        });
        Schema::table('attendance_records', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'date']);
        });
        Schema::table('employees', function (Blueprint $table) {
            $table->dropIndex(['archived_at']);
        });
    }
};
