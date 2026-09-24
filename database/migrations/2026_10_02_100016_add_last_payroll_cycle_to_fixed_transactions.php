<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Worksy's "Last Amount Pay Cycle": the cycle a Fixed Transaction's last-month
        // amount is paid in. Null means the same cycle as the regular amount.
        Schema::table('fixed_transactions', function (Blueprint $t) {
            $t->string('last_payroll_cycle', 12)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('fixed_transactions', function (Blueprint $t) {
            $t->dropColumn('last_payroll_cycle');
        });
    }
};
