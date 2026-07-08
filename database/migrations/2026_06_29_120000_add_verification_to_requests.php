<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two-step approval gate driven by the org chart: a request is first VERIFIED by the
 * requester's immediate superior (reports_to), then APPROVED by management. Adds the
 * `verified` status plus who/when verified to leave, claims and overtime.
 *
 * The status columns move from enum to plain string so the new value needs no fragile
 * cross-database enum rewrite — the allowed values are enforced in the controllers, which
 * are the only writers.
 */
return new class extends Migration
{
    private const TABLES = ['leave_requests', 'claims', 'overtime_requests'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->string('status')->default('submitted')->change();
                $t->foreignId('verified_by_id')->nullable()->after('status')->constrained('employees')->nullOnDelete();
                $t->timestamp('verified_at')->nullable()->after('verified_by_id');
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropConstrainedForeignId('verified_by_id');
                $t->dropColumn('verified_at');
            });
        }
    }
};
