<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec F12: one row per agency filing — the monthly EPF / SOCSO-EIS / PCB / HRD Corp
 * submissions a finalized run opens, and the yearly Form EA and Form E. Carries the due
 * date, when HR downloaded the file, and the receipt once it is actually submitted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_submissions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            // Monthly rows hang off a run; the yearly EA / Form E rows carry a year instead.
            $t->foreignId('payroll_run_id')->nullable()->constrained('payroll_runs')->cascadeOnDelete();
            $t->smallInteger('year')->nullable();
            $t->string('agency');
            $t->date('due_on');
            $t->timestamp('downloaded_at')->nullable();
            $t->timestamp('submitted_at')->nullable();
            $t->foreignId('submitted_by_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('receipt_reference', 80)->nullable();
            $t->decimal('amount_paid', 12, 2)->nullable();
            $t->timestamps();
            $t->unique(['tenant_id', 'payroll_run_id', 'year', 'agency']);
            $t->index(['tenant_id', 'due_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_submissions');
    }
};
