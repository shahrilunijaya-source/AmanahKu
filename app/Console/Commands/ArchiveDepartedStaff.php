<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AppNotification;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\OffboardingCase;
use App\Models\Resignation;
use App\Models\Tenant;
use App\Services\OffboardingService;
use App\Services\StaffArchiver;
use App\Tenancy\CurrentTenant;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Auto-archive staff whose offboarding last working day has passed.
 *
 * The offboarding case is the departure record. Any in-progress case with a last_day strictly
 * before today means the person has left — so we archive them (full detach cascade via
 * StaffArchiver: reports move up-chain, pivots drop, open tasks pass to their manager, pending
 * requests close, login can no longer act), close the case, and close a linked resignation.
 * This covers EVERY reason (resignation, termination, end-of-contract, retirement), not just
 * resignations. Strictly before-today so a person is never detached on a day they still work.
 *
 * Self-heal: a legacy acknowledged resignation past its last day with no case (acknowledged
 * before auto-open existed) gets a case opened first, so no leaver is ever missed.
 *
 * Clearance is NEVER a gate — access cutoff must not wait on a forgotten checkbox. Instead a
 * case archived with unchecked items raises an HR notification + audit flag.
 *
 * Idempotent: an already-archived person is skipped by StaffArchiver, but their case/resignation
 * are still closed, so a re-run never double-processes.
 */
class ArchiveDepartedStaff extends Command
{
    protected $signature = 'staff:archive-departed';

    protected $description = 'Archive + detach staff whose offboarding last working day has passed.';

    public function handle(CurrentTenant $context, StaffArchiver $archiver, OffboardingService $offboarding): int
    {
        $today = Carbon::today()->toDateString();
        $archivedCount = 0;

        foreach (Tenant::query()->orderBy('id')->get() as $tenant) {
            $context->set($tenant);

            try {
                // Self-heal: legacy acknowledged resignations past their last day with no case.
                $caseless = Resignation::query()
                    ->where('status', 'acknowledged')
                    ->whereDate('last_working_date', '<', $today)
                    ->whereDoesntHave('offboardingCase')
                    ->with('employee')
                    ->get();

                foreach ($caseless as $resignation) {
                    if ($resignation->employee) {
                        $offboarding->openCase(
                            $resignation->employee,
                            $resignation->last_working_date,
                            'resignation',
                            'Auto-opened from resignation.',
                            $resignation,
                        );
                    }
                }

                // Primary sweep: every in-progress case whose last day has passed — all reasons.
                $due = OffboardingCase::query()
                    ->where('status', 'in_progress')
                    ->whereDate('last_day', '<', $today)
                    ->with(['employee', 'clearanceItems', 'resignation'])
                    ->get();

                foreach ($due as $case) {
                    $employee = $case->employee;
                    if (! $employee) {
                        continue;
                    }

                    if ($archiver->archive($employee)) {
                        $archivedCount++;
                    }

                    $case->update(['status' => 'completed', 'completed_at' => now()]);

                    if ($case->resignation && $case->resignation->status === 'acknowledged') {
                        $case->resignation->update(['status' => 'completed']);
                    }

                    $outstanding = $case->clearanceItems->where('done', false)->count();
                    if ($outstanding > 0) {
                        $this->flagOutstanding($tenant, $employee, $outstanding);
                    }
                }
            } catch (\Throwable $e) {
                // Per-tenant isolation (AK-REL-04): one tenant's failure must not abort the rest.
                report($e);
                $this->error("Tenant {$tenant->id}: {$e->getMessage()}");
            }
        }

        $context->set(null);

        $this->info("Archived {$archivedCount} departed staff.");

        return self::SUCCESS;
    }

    /** Notify management/HR + audit that a person was archived with clearance still open. */
    private function flagOutstanding(Tenant $tenant, Employee $employee, int $outstanding): void
    {
        $userIds = $tenant->users()->wherePivotIn('role', ['management', 'hr'])->pluck('users.id');

        AppNotification::sendMany(
            $userIds,
            'Offboarding: clearance outstanding',
            "{$employee->name} was archived with {$outstanding} clearance item(s) still open.",
            route('app.screen', 'offboarding'),
        );

        AuditLog::record('Archived with outstanding clearance', "{$employee->name} · {$outstanding} open");
    }
}
