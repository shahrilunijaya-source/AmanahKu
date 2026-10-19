<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marks a staff member as "payroll only": still paid every run, but left out of
 * attendance, timesheets and reports, and hidden from the directory for non-HR staff.
 * Set by HR from the profile, every change audited (EmployeeController::payrollOnly).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->boolean('payroll_only')->default(false)->after('archived_at');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('payroll_only');
        });
    }
};
