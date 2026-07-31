<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * (tenant_id, status) indexes on the four busiest approval-queue tables (AK-DB-02).
 * Every dashboard/screen render and the reporting-line queue scopes filter these on
 * status inside a tenant partition, but the add_hot_path_indexes batch skipped them
 * while covering seven sibling tables. Purely additive — no behavior change.
 */
return new class extends Migration
{
    /** Tables that carry an approval queue filtered by (tenant_id, status). */
    private const TABLES = ['leave_requests', 'claims', 'overtime_requests', 'expense_reports'];

    public function up(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->index(['tenant_id', 'status']);
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropIndex(['tenant_id', 'status']);
            });
        }
    }
};
