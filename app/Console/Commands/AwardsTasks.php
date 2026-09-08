<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Employee;
use App\Models\Tenant;
use App\Models\WorkItem;
use App\Tenancy\CurrentTenant;
use App\Timesheet\DayRules;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * CR-14b "Auto-task to T.A.A.": on the last Monday of the month at 08:00, one
 * 'Nominate this month's awards' card per active employee with a user (due the month's
 * last working day) and one 'Select manual award winners' card per manager/management/
 * director (due the next month's first working day). Same per-person system-card shape
 * as CR-34's CreateManagementMeetingTasks: `source` = 'awards', `source_ref` =
 * 'YYYY-MM-nominate' / 'YYYY-MM-select', one card per person per source_ref so a second
 * run the same day adds nothing. These cards never earn an award — App\Support\Awards
 * excludes every card with a non-null `source`.
 */
class AwardsTasks extends Command
{
    protected $signature = 'awards:tasks';

    protected $description = "Create the monthly Nominate/Select award cards on the month's last Monday.";

    public function handle(CurrentTenant $context, DayRules $dayRules): int
    {
        $today = Carbon::now()->startOfDay();
        $made = 0;

        foreach (Tenant::query()->orderBy('id')->get() as $tenant) {
            $context->set($tenant);

            try {
                $made += $this->sweepTenant($today, $dayRules);
            } catch (\Throwable $e) {
                report($e);
                $this->error("Award tasks failed for tenant {$tenant->id}: {$e->getMessage()}");
            }
        }

        $context->set(null);
        $this->info("Award task cards created: {$made}.");

        return self::SUCCESS;
    }

    private function sweepTenant(Carbon $today, DayRules $dayRules): int
    {
        if (! $this->isLastMondayOfMonth($today)) {
            return 0;
        }

        $tenantId = app(CurrentTenant::class)->id();
        $monthKey = $today->format('Y-m');

        $lastWorkingDay = $today->copy()->endOfMonth();
        while (! $dayRules->isWorkingDay($lastWorkingDay)) {
            $lastWorkingDay->subDay();
        }

        $nextMonthFirstWorkingDay = $today->copy()->addMonthNoOverflow()->startOfMonth();
        while (! $dayRules->isWorkingDay($nextMonthFirstWorkingDay)) {
            $nextMonthFirstWorkingDay->addDay();
        }

        $made = 0;
        $made += $this->makeCards(
            Employee::where('tenant_id', $tenantId)->active()->whereNotNull('user_id')->get(),
            "{$monthKey}-nominate", "Nominate this month's awards", $lastWorkingDay,
        );

        $selectUserIds = DB::table('tenant_user')->where('tenant_id', $tenantId)
            ->whereIn('role', ['manager', 'management', 'director'])->pluck('user_id');
        $made += $this->makeCards(
            Employee::where('tenant_id', $tenantId)->active()->whereIn('user_id', $selectUserIds)->get(),
            "{$monthKey}-select", 'Select manual award winners', $nextMonthFirstWorkingDay,
        );

        return $made;
    }

    /** @param  Collection<int, Employee>  $people */
    private function makeCards(Collection $people, string $sourceRef, string $title, Carbon $dueAt): int
    {
        $made = 0;
        foreach ($people as $person) {
            $exists = WorkItem::where('employee_id', $person->id)->where('source', 'awards')->where('source_ref', $sourceRef)->exists();
            if ($exists) {
                continue;
            }

            WorkItem::create([
                'employee_id' => $person->id,
                'title' => $title,
                'type' => 'task',
                'status' => 'todo',
                'priority' => 'low',
                'due_at' => $dueAt->toDateString(),
                'labels' => ['system'],
                'source' => 'awards',
                'source_ref' => $sourceRef,
                'description' => "{$title} — head to /app/awards.",
                'progress' => 0,
                'sort_order' => (int) WorkItem::where('employee_id', $person->id)->where('status', 'todo')->max('sort_order') + 1,
            ]);
            $made++;
        }

        return $made;
    }

    private function isLastMondayOfMonth(Carbon $today): bool
    {
        if (! $today->isMonday()) {
            return false;
        }

        $lastMonday = $today->copy()->endOfMonth();
        while (! $lastMonday->isMonday()) {
            $lastMonday->subDay();
        }

        return $today->isSameDay($lastMonday);
    }
}
