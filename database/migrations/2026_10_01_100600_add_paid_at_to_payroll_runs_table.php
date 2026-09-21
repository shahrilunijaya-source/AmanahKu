<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Spec F5: when the run was actually paid, and why a pay date past EA s.19's seventh day was allowed. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_runs', function (Blueprint $table) {
            $table->timestamp('paid_at')->nullable()->after('finalized_at');
            $table->string('pay_date_override_reason', 240)->nullable()->after('payment_date');
        });
    }

    public function down(): void
    {
        Schema::table('payroll_runs', function (Blueprint $table) {
            $table->dropColumn(['paid_at', 'pay_date_override_reason']);
        });
    }
};
