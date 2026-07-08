<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One current salary structure per employee (edit updates in place).
        Schema::create('salary_structures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            // Salary/statutory data has multi-year retention duties — never let deleting
            // an Employee silently wipe it. restrict forces an explicit decision (AK-DB-01).
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->decimal('basic_salary', 12, 2)->default(0);
            $table->json('allowances')->nullable();        // [{name, amount}]
            $table->string('currency', 8)->default('MYR');
            $table->date('effective_from')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'employee_id']);
        });

        // Editable statutory rate tables, one row per type per tenant. Seeded with
        // current published MY values; verify against KWSP/PERKESO before a real run.
        Schema::create('statutory_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('type');                         // epf | socso | eis
            $table->json('config');                         // rate percentages, ceiling, threshold
            $table->string('label')->nullable();
            $table->date('effective_from')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'type']);
        });

        Schema::create('payroll_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('period', 7);                    // YYYY-MM
            $table->string('label')->nullable();            // "June 2026"
            $table->string('status')->default('draft');     // draft | approved | finalized
            $table->foreignId('run_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('totals')->nullable();             // cached {gross, deductions, net, employer_cost, headcount}
            $table->text('notes')->nullable();
            $table->timestamp('finalized_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'period']);
        });

        Schema::create('payslips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payroll_run_id')->constrained()->cascadeOnDelete();
            // Payslips are compliance records — deleting an Employee must not erase their
            // finalised payroll history. restrict forces an explicit decision (AK-DB-01).
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();

            // Earnings
            $table->decimal('basic', 12, 2)->default(0);
            $table->decimal('allowances_total', 12, 2)->default(0);
            $table->decimal('overtime_hours', 6, 2)->default(0);
            $table->decimal('overtime_amount', 12, 2)->default(0);
            $table->decimal('bonus', 12, 2)->default(0);
            $table->json('additions')->nullable();          // [{name, amount}]
            $table->decimal('unpaid_days', 5, 2)->default(0);
            $table->decimal('unpaid_deduction', 12, 2)->default(0);
            $table->decimal('gross', 12, 2)->default(0);

            // Statutory (employee + employer split)
            $table->decimal('epf_employee', 12, 2)->default(0);
            $table->decimal('epf_employer', 12, 2)->default(0);
            $table->decimal('socso_employee', 12, 2)->default(0);
            $table->decimal('socso_employer', 12, 2)->default(0);
            $table->decimal('eis_employee', 12, 2)->default(0);
            $table->decimal('eis_employer', 12, 2)->default(0);
            $table->decimal('pcb', 12, 2)->default(0);       // manual income-tax entry
            $table->json('other_deductions')->nullable();    // [{name, amount}]

            // Reimbursement (approved claims pulled into the run)
            $table->decimal('claims_reimbursement', 12, 2)->default(0);
            $table->json('claim_ids')->nullable();

            $table->decimal('total_deductions', 12, 2)->default(0);
            $table->decimal('net_pay', 12, 2)->default(0);
            $table->decimal('employer_cost', 12, 2)->default(0);
            $table->string('notes')->nullable();
            $table->timestamps();
            $table->unique(['payroll_run_id', 'employee_id']);
        });

        // Trace when a claim was reimbursed through payroll.
        Schema::table('claims', function (Blueprint $table) {
            $table->timestamp('paid_at')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('claims', function (Blueprint $table) {
            $table->dropColumn('paid_at');
        });
        Schema::dropIfExists('payslips');
        Schema::dropIfExists('payroll_runs');
        Schema::dropIfExists('statutory_rates');
        Schema::dropIfExists('salary_structures');
    }
};
