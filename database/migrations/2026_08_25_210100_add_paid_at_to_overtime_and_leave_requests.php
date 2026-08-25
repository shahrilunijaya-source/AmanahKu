<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Marks an approved overtime/unpaid-leave request as consumed by a finalized payslip —
     * same role as claims.paid_at. Pulling a request into a draft is tracked separately
     * (payslips.overtime_request_ids / unpaid_leave_request_ids, added in the next
     * migration) so a draft-only pull still blocks a second draft from also pulling it,
     * without prematurely marking the source request paid before the run is real.
     */
    public function up(): void
    {
        Schema::table('overtime_requests', function (Blueprint $table) {
            $table->timestamp('paid_at')->nullable()->after('decided_at');
        });

        Schema::table('leave_requests', function (Blueprint $table) {
            $table->timestamp('paid_at')->nullable()->after('rejected_at');
        });
    }

    public function down(): void
    {
        Schema::table('overtime_requests', function (Blueprint $table) {
            $table->dropColumn('paid_at');
        });

        Schema::table('leave_requests', function (Blueprint $table) {
            $table->dropColumn('paid_at');
        });
    }
};
