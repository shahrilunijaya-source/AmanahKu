<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Attendance\HolidayEve;
use App\Models\AppNotification;
use App\Models\Employee;
use App\Models\Tenant;
use App\Tenancy\CurrentTenant;
use Illuminate\Console\Command;

/**
 * The 5:30 PM holiday-eve bell (CR-20) for everyone who did not clock out: on leave,
 * never clocked in, or still at their desk. Same dedupe key as the clock-out greeting,
 * so someone who already saw the full-screen card is not told twice. Runs every
 * weekday; on a day that is not a holiday eve it does nothing.
 */
class HolidayEveGreeting extends Command
{
    protected $signature = 'attendance:holiday-eve';

    protected $description = 'Send the holiday-eve greeting to staff who have not clocked out today.';

    public function handle(CurrentTenant $context, HolidayEve $eves): int
    {
        $now = now();
        $sent = 0;

        foreach (Tenant::query()->orderBy('id')->get() as $tenant) {
            $context->set($tenant);

            try {
                $eve = $eves->forDay($now);
                if ($eve === null) {
                    continue;
                }
                $payload = $eves->payload($eve['holiday'], $eve['next_working_day']);

                $staff = Employee::query()->active()->whereNotNull('user_id')->get();
                foreach ($staff as $employee) {
                    $sent += (int) AppNotification::send(
                        $employee->user_id,
                        $payload['name'].' · '.$payload['greeting_en'],
                        'See you on '.$eve['next_working_day']->format('l j M').'.',
                        route('app.screen', 'attendance'),
                        HolidayEve::dedupeKey($payload['holiday_id']),
                    );
                }
            } finally {
                $context->set(null);
            }
        }

        $this->info("Holiday-eve greetings sent: {$sent}");

        return self::SUCCESS;
    }
}
