<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Worksy's per-person EPF setup. A Custom scheme replaces the statutory rates
     * (employee % lives in the existing epf_employee_rate_override); a statutory scheme
     * can add extra on top, by percentage or by a ringgit amount.
     */
    public function up(): void
    {
        Schema::table('salary_structures', function (Blueprint $table) {
            $table->decimal('epf_employer_rate_override', 5, 2)->nullable()->after('epf_employee_rate_override');
            $table->string('epf_additional_by', 16)->nullable()->after('epf_employer_rate_override');
            $table->decimal('epf_additional_employee', 10, 2)->nullable()->after('epf_additional_by');
            $table->decimal('epf_additional_employer', 10, 2)->nullable()->after('epf_additional_employee');
        });
    }

    public function down(): void
    {
        Schema::table('salary_structures', fn (Blueprint $table) => $table->dropColumn(['epf_employer_rate_override', 'epf_additional_by', 'epf_additional_employee', 'epf_additional_employer']));
    }
};
