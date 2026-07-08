<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Archive (soft-hide) support for staff. `archived_at` flags a person as removed
 * from the active directory + every active picker, WITHOUT a global soft-delete
 * scope — so their payroll, attendance, timesheet and approval history still
 * resolves their name everywhere it is referenced (fail-safe). Restorable by
 * clearing the column. Additive + nullable, so existing data is untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->timestamp('archived_at')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('archived_at');
        });
    }
};
