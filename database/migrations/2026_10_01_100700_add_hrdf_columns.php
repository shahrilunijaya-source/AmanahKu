<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Spec F7: HRD Corp levy. Payroll items carry their own liability flag (the levy's wage
 * base is basic pay plus fixed allowances), payslips store the computed employer levy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_items', function (Blueprint $t) {
            $t->boolean('hrdf_liable')->default(false)->after('prorate_on_incomplete_month');
        });
        DB::table('payroll_items')->whereIn('code', ['basic-salary', 'fixed-allowance'])->update(['hrdf_liable' => true]);

        Schema::table('payslips', function (Blueprint $t) {
            $t->decimal('hrdf_levy', 12, 2)->default(0)->after('eis_employer');
        });
    }

    public function down(): void
    {
        Schema::table('payroll_items', fn (Blueprint $t) => $t->dropColumn('hrdf_liable'));
        Schema::table('payslips', fn (Blueprint $t) => $t->dropColumn('hrdf_levy'));
    }
};
