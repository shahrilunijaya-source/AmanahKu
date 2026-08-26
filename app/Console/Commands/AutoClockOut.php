<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Attendance\ClockService;
use App\Models\AppNotification;
use App\Models\AttendanceRecord;
use App\Models\Tenant;
use App\Tenancy\CurrentTenant;
use Illuminate\Console\Command;

/**
 * Nightly sweep that closes punches nobody clocked out of.
 *
 * Unijaya credits no time past the shift end, so a forgotten clock-out capped at that end
 * costs the employee nothing — and leaving it open costs them the whole day: an open record
 * carries no worked minutes into the ledger, and ClockService::clockOut() stops offering it
 * once it is more than a day old, so the employee cannot rescue it themselves either.
 *
 * The stamped time is always the shift end, never the moment this runs, and the row carries
 * an `auto_out` flag so the ledger never passes it off as a real punch. HR can still type a
 * truer time over it through the admin amend screen, which marks it `amended`.
 *
 * Records back to yesterday are in range, not just today's: an overnight shift is still
 * inside its own hours at 23:59 on the night it started, and only becomes closable on the
 * following night's run, by which time its record is dated yesterday.
 *
 * A punch opened on a weekend or public holiday is closed like any other. The reminder
 * command stays quiet on those days because nudging someone with nothing to clock is noise,
 * but somebody who did clock in that day has a real record, and it deserves the same close.
 */
class AutoClockOut extends Command
{
    protected $signature = 'attendance:auto-clock-out';

    protected $description = 'Close attendance punches left open past their shift end, stamped at the shift end.';

    private const TITLE = 'We clocked you out';

    private const BODY = 'You were still clocked in after your shift ended, so we closed it at your shift end time. Tell HR if that is wrong.';

    public function handle(CurrentTenant $context, ClockService $clock): int
    {
        $now = now();
        $url = route('app.screen', 'attendance');
        $closed = 0;

        foreach (Tenant::query()->orderBy('id')->get() as $tenant) {
            $context->set($tenant);

            try {
                $records = AttendanceRecord::query()
                    ->whereNotNull('clock_in')
                    ->whereNull('clock_out')
                    ->whereNotNull('expected_end')
                    ->where('date', '>=', $now->copy()->subDay()->toDateString())
                    ->with('employee')
                    ->get();

                foreach ($records as $record) {
                    if (! $clock->closeAtShiftEnd($record, $now)) {
                        continue;
                    }

                    $closed++;

                    // The close is the point; the bell is a courtesy. Staff with no login
                    // account still get their punch closed, they just have nowhere to read
                    // about it.
                    AppNotification::send(
                        $record->employee?->user_id,
                        self::TITLE,
                        self::BODY,
                        $url,
                        'attendance-auto-out-'.$record->id,
                    );
                }
            } catch (\Throwable $e) {
                // Isolate per-tenant failures so one bad tenant does not silently skip
                // everyone after it (AK-REL-04). Log and carry on.
                report($e);
                $this->error("Auto clock-out failed for tenant {$tenant->id}: {$e->getMessage()}");
            }
        }

        $context->set(null);

        $this->info("Punches closed at shift end: {$closed}.");

        return self::SUCCESS;
    }
}
