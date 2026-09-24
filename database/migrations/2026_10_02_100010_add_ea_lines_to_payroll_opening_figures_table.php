<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payroll Figures Take On, Worksy style: one year-to-date amount per Form EA line.
 * Lines that already had a column keep it (gross is B1a, pcb_paid is D1, and so on);
 * every other EA line lives in this one JSON column, keyed by box
 * (see PayrollOpeningFigure::EA_AMOUNTS and EA_TEXT).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_opening_figures', function (Blueprint $table) {
            $table->json('ea_lines')->nullable()->after('exempt_allowances');
        });
    }

    public function down(): void
    {
        Schema::table('payroll_opening_figures', function (Blueprint $table) {
            $table->dropColumn('ea_lines');
        });
    }
};
