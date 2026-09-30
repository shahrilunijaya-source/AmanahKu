<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Employee;
use App\Services\Payroll\LifecycleNotices;

/**
 * Spec F11: opens the statutory notices off the employee record itself, so every write
 * path (hiring form, import, onboarding, EmploymentRecordService::resign, offboarding,
 * a plain profile edit) gets them without each one remembering to ask.
 */
class EmployeeLifecycleObserver
{
    public function __construct(private readonly LifecycleNotices $notices) {}

    public function created(Employee $employee): void
    {
        // Someone entered after they already left (the staff import's leaver rows): the
        // hiring and leaving notices were for the old system to file, not new to-dos.
        if ($employee->archived_at !== null) {
            return;
        }
        $this->notices->onHired($employee);
        if ($employee->last_working_day !== null) {
            $this->notices->onLastWorkingDaySet($employee);
        }
    }

    public function updated(Employee $employee): void
    {
        if ($employee->archived_at !== null && ! $employee->wasChanged('archived_at')) {
            return;
        }
        // wasChanged() works in `updated` — syncChanges() has already run by this point.
        if ($employee->wasChanged('joined_at')) {
            $this->notices->onHired($employee);
        }
        if ($employee->wasChanged('last_working_day')) {
            $this->notices->onLastWorkingDaySet($employee);
        }
    }
}
