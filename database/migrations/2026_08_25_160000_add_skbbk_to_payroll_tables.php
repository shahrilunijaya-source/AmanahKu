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
        Schema::table('salary_structures', function (Blueprint $table) {
            // SKBBK ("Lindung 24 Jam") — voluntary since 8 July 2026, entirely employee-paid.
            $table->boolean('skbbk_opt_in')->default(false)->after('cp38_monthly');
        });

        Schema::table('payslips', function (Blueprint $table) {
            // Separate employee-side SKBBK line — not part of socso_employee.
            $table->decimal('skbbk_employee', 12, 2)->default(0)->after('eis_employer');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('salary_structures', function (Blueprint $table) {
            $table->dropColumn('skbbk_opt_in');
        });

        Schema::table('payslips', function (Blueprint $table) {
            $table->dropColumn('skbbk_employee');
        });
    }
};
