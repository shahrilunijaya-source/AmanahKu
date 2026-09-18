<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Spec F10/F11: a leaver's final pay is held back while the CP22A is unsettled,
        // and released by hand once LHDN clears it. The release is stamped rather than
        // just flipped, so the payout screen can show who let the money go and when.
        Schema::table('payslips', function (Blueprint $t) {
            $t->boolean('held_for_cp22a')->default(false)->after('net_pay');
            $t->timestamp('hold_released_at')->nullable()->after('held_for_cp22a');
            $t->foreignId('hold_released_by_id')->nullable()->after('hold_released_at')->constrained('users')->nullOnDelete();
        });

        // Which run paid this employee out for good. A monthly run skips anyone carrying
        // one, and a second final run for the same person is refused.
        Schema::table('employees', function (Blueprint $t) {
            $t->foreignId('final_pay_run_id')->nullable()->after('last_working_day')->constrained('payroll_runs')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $t) {
            $t->dropConstrainedForeignId('final_pay_run_id');
        });

        Schema::table('payslips', function (Blueprint $t) {
            $t->dropConstrainedForeignId('hold_released_by_id');
            $t->dropColumn(['held_for_cp22a', 'hold_released_at']);
        });
    }
};
