<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * overtime_hours has always meant RAW hours; the multiplier that turns those into
     * money now travels alongside it explicitly instead of being implied (and, for a
     * pulled multi-rate payslip, guessed at) — see PayrollCalculator's overtime_groups
     * and PayslipLine's one-line-per-rate breakdown. Null when a pull mixed more than
     * one rate (1.5x ordinary + 3x public holiday, say): no single multiplier describes
     * that payslip, the PayslipLine rows are the source of truth for the breakdown.
     */
    public function up(): void
    {
        Schema::table('payslips', function (Blueprint $table) {
            $table->decimal('overtime_multiplier', 4, 2)->nullable()->default(1.5)->after('overtime_amount');
        });
    }

    public function down(): void
    {
        Schema::table('payslips', function (Blueprint $table) {
            $table->dropColumn('overtime_multiplier');
        });
    }
};
