<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\WorkItem;
use App\Support\ManagementMeeting;
use App\Tenancy\CurrentTenant;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * CR-34 scope 3b: every Friday 08:00 (Thursday when Friday is a public holiday), one
 * individual 'Update Track for management meeting' card per manager — PM/PE on a live
 * project, plus every active employee whose role is a configured attendee role. Not a
 * group task: each person is Primary Owner of their own card, closed only by dragging it
 * to Done themselves. Marked `source = 'management_meeting'` / `source_ref = <due date>`
 * so CR-19 auto-done and CR-14a awards can exclude it; (employee, source_ref) is unique,
 * so a second run the same day, or a later run once the trigger day has passed, adds
 * nothing.
 */
class CreateManagementMeetingTasks extends Command
{
    protected $signature = 'management:meeting-tasks';

    protected $description = 'Create the Friday "Update Track for management meeting" card for every manager and attendee.';

    public function handle(CurrentTenant $context, ManagementMeeting $meeting): int
    {
        $today = Carbon::now()->startOfDay();
        $made = 0;

        foreach (Tenant::query()->orderBy('id')->get() as $tenant) {
            $context->set($tenant);

            try {
                $made += $this->sweepTenant($today, $meeting);
            } catch (\Throwable $e) {
                report($e);
                $this->error("Management meeting tasks failed for tenant {$tenant->id}: {$e->getMessage()}");
            }
        }

        $context->set(null);
        $this->info("Management meeting cards created: {$made}.");

        return self::SUCCESS;
    }

    private function sweepTenant(Carbon $today, ManagementMeeting $meeting): int
    {
        $settings = $meeting->settings();
        if ($settings->isPausedOn($today) || ! $meeting->isTriggerDay($today, $settings)) {
            return 0;
        }

        $dueDate = $today->toDateString();
        $project = $meeting->project();
        $made = 0;

        foreach ($meeting->recipients($settings) as $person) {
            $exists = WorkItem::where('employee_id', $person->id)
                ->where('source', 'management_meeting')
                ->where('source_ref', $dueDate)
                ->exists();
            if ($exists) {
                continue;
            }

            WorkItem::create([
                'employee_id' => $person->id,
                'title' => 'Update Track for management meeting',
                'type' => 'task',
                'status' => 'todo',
                'priority' => 'medium',
                'due_at' => $dueDate,
                'project_id' => $project->id,
                'labels' => ['system'],
                'source' => 'management_meeting',
                'source_ref' => $dueDate,
                'progress' => 0,
                'sort_order' => (int) WorkItem::where('employee_id', $person->id)->where('status', 'todo')->max('sort_order') + 1,
            ]);
            $made++;
        }

        return $made;
    }
}
