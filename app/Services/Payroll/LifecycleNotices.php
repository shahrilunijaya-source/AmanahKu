<?php

declare(strict_types=1);

namespace App\Services\Payroll;

use App\Models\Employee;
use App\Models\PayrollNotice;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Spec F11: opens the statutory notices a hire or a leaving date triggers, and says when
 * a final pay run must be held back pending LHDN clearance.
 *
 * Due dates: LHDN gives 30 days from the start of employment for a CP22, and requires a
 * CP22A not less than 30 days before the employee's last day. PERKESO Form 2 and the
 * KWSP registration follow the same one-month-from-hire deadline.
 */
final class LifecycleNotices
{
    /** Days LHDN / PERKESO / KWSP allow after a hire. */
    private const int HIRE_WINDOW_DAYS = 30;

    /** Days before the last working day a CP22A is due. */
    private const int CESSATION_LEAD_DAYS = 30;

    /** How long the final pay may be held for clearance once the CP22A is filed. */
    private const int CLEARANCE_HOLD_DAYS = 90;

    public function onHired(Employee $employee): void
    {
        if ($employee->joined_at === null) {
            return;
        }
        $due = CarbonImmutable::parse($employee->joined_at)->addDays(self::HIRE_WINDOW_DAYS)->toDateString();

        foreach (['cp22', 'socso_form2', 'kwsp_registration'] as $type) {
            $this->open($employee, $type, $due);
        }
    }

    /**
     * A CP22A is due 30 days before the last working day, but never in the past — HR who
     * learns of a leaving date late still gets a due date they can act on. An unfiled
     * notice follows the date as it moves (and goes away if it is cleared); a filed one
     * is history and is left exactly as it is.
     */
    public function onLastWorkingDaySet(Employee $employee): void
    {
        $open = $this->query($employee, 'cp22a')->whereNull('filed_on')->first();

        $lastDay = $employee->last_working_day;
        if ($lastDay === null) {
            if ($open !== null) {
                $open->delete();
            }

            return;
        }

        $due = CarbonImmutable::parse($lastDay)->subDays(self::CESSATION_LEAD_DAYS);
        $today = CarbonImmutable::parse(now()->toDateString());
        $dueOn = ($due->lt($today) ? $today : $due)->toDateString();

        if ($open !== null) {
            $open->forceFill(['due_on' => $dueOn])->save();

            return;
        }

        $this->open($employee, 'cp22a', $dueOn);
    }

    /**
     * Opens one notice if it is not already there. Written out rather than firstOrCreate
     * because tenant_id is not fillable, and this runs from a model observer where no
     * tenant context is set (a seeder, a console command, a test).
     */
    public function open(Employee $employee, string $type, string $dueOn): PayrollNotice
    {
        $existing = $this->query($employee, $type)->where('due_on', $dueOn)->first();
        if ($existing !== null) {
            return $existing;
        }

        $notice = new PayrollNotice;
        $notice->forceFill([
            'tenant_id' => $employee->tenant_id,
            'employee_id' => $employee->id,
            'type' => $type,
            'due_on' => $dueOn,
        ])->save();

        return $notice;
    }

    /** @return Builder<PayrollNotice> */
    private function query(Employee $employee, string $type)
    {
        return PayrollNotice::withoutGlobalScopes()
            ->where('tenant_id', $employee->tenant_id)
            ->where('employee_id', $employee->id)
            ->where('type', $type);
    }

    /**
     * Spec F10/F11: a leaver's final pay is held until LHDN clears the CP22A, or until
     * 90 days have passed since it was filed and no clearance arrived.
     */
    public function holdsFinalPay(Employee $employee): bool
    {
        $notice = $this->query($employee, 'cp22a')->whereNull('cleared_on')->orderByDesc('due_on')->first();

        if ($notice === null) {
            return false;
        }
        if ($notice->filed_on === null) {
            return true;
        }

        return CarbonImmutable::parse($notice->filed_on)->addDays(self::CLEARANCE_HOLD_DAYS)->isFuture();
    }

    /**
     * The particulars every one of these forms asks for, so HR can copy them straight
     * onto the agency's own form.
     *
     * @return array<string, string>
     */
    public function prefill(PayrollNotice $notice): array
    {
        $employee = $notice->employee;
        if ($employee === null) {
            return [];
        }
        $structure = $employee->salaryStructure;

        return array_map(fn ($v) => (string) $v, array_filter([
            'Name' => $employee->name,
            'NRIC' => $employee->nric,
            'Staff ID' => $employee->staff_id,
            'Income tax number' => $structure !== null ? $structure->tax_no : null,
            'EPF number' => $structure !== null ? $structure->epf_no : null,
            'SOCSO number' => $structure !== null ? $structure->socso_no : null,
            'Address' => $employee->address,
            'Joined on' => $employee->joined_at !== null ? $employee->joined_at->toDateString() : null,
            'Last working day' => $employee->last_working_day !== null ? $employee->last_working_day->toDateString() : null,
            'Monthly salary' => $employee->salary !== null ? number_format((float) $employee->salary, 2) : null,
        ], fn ($v) => $v !== null && $v !== ''));
    }
}
