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
            $table->string('nationality')->default('citizen')->after('nric');
            $table->boolean('epf_opt_in_60plus')->default(false)->after('nationality');
            $table->decimal('epf_employee_rate_override', 5, 2)->nullable()->after('epf_opt_in_60plus');
            $table->string('tax_no')->nullable()->after('epf_employee_rate_override');
            $table->string('marital_status')->default('single')->after('tax_no');
            $table->boolean('spouse_working')->default(false)->after('marital_status');
            $table->unsignedSmallInteger('children_relief_count')->default(0)->after('spouse_working');
            $table->boolean('disabled_self')->default(false)->after('children_relief_count');
            $table->boolean('disabled_spouse')->default(false)->after('disabled_self');
            $table->decimal('zakat_monthly', 12, 2)->default(0)->after('disabled_spouse');
            $table->decimal('cp38_monthly', 12, 2)->default(0)->after('zakat_monthly');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('salary_structures', function (Blueprint $table) {
            $table->dropColumn([
                'nationality',
                'epf_opt_in_60plus',
                'epf_employee_rate_override',
                'tax_no',
                'marital_status',
                'spouse_working',
                'children_relief_count',
                'disabled_self',
                'disabled_spouse',
                'zakat_monthly',
                'cp38_monthly',
            ]);
        });
    }
};
