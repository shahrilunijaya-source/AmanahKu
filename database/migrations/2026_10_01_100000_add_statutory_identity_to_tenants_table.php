<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Employer statutory identity (spec F1): the registration numbers every upload file and
 * annual form is keyed on. employer_tin and registration_number already exist.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('epf_employer_no', 40)->nullable()->after('employer_tin');
            $table->string('socso_employer_code', 40)->nullable()->after('epf_employer_no');
            $table->string('hrdf_registration_no', 40)->nullable()->after('socso_employer_code');
            $table->string('employer_category', 2)->nullable()->after('hrdf_registration_no');   // Form E item 3
            $table->string('employer_status', 2)->nullable()->after('employer_category');        // Form E item 4
            $table->string('paying_bank_code', 11)->nullable()->after('employer_status');
            $table->string('paying_bank_account_no', 40)->nullable()->after('paying_bank_code');
            $table->string('payroll_contact_name', 120)->nullable()->after('paying_bank_account_no');
            $table->string('payroll_contact_phone', 40)->nullable()->after('payroll_contact_name');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn([
                'epf_employer_no', 'socso_employer_code', 'hrdf_registration_no', 'employer_category', 'employer_status',
                'paying_bank_code', 'paying_bank_account_no', 'payroll_contact_name', 'payroll_contact_phone',
            ]);
        });
    }
};
