<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Frozen-at-generation total of every deduction-type Fixed Transaction, mirroring
        // allowances_total's role for earnings — set once at run creation, left untouched
        // by later payslip edits (see PayrollController::updatePayslip's "stays as
        // generated" convention).
        Schema::table('payslips', function (Blueprint $table) {
            $table->decimal('fixed_deductions_total', 12, 2)->default(0)->after('unpaid_deduction');
        });
    }

    public function down(): void
    {
        Schema::table('payslips', function (Blueprint $table) {
            $table->dropColumn('fixed_deductions_total');
        });
    }
};
