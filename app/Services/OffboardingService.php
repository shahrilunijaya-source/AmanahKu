<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\OffboardingCase;
use App\Models\Resignation;
use Illuminate\Support\Carbon;

/**
 * Opens exit-clearance cases and seeds their standard checklist. The single path shared by the
 * HR "open case" action (OffboardingController::store), resignation acknowledgement
 * (ResignationController::acknowledge), and the archival self-heal (ArchiveDepartedStaff) — so
 * every departure, resignation or not, flows through one case. Caller owns authorization; the
 * BelongsToTenant trait fills tenant_id from the active CurrentTenant on create.
 */
class OffboardingService
{
    /**
     * The standard clearance checklist seeded with each new case: [department, title].
     *
     * @var list<array{0:string,1:string}>
     */
    public const STANDARD_CHECKLIST = [
        ['IT', 'Revoke system & email access'],
        ['IT', 'Collect laptop & devices'],
        ['HR', 'Conduct exit interview'],
        ['HR', 'Process final documentation'],
        ['Finance', 'Settle final salary & claims'],
        ['Finance', 'Recover company advances'],
        ['Manager', 'Knowledge handover sign-off'],
        ['Admin', 'Collect access card & keys'],
    ];

    /**
     * Open (or reuse) the employee's in-progress exit-clearance case. Idempotent:
     *  - a case already linked to $resignation is returned untouched;
     *  - an existing UNLINKED in-progress case for the employee is linked to $resignation and
     *    re-dated rather than duplicated;
     *  - otherwise a fresh case is created and the standard checklist seeded.
     */
    public function openCase(
        Employee $employee,
        Carbon|string $lastDay,
        string $reason,
        ?string $notes = null,
        ?Resignation $resignation = null,
    ): OffboardingCase {
        if ($resignation) {
            $linked = OffboardingCase::where('resignation_id', $resignation->id)->first();
            if ($linked) {
                return $linked;
            }
        }

        $existing = OffboardingCase::where('employee_id', $employee->id)
            ->where('status', 'in_progress')
            ->whereNull('resignation_id')
            ->first();

        if ($existing) {
            if ($resignation) {
                $existing->update([
                    'resignation_id' => $resignation->id,
                    'last_day' => $lastDay,
                    'reason' => $reason,
                ]);
            }

            return $existing;
        }

        $case = OffboardingCase::create([
            'employee_id' => $employee->id,
            'resignation_id' => $resignation?->id,
            'last_day' => $lastDay,
            'reason' => $reason,
            'status' => 'in_progress',
            'notes' => $notes,
        ]);

        foreach (self::STANDARD_CHECKLIST as $i => [$department, $title]) {
            $case->clearanceItems()->create([
                'department' => $department,
                'title' => $title,
                'done' => false,
                'sort' => $i,
            ]);
        }

        AuditLog::record('Opened offboarding', $employee->name);

        return $case;
    }
}
