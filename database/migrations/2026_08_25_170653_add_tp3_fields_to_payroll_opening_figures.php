<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `payroll_opening_figures` records what a previous employer (or previous payroll
     * system) already paid an employee earlier this calendar year. Most of it mirrors
     * LHDN's Form TP3 ("Notification Form Relating to Employment with Previous Employers
     * in the Current Year") — gross, EPF, PCB paid, zakat, the additional-remuneration
     * pair, the employer's name/TIN, and section D's optional deductions (∑LP, which DOES
     * feed the PCB formula). SOCSO and EIS are NOT on Form TP3 — they come from the take-on
     * screen of whatever HRMS the client used before, kept here only for the year-end EA
     * form and HR's own reconciliation. See PayrollOpeningFigure's docblock for which
     * columns feed PcbYearToDate and which are record-keeping only.
     */
    public function up(): void
    {
        Schema::table('payroll_opening_figures', function (Blueprint $table) {
            $table->decimal('socso', 12, 2)->default(0)->after('additional_epf');
            $table->decimal('eis', 12, 2)->default(0)->after('socso');
            $table->string('previous_employer')->nullable()->after('eis');
            $table->string('previous_employer_tin', 40)->nullable()->after('previous_employer');
            $table->decimal('optional_deductions', 12, 2)->default(0)->after('previous_employer_tin');
            $table->decimal('exempt_allowances', 12, 2)->default(0)->after('optional_deductions');
        });
    }

    public function down(): void
    {
        Schema::table('payroll_opening_figures', function (Blueprint $table) {
            $table->dropColumn(['socso', 'eis', 'previous_employer', 'previous_employer_tin', 'optional_deductions', 'exempt_allowances']);
        });
    }
};
