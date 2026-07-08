<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Employee;
use App\Models\WorkItem;
use App\Tenancy\CurrentTenant;
use App\Timesheet\TimesheetCompliance;
use Illuminate\Support\Collection;

/**
 * Real, tenant-scoped signals behind the "AI Workforce Intelligence" (workload) screen's
 * Recommended-actions panel and the manager dashboard's recs strip.
 *
 * Replaces the old canned Amanahku::managerRecs() copy. Each recommendation is derived from
 * live data and only appears when its condition actually holds, so an empty panel means the
 * workforce genuinely has nothing outstanding — not that the feature is unwired.
 *
 * The same query helpers feed both the recommendation TEXT (here) and the Apply action
 * (WorkforceController), so the count a manager reads and the number of people actually
 * nudged can never drift apart.
 */
class WorkforceInsights
{
    /** Memoised roster so overloaded()/available()/recommendations() share one query per instance. */
    private ?Collection $liveWorkload = null;

    /**
     * Active staff with their open (not-done) work-item count loaded, so the live workload
     * accessor resolves without an N+1. The single query behind overloaded()/available().
     * `reportsTo` is eager-loaded too so the rebalance nudge (WorkforceController) can read each
     * person's manager without firing a query per overloaded employee. Memoised per instance —
     * recommendations() calls overloaded() and available() back-to-back, which would otherwise
     * run this identical query twice on the dashboard/workload hot path.
     *
     * @return Collection<int, Employee>
     */
    private function withLiveWorkload(): Collection
    {
        return $this->liveWorkload ??= Employee::active()
            ->with('reportsTo')
            ->withCount(['workItems as open_items_count' => fn ($q) => $q->where('status', '!=', 'done')])
            ->get();
    }

    /** Overloaded staff (live red workload = open items past the overloaded threshold), heaviest first. */
    public function overloaded(): Collection
    {
        return $this->withLiveWorkload()
            ->filter(fn (Employee $e) => $e->workload === 'red')
            ->sortByDesc('open_items_count')
            ->values();
    }

    /** The most-available peer (live green workload, lightest load) work could shift to. */
    public function available(): ?Employee
    {
        return $this->withLiveWorkload()
            ->filter(fn (Employee $e) => $e->workload === 'green')
            ->sortBy('open_items_count')
            ->first();
    }

    /**
     * Work items past their structured due date and not yet done. Only items carrying a real
     * due_at count — free-text due_label cards ("Due tomorrow") have no comparable date.
     */
    public function overdueItems(): Collection
    {
        return WorkItem::whereNotNull('due_at')
            ->whereDate('due_at', '<', now()->toDateString())
            ->whereNotIn('status', ['done'])
            ->with('employee.reportsTo')
            ->get();
    }

    /** Active staff who have not completed THIS week's timesheet. */
    public function pendingTimesheets(): Collection
    {
        $svc = app(TimesheetCompliance::class);
        $tenant = app(CurrentTenant::class)->get();

        return $svc->pending($tenant, $svc->weekStart(now()));
    }

    /**
     * The recommendation cards. Order mirrors the old canned list (rebalance, escalate,
     * timesheet) but each is emitted only when its underlying signal is non-empty.
     *
     * @return list<array{type:string,t:string,impact:string}>
     */
    public function recommendations(): array
    {
        $recs = [];

        $overloaded = $this->overloaded();
        $available = $this->available();
        if ($overloaded->isNotEmpty() && $available) {
            $count = $overloaded->count();
            $recs[] = [
                'type' => 'rebalance',
                't' => "Rebalance work from {$overloaded->first()->name} to {$available->name}",
                'impact' => 'Resolves '.$count.' overload'.($count === 1 ? '' : 's'),
            ];
        }

        $overdue = $this->overdueItems();
        if ($overdue->isNotEmpty()) {
            $count = $overdue->count();
            $critical = $overdue->where('priority', 'high')->count();
            $recs[] = [
                'type' => 'overdue',
                't' => 'Escalate '.$count.' overdue item'.($count === 1 ? '' : 's').' to their managers',
                'impact' => $critical > 0
                    ? $critical.' critical item'.($critical === 1 ? '' : 's')
                    : $count.' overdue',
            ];
        }

        $pending = $this->pendingTimesheets();
        if ($pending->isNotEmpty()) {
            $count = $pending->count();
            $recs[] = [
                'type' => 'timesheet',
                't' => 'Send timesheet reminder to '.$count.' staff',
                'impact' => $count.' missing',
            ];
        }

        return $recs;
    }
}
