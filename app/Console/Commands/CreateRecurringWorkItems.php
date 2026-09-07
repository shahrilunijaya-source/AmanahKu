<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\PublicHoliday;
use App\Models\RecurringTask;
use App\Models\RecurringTaskOccurrence;
use App\Models\Tenant;
use App\Models\WorkItem;
use App\Tenancy\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * CR-18: the recurring task engine. Runs daily. For every schedule that is not paused,
 * finds the latest period whose creation day has come (a public holiday on that day
 * moves it to the next working day) and, if that period has no occurrence yet, makes the card: owner resolved from the role,
 * tagged people as helpers, due at the end of the period, subtasks from the template.
 * The (schedule, period) unique key means a second run the same day does nothing, and a
 * period skipped by HR (an occurrence with no card) is never created.
 */
class CreateRecurringWorkItems extends Command
{
    protected $signature = 'work:recurring {--on= : Local only: run as if today were this date}';

    protected $description = 'Create the work cards that recurring schedules are due to spawn today.';

    public function handle(CurrentTenant $context): int
    {
        if ($this->option('on') && app()->isLocal()) {
            Carbon::setTestNow(Carbon::parse($this->option('on')));
        }

        $today = CarbonImmutable::now()->startOfDay();
        $made = 0;

        foreach (Tenant::query()->orderBy('id')->get() as $tenant) {
            $context->set($tenant);

            try {
                $made += $this->sweepTenant($today);
            } catch (\Throwable $e) {
                report($e);
                $this->error("Recurring tasks failed for tenant {$tenant->id}: {$e->getMessage()}");
            }
        }

        $context->set(null);
        $this->info("Recurring cards created: {$made}.");

        return self::SUCCESS;
    }

    private function sweepTenant(CarbonImmutable $today): int
    {
        $made = 0;
        foreach (RecurringTask::query()->whereNull('paused_at')->orderBy('id')->get() as $schedule) {
            $period = $schedule->latestPeriodDueOn($today);
            if ($period === null || $this->creationDay($schedule, $period)->gt($today)) {
                continue;
            }
            if ($schedule->occurrences()->whereDate('period', $period->toDateString())->exists()) {
                continue;
            }

            $owner = $schedule->resolveOwner();
            if ($owner === null) {
                $this->warn("Schedule {$schedule->id} ({$schedule->title}) has no active owner; nothing created for {$period->toDateString()}.");

                continue;
            }

            $this->createCard($schedule, $period, $owner);
            $made++;
        }

        return $made;
    }

    private function createCard(RecurringTask $schedule, CarbonImmutable $period, Employee $owner): void
    {
        $due = $schedule->dueFor($period)->toDateString();

        $card = WorkItem::create([
            'tenant_id' => $schedule->tenant_id,
            'employee_id' => $owner->id,
            'title' => $schedule->title,
            'type' => 'task',
            'priority' => $schedule->priority ?: 'medium',
            'due_at' => $due,
            'project_id' => $schedule->project_id,
            'labels' => [RecurringTask::LABEL],
            'status' => 'todo',
            'progress' => 0,
            'sort_order' => (int) WorkItem::where('employee_id', $owner->id)->where('status', 'todo')->max('sort_order') + 1,
        ]);

        $helpers = Employee::active()
            ->where('tenant_id', $schedule->tenant_id)
            ->whereIn('id', array_map('intval', $schedule->tagged_employee_ids ?? []))
            ->where('id', '!=', $owner->id)
            ->pluck('id');
        if ($helpers->isNotEmpty()) {
            $card->participants()->sync($helpers->mapWithKeys(fn (int $id) => [$id => ['role' => 'helper']])->all());
            AuditLog::change($card, 'participants', [], $helpers->map(fn (int $id) => $id.':helper')->values()->all());
        }

        foreach ($schedule->subtasks ?? [] as $i => $title) {
            $card->children()->create([
                'tenant_id' => $schedule->tenant_id,
                'employee_id' => $owner->id,
                'title' => $title,
                'type' => 'task',
                'priority' => $schedule->priority ?: 'medium',
                'due_at' => $due,
                'project_id' => $schedule->project_id,
                'status' => 'todo',
                'progress' => 0,
                'sort_order' => $i + 1,
            ]);
        }

        RecurringTaskOccurrence::create([
            'tenant_id' => $schedule->tenant_id,
            'recurring_task_id' => $schedule->id,
            'period' => $period->toDateString(),
            'work_item_id' => $card->id,
        ]);
    }

    /**
     * The day a period's card is made: the period start, brought forward by the lead
     * days. A public holiday on that day pushes it to the next working day (CR-18 item
     * 3d); a plain weekend does not, the card simply waits on the board until Monday.
     */
    private function creationDay(RecurringTask $schedule, CarbonImmutable $period): CarbonImmutable
    {
        $day = $period->subDays($schedule->lead_days);
        if (! $this->isPublicHoliday($day)) {
            return $day;
        }
        while ($day->isWeekend() || $this->isPublicHoliday($day)) {
            $day = $day->addDay();
        }

        return $day;
    }

    private function isPublicHoliday(CarbonImmutable $day): bool
    {
        return PublicHoliday::whereDate('date', $day->toDateString())->exists();
    }
}
