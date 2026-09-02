<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Unpaid leave has no quota — HR confirmed it is not an entitlement anyone is
     * allotted, it is simply salary not paid for a day not worked. The standard set
     * has always seeded it at 0 (LeaveSetupController::loadStandardTypes), but a
     * tenant edited it to 5 by hand and every employee then picked up a balance row.
     * Those rows make approvals decrement a quota that should not exist, and split
     * an over-quota unpaid request into a second unpaid request.
     *
     * Matched on is_unpaid, not the name — see 2026_08_25_210000.
     */
    public function up(): void
    {
        $unpaidIds = DB::table('leave_types')->where('is_unpaid', true)->pluck('id');

        if ($unpaidIds->isEmpty()) {
            return;
        }

        DB::table('leave_types')->whereIn('id', $unpaidIds)->update(['entitlement' => 0]);
        DB::table('leave_balances')->whereIn('leave_type_id', $unpaidIds)->delete();
    }

    /**
     * Deliberately a no-op. The deleted balance rows recorded a quota that was never
     * real and cannot be reconstructed, and 0 is what the standard set seeds anyway —
     * writing an entitlement back would hand the bug to every tenant that never had it.
     */
    public function down(): void {}
};
