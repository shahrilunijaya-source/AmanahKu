<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AppNotification;
use App\Models\Tenant;
use App\Tenancy\CurrentTenant;
use App\Timesheet\TimesheetCompliance;
use Illuminate\Console\Command;

/**
 * Friday 5pm timesheet reminder.
 *
 * For each tenant, bell-notifies every active staffer whose current-week
 * timesheet is not fully filled (a weekday below 100%). Tenant-aware like the
 * leave/digest commands: the active tenant is set per loop so AppNotification
 * rows are written under the correct tenant scope. Context is cleared at the end.
 *
 * Idempotent enough for cron retries — a duplicate bell is harmless and clears
 * when the user reads it; there is no dedupe table.
 */
class TimesheetReminder extends Command
{
    protected $signature = 'timesheet:remind';

    protected $description = 'Bell-notify staff whose current-week timesheet is not fully filled in (Friday 5pm reminder).';

    private const TITLE = 'Timesheet reminder';

    private const BODY = "Your timesheet for this week isn't fully filled in. Please complete it to 100% for each day.";

    public function handle(CurrentTenant $context, TimesheetCompliance $compliance): int
    {
        $weekStart = $compliance->weekStart(now());
        $url = route('app.screen', 'timesheets');
        $remindedTenants = 0;

        foreach (Tenant::query()->orderBy('id')->get() as $tenant) {
            $context->set($tenant);

            try {
                $userIds = $compliance->pending($tenant, $weekStart)
                    ->pluck('user_id')
                    ->filter()
                    ->values();

                if ($userIds->isEmpty()) {
                    continue;
                }

                AppNotification::sendMany($userIds, self::TITLE, self::BODY, $url);
                $remindedTenants++;
            } catch (\Throwable $e) {
                // This is a one-shot weekly reminder — isolate per-tenant failures so one
                // bad tenant doesn't silently skip everyone after it. Log and continue.
                report($e);
                $this->error("Timesheet reminder failed for tenant {$tenant->id}: {$e->getMessage()}");
            }
        }

        $context->set(null);

        $this->info("Timesheet reminder sent for {$remindedTenants} tenant(s).");

        return self::SUCCESS;
    }
}
