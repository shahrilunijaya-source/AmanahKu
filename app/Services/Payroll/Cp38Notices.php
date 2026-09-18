<?php

declare(strict_types=1);

namespace App\Services\Payroll;

use App\Models\Employee;
use App\Models\PayrollCp38Notice;
use App\Models\Payslip;
use Illuminate\Support\Collection;

/**
 * Spec F9: works out how much CP38 a month should collect, and moves the running
 * balances when a run is finalized (never when a draft is built — a draft can be
 * rebuilt any number of times, so nothing outside it may move until finalize).
 */
final class Cp38Notices
{
    /**
     * Active notices covering $period ('YYYY-MM'), oldest notice first.
     *
     * @return Collection<int, PayrollCp38Notice>
     */
    public function activeFor(Employee $employee, string $period): Collection
    {
        return PayrollCp38Notice::where('tenant_id', $employee->tenant_id)
            ->where('employee_id', $employee->id)
            ->where('status', 'active')
            ->where('first_period', '<=', $period)
            ->where(fn ($q) => $q->whereNull('last_period')->orWhere('last_period', '>=', $period))
            ->orderBy('first_period')->orderBy('id')
            ->get();
    }

    /** What this month should deduct: each notice gives its instalment, or what is left of it. */
    public function instalmentFor(Employee $employee, string $period): float
    {
        $total = 0.0;
        foreach ($this->activeFor($employee, $period) as $notice) {
            $total += $this->dueFrom($notice);
        }

        return round($total, 2);
    }

    /** Collect the payslip's CP38 from the notices, oldest first, recording what each gave. */
    public function applyFinalized(Payslip $payslip): void
    {
        $employee = $payslip->employee;
        $run = $payslip->payrollRun;
        if ($employee === null || $run === null) {
            return;
        }
        $left = round((float) $payslip->cp38, 2);
        if ($left <= 0) {
            return;
        }

        $applied = [];
        foreach ($this->activeFor($employee, $run->period) as $notice) {
            if ($left <= 0) {
                break;
            }
            $take = min($this->dueFrom($notice), $left);
            if ($take <= 0) {
                continue;
            }
            $applied[(string) $notice->id] = round($take, 2);
            $left = round($left - $take, 2);
            $balance = $notice->remaining_balance;
            if ($balance !== null) {
                $balance = round($balance - $take, 2);
                $notice->remaining_balance = $balance;
                if ($balance <= 0) {
                    $notice->status = 'completed';
                }
            }
            $notice->save();
        }

        $payslip->forceFill(['cp38_applied' => $applied ?: null])->save();
    }

    /** Undo applyFinalized() exactly, from what the payslip recorded. */
    public function reverseFinalized(Payslip $payslip): void
    {
        $applied = $payslip->cp38_applied;
        if (! is_array($applied) || $applied === []) {
            return;
        }
        foreach ($applied as $noticeId => $amount) {
            $notice = PayrollCp38Notice::where('tenant_id', $payslip->tenant_id)->find((int) $noticeId);
            if ($notice === null) {
                continue;
            }
            if ($notice->remaining_balance !== null) {
                $notice->remaining_balance = round($notice->remaining_balance + (float) $amount, 2);
            }
            if ($notice->status === 'completed') {
                $notice->status = 'active';
            }
            $notice->save();
        }
        $payslip->forceFill(['cp38_applied' => null])->save();
    }

    /** An open-ended notice always gives its full instalment; a closed one gives what is left. */
    private function dueFrom(PayrollCp38Notice $notice): float
    {
        $balance = $notice->remaining_balance;

        return round(max(0.0, $balance !== null ? min($notice->monthly_instalment, $balance) : $notice->monthly_instalment), 2);
    }
}
