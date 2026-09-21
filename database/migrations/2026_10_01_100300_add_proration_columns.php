<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Spec F3: which items prorate on an incomplete month, and the day counts a payslip prints. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_items', function (Blueprint $table) {
            $table->boolean('prorate_on_incomplete_month')->default(false)->after('perkeso_liable');
        });
        DB::table('payroll_items')->whereIn('code', ['basic-salary', 'fixed-allowance'])->update(['prorate_on_incomplete_month' => true]);

        Schema::table('payslips', function (Blueprint $table) {
            $table->unsignedSmallInteger('days_employed')->nullable()->after('basic');
            $table->unsignedSmallInteger('days_in_month')->nullable()->after('days_employed');
            $table->boolean('basic_overridden')->default(false)->after('days_in_month');
        });
    }

    public function down(): void
    {
        Schema::table('payroll_items', fn (Blueprint $t) => $t->dropColumn('prorate_on_incomplete_month'));
        Schema::table('payslips', fn (Blueprint $t) => $t->dropColumn(['days_employed', 'days_in_month', 'basic_overridden']));
    }
};
