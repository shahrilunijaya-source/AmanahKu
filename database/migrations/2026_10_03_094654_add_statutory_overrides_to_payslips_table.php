<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('payslips', function (Blueprint $table) {
            // Null = computed. Set = HR typed it on the draft payslip review; the same figure
            // is also written into the normal column so every export reads it.
            $table->decimal('epf_employee_override', 12, 2)->nullable();
            $table->decimal('epf_employer_override', 12, 2)->nullable();
            $table->decimal('socso_employee_override', 12, 2)->nullable();
            $table->decimal('socso_employer_override', 12, 2)->nullable();
            $table->decimal('eis_employee_override', 12, 2)->nullable();
            $table->decimal('eis_employer_override', 12, 2)->nullable();
            $table->decimal('unpaid_deduction_override', 12, 2)->nullable();
            $table->decimal('claims_reimbursement_override', 12, 2)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payslips', function (Blueprint $table) {
            $table->dropColumn(['epf_employee_override', 'epf_employer_override', 'socso_employee_override', 'socso_employer_override', 'eis_employee_override', 'eis_employer_override', 'unpaid_deduction_override', 'claims_reimbursement_override']);
        });
    }
};
