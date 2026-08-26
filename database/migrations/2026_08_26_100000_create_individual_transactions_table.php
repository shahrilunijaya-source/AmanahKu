<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A one-off per-employee pay/deduction line against a Payroll Item catalogue
        // entry, queued for a single month — the client's HRMS calls this an "Individual
        // Transaction" (a recurring one is a "Fixed Transaction", see
        // 2026_08_25_200000). Several rows per employee per period are allowed — this is
        // a list of one-offs, not a single value. createRun() picks up every row whose
        // period matches the run being generated; editing a draft payslip writes into
        // this same table rather than a parallel mechanism, so the two entry points can
        // never disagree about what is on the payslip.
        Schema::create('individual_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            // Payroll history has multi-year retention duties — never let deleting an
            // Employee silently wipe it. restrict forces an explicit decision (AK-DB-01,
            // same convention as fixed_transactions/salary_structures/payslips).
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->foreignId('payroll_item_id')->constrained()->restrictOnDelete();
            $table->string('period', 7);   // YYYY-MM — the single month this one-off applies to
            $table->decimal('amount', 12, 2);
            $table->text('remarks')->nullable();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            // "this employee's one-offs for this period": the exact lookup createRun() and
            // updatePayslip() both make.
            $table->index(['tenant_id', 'employee_id', 'period'], 'individual_tx_period_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('individual_transactions');
    }
};
