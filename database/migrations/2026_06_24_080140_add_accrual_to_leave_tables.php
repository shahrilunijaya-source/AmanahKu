<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_types', function (Blueprint $table) {
            $table->decimal('monthly_accrual_days', 5, 2)->default(0)->after('entitlement');
            $table->decimal('max_balance', 6, 2)->nullable()->after('monthly_accrual_days');
            $table->decimal('max_carry_forward', 6, 2)->nullable()->after('max_balance');
        });

        Schema::table('leave_balances', function (Blueprint $table) {
            $table->date('last_accrued_on')->nullable()->after('balance');
        });
    }

    public function down(): void
    {
        Schema::table('leave_balances', function (Blueprint $table) {
            $table->dropColumn('last_accrued_on');
        });

        Schema::table('leave_types', function (Blueprint $table) {
            $table->dropColumn(['monthly_accrual_days', 'max_balance', 'max_carry_forward']);
        });
    }
};
