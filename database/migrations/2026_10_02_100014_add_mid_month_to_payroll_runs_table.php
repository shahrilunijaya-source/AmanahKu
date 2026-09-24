<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Process Payroll wizard: HR's free-text remarks on a run, and how a mid-month
        // run worked out its advance (cutoff day or percentage of salary).
        Schema::table('payroll_runs', function (Blueprint $t) {
            $t->text('remarks')->nullable();
            $t->string('mid_month_basis', 12)->nullable();
            $t->unsignedSmallInteger('mid_month_value')->nullable();
        });

        // The mid-month advance a month-end or final payslip takes back out of net pay.
        // Its own column so gross and every statutory base stay untouched.
        Schema::table('payslips', function (Blueprint $t) {
            $t->decimal('mid_month_advance', 12, 2)->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('payslips', function (Blueprint $t) {
            $t->dropColumn('mid_month_advance');
        });

        Schema::table('payroll_runs', function (Blueprint $t) {
            $t->dropColumn(['remarks', 'mid_month_basis', 'mid_month_value']);
        });
    }
};
