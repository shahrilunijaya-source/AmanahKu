<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Worksy Bank & Statutory fields. All nullable; payroll maths reads none of them. */
    public function up(): void
    {
        Schema::table('salary_structures', function (Blueprint $table) {
            $table->string('bank_holder_name', 160)->nullable();
            $table->boolean('tax_resident')->default(true);
            $table->string('tax_category', 8)->nullable();
            $table->string('employee_tax_status', 32)->nullable();
            $table->json('child_relief_breakdown')->nullable();
            $table->string('epf_scheme', 32)->nullable();
            $table->string('socso_category', 32)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('salary_structures', fn (Blueprint $table) => $table->dropColumn(['bank_holder_name', 'tax_resident', 'tax_category', 'employee_tax_status', 'child_relief_breakdown', 'epf_scheme', 'socso_category']));
    }
};
