<?php

declare(strict_types=1);

namespace App\Services\Payroll;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\IndividualTransaction;
use App\Models\PayrollItem;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;

/**
 * Spec F15: a raise recorded with an effective date inside a month that is already
 * finalized. Finalized payslips never change, so the shortfall for those months is
 * queued as one "Back pay" Individual Transaction into the next monthly run, where HR
 * can still edit or remove it.
 */
class BackPay
{
    /** @var list<string> Messages for the screen that saved the change. */
    private static array $notes = [];

    /**
     * Notes raised since the last call, then cleared.
     *
     * @return list<string>
     */
    public static function pullNotes(): array
    {
        [$notes, self::$notes] = [self::$notes, []];

        return $notes;
    }

    /** The notes as a tail for the saving screen's success message. Empty when there are none. */
    public static function noteSuffix(): string
    {
        $notes = self::pullNotes();

        return $notes === [] ? '' : ' '.implode(' ', $notes);
    }

    /** Queue back pay for a basic salary change. Null when there is nothing to pay. */
    public static function queue(Employee $employee, float $oldSalary, float $newSalary, string $effectiveOn): ?IndividualTransaction
    {
        $diff = round($newSalary - $oldSalary, 2);
        if ($diff === 0.0) {
            return null;
        }

        $effective = CarbonImmutable::parse($effectiveOn);
        $payslips = Payslip::where('employee_id', $employee->id)
            ->whereHas('payrollRun', fn ($run) => $run->where('status', 'finalized')->where('kind', 'monthly')
                ->where('period', '>=', $effective->format('Y-m')))
            ->with('payrollRun')->get()->sortBy(fn (Payslip $p) => $p->payrollRun->period)->values();
        if ($payslips->isEmpty()) {
            return null;
        }

        if ($diff < 0) {
            // Recovering paid wages is a deduction under EA s.24 and needs consent: HR's call.
            self::$notes[] = $employee->name.': pay cut is backdated into finalized months. Nothing was deducted; recover any overpayment by hand.';

            return null;
        }

        $amount = 0.0;
        $paid = $skipped = [];
        foreach ($payslips as $payslip) {
            $label = $payslip->payrollRun->label;
            if ($payslip->basic_overridden) {
                $skipped[] = $label;

                continue;
            }
            $inMonth = (int) ($payslip->days_in_month ?: $effective->daysInMonth);
            $employed = (int) ($payslip->days_employed ?: $inMonth);
            if ($payslip->payrollRun->period === $effective->format('Y-m')) {
                $employed = min($employed, $inMonth - $effective->day + 1);
            }
            $amount += Proration::prorate($diff, $employed, $inMonth);
            $paid[] = $label;
        }
        $amount = round($amount, 2);
        if ($amount <= 0) {
            return null;
        }

        $lastFinalized = PayrollRun::where('tenant_id', $employee->tenant_id)->where('status', 'finalized')
            ->where('kind', 'monthly')->max('period');
        $period = CarbonImmutable::parse($lastFinalized.'-01')->addMonth()->format('Y-m');

        $remark = 'Back pay '.implode(', ', $paid).' · RM '.number_format($oldSalary, 2).' to RM '.number_format($newSalary, 2)
            .($skipped ? ' · skipped (basic overridden): '.implode(', ', $skipped) : '')
            .($effective->year < (int) substr($period, 0, 4) ? ' · includes prior year arrears, check PCB' : '');

        $tx = IndividualTransaction::create([
            'employee_id' => $employee->id,
            'payroll_item_id' => self::item($employee)->id,
            'period' => $period,
            'amount' => $amount,
            'remarks' => mb_substr($remark, 0, 255),
            'created_by_id' => Auth::id(),
        ]);

        AuditLog::record('Queued back pay', $employee->name.' · '.$period.' · RM '.number_format($amount, 2));
        self::$notes[] = $employee->name.': back pay of RM '.number_format($amount, 2).' queued for the '.$period.' pay run.';

        return $tx;
    }

    private static function item(Employee $employee): PayrollItem
    {
        $find = fn () => PayrollItem::where('tenant_id', $employee->tenant_id)->where('code', 'back-pay')->first();
        if ($find() === null) {
            PayrollItem::seedFor(Tenant::findOrFail($employee->tenant_id));
        }

        return $find();
    }
}
