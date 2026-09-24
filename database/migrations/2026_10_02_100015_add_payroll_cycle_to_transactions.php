<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Which run pays a Fixed or Individual Transaction: month_end (every row so far)
        // or mid_month, which pays it early in the mid-month run.
        Schema::table('fixed_transactions', function (Blueprint $t) {
            $t->string('payroll_cycle', 12)->default('month_end');
        });
        Schema::table('individual_transactions', function (Blueprint $t) {
            $t->string('payroll_cycle', 12)->default('month_end');
        });
    }

    public function down(): void
    {
        Schema::table('fixed_transactions', function (Blueprint $t) {
            $t->dropColumn('payroll_cycle');
        });
        Schema::table('individual_transactions', function (Blueprint $t) {
            $t->dropColumn('payroll_cycle');
        });
    }
};
