<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A recurring per-employee pay/deduction line against a Payroll Item catalogue
        // entry — the client's HRMS calls this a "Fixed Transaction" (a one-off is an
        // "Individual Transaction", a later pass). Every run-generation month sums the
        // Fixed Transactions whose [start_period, end_period] covers that period.
        Schema::create('fixed_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            // Payroll history has multi-year retention duties — never let deleting an
            // Employee silently wipe it. restrict forces an explicit decision (AK-DB-01,
            // same convention as salary_structures/payslips in 2026_06_24_000004).
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->foreignId('payroll_item_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 12, 2);
            $table->string('start_period', 7);              // YYYY-MM, inclusive
            $table->string('end_period', 7)->nullable();     // YYYY-MM inclusive, null = open-ended
            // The client's HRMS lets the final month's amount differ from every other
            // month (e.g. a part-month allowance change on the way out) — applied only
            // when the run period equals end_period, ignored while open-ended.
            $table->decimal('last_amount', 12, 2)->nullable();
            $table->boolean('prorate')->default(false);
            $table->text('remarks')->nullable();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            // "active for this employee in this period": tenant+employee+start narrows the
            // scan, the run-generation query then filters end_period in PHP/SQL from there.
            $table->index(['tenant_id', 'employee_id', 'start_period', 'end_period'], 'fixed_tx_active_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fixed_transactions');
    }
};
