<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payslips', function (Blueprint $table) {
            // PCB on additional remuneration (bonus, arrears, …) — kept separate from the
            // normal-remuneration PCB so the payslip can show both lines (spec D.b.2).
            $table->decimal('pcb_additional', 12, 2)->default(0)->after('pcb');
            // Zakat/fi deducted this month — nets off the normal PCB (spec E.14/net MTD).
            $table->decimal('zakat', 12, 2)->default(0)->after('pcb_additional');
            // CP38 additional-tax instalment — a SEPARATE deduction from PCB, not netted.
            $table->decimal('cp38', 12, 2)->default(0)->after('zakat');
            // Null = PCB was computed by PcbCalculator. Set = HR typed a figure by hand on
            // a draft payslip; that figure wins over the computed one and survives recompute.
            $table->decimal('pcb_override', 12, 2)->nullable()->after('cp38');
        });

        // "Payroll Figures Take On" — what a previous employer or previous system already
        // paid this employee earlier in the same calendar year, needed so PcbYearToDate can
        // give the calculator correct year-to-date figures for anyone who joined (or whose
        // company switched to this app) mid-year. One row per tenant/employee/year.
        Schema::create('payroll_opening_figures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('year');
            $table->decimal('gross', 12, 2)->default(0);              // ∑Y opening
            $table->decimal('epf', 12, 2)->default(0);                // ∑K opening
            $table->decimal('pcb_paid', 12, 2)->default(0);           // X opening
            $table->decimal('zakat_paid', 12, 2)->default(0);         // Z opening
            $table->decimal('additional_gross', 12, 2)->default(0);   // opening bonus/etc gross
            $table->decimal('additional_epf', 12, 2)->default(0);     // opening EPF on the above
            $table->timestamps();
            $table->unique(['tenant_id', 'employee_id', 'year']);
        });

        // PCB is now the real LHDN computerised calculation, always on — the only thing
        // this table ever held tenant-editable was PCB config, so nothing is left in it.
        Schema::dropIfExists('statutory_rates');
    }

    public function down(): void
    {
        Schema::create('statutory_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->json('config');
            $table->string('label')->nullable();
            $table->date('effective_from')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'type']);
        });

        Schema::dropIfExists('payroll_opening_figures');

        Schema::table('payslips', function (Blueprint $table) {
            $table->dropColumn(['pcb_additional', 'zakat', 'cp38', 'pcb_override']);
        });
    }
};
