<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Payment + statutory identifiers needed for the bank payment file and the
        // EPF/SOCSO/EIS contribution reports. Nullable — payroll still runs without them.
        Schema::table('salary_structures', function (Blueprint $table) {
            $table->string('bank_name')->nullable()->after('currency');
            $table->string('bank_account_no')->nullable()->after('bank_name');
            $table->string('epf_no')->nullable()->after('bank_account_no');
            $table->string('socso_no')->nullable()->after('epf_no');
            $table->string('nric')->nullable()->after('socso_no');
        });
    }

    public function down(): void
    {
        Schema::table('salary_structures', function (Blueprint $table) {
            $table->dropColumn(['bank_name', 'bank_account_no', 'epf_no', 'socso_no', 'nric']);
        });
    }
};
