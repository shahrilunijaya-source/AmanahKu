<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Spec F13: finalizing a run and letting staff see their payslips become two steps, so
 * HR can close the figures, check the bank file, and only then release the payslips.
 * Runs already finalized are backfilled as published — nothing disappears for staff who
 * can see their payslip today.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_runs', function (Blueprint $t) {
            $t->timestamp('published_at')->nullable()->after('finalized_at');
        });

        DB::table('payroll_runs')->where('status', 'finalized')->whereNull('published_at')
            ->update(['published_at' => DB::raw('finalized_at')]);
    }

    public function down(): void
    {
        Schema::table('payroll_runs', function (Blueprint $t) {
            $t->dropColumn('published_at');
        });
    }
};
