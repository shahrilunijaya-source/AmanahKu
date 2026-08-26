<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Approved overtime and unpaid leave now populate a draft payslip automatically
     * (PayrollController::pullableOvertimeFor/pullableUnpaidLeaveFor), the same way
     * claim_ids already tracks pulled claims — see createRun()'s claims comment.
     *
     * - *_request_ids: which source rows this payslip has reserved, so a second draft run
     *   can never pull the same request (mirrors payslips.claim_ids exactly).
     * - pulled_overtime_hours / pulled_unpaid_days: the auto-computed figure, kept
     *   separately from the (possibly HR-overridden) overtime_hours/unpaid_days columns so
     *   the UI can always show "pulled: X" even after an override replaces the number used
     *   in the calculation.
     * - overtime_overridden / unpaid_days_overridden: true once HR has typed their own
     *   figure — distinguishes "pulled automatically" from "entered by hand" for display,
     *   without relying on fragile float-equality against the pulled figure.
     */
    public function up(): void
    {
        Schema::table('payslips', function (Blueprint $table) {
            $table->json('overtime_request_ids')->nullable()->after('overtime_amount');
            $table->decimal('pulled_overtime_hours', 6, 2)->default(0)->after('overtime_request_ids');
            $table->boolean('overtime_overridden')->default(false)->after('pulled_overtime_hours');
            $table->json('unpaid_leave_request_ids')->nullable()->after('unpaid_deduction');
            $table->decimal('pulled_unpaid_days', 5, 2)->default(0)->after('unpaid_leave_request_ids');
            $table->boolean('unpaid_days_overridden')->default(false)->after('pulled_unpaid_days');
        });
    }

    public function down(): void
    {
        Schema::table('payslips', function (Blueprint $table) {
            $table->dropColumn([
                'overtime_request_ids', 'pulled_overtime_hours', 'overtime_overridden',
                'unpaid_leave_request_ids', 'pulled_unpaid_days', 'unpaid_days_overridden',
            ]);
        });
    }
};
