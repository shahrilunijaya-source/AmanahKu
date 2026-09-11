<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AppNotification;
use App\Models\Employee;
use App\Models\PublicHoliday;
use App\Models\Tenant;
use App\Support\DashboardBands;
use App\Tenancy\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Daily 8 AM sweep (CR-13): tell every active colleague — except the celebrant —
 * about today's birthday(s), including the advance case (celebrated early on the
 * last working day before a weekend/holiday). Private employees never appear
 * here. Every send is deduped by the real birthday date, so a cron retry the
 * same day is a no-op — same pattern as tot:remind.
 */
class BirthdayNotify extends Command
{
    protected $signature = 'birthday:notify';

    protected $description = "Notify colleagues about today's birthday(s), not the celebrant.";

    public function handle(CurrentTenant $context): int
    {
        $today = CarbonImmutable::now()->startOfDay();
        $sent = 0;

        foreach (Tenant::query()->orderBy('id')->get() as $tenant) {
            $context->set($tenant);

            try {
                $sent += $this->sweepTenant($today);
            } catch (\Throwable $e) {
                report($e);
                $this->error("Birthday notify failed for tenant {$tenant->id}: {$e->getMessage()}");
            }
        }

        $context->set(null);

        $this->info("Birthday notifications sent: {$sent}.");

        return self::SUCCESS;
    }

    private function sweepTenant(CarbonImmutable $today): int
    {
        $isWorkingDay = fn (CarbonImmutable $day): bool => ! $day->isWeekend()
            && ! PublicHoliday::whereDate('date', $day->toDateString())->exists();

        $celebratedDates = DashboardBands::celebratedOn($today, $isWorkingDay);
        $monthDayPairs = collect($celebratedDates)->map(fn (CarbonImmutable $d) => [$d->month, $d->day]);

        $celebrants = Employee::active()->where('birthday_private', false)->whereNotNull('date_of_birth')
            ->where(function ($q) use ($monthDayPairs) {
                foreach ($monthDayPairs as [$month, $day]) {
                    $q->orWhere(fn ($sub) => $sub->whereMonth('date_of_birth', $month)->whereDay('date_of_birth', $day));
                }
            })
            ->get();

        if ($celebrants->isEmpty()) {
            return 0;
        }

        $everyone = Employee::active()->whereNotNull('user_id')->pluck('user_id', 'id');
        $sent = 0;
        $url = route('app.screen', 'dash');

        foreach ($celebrants as $celebrant) {
            $on = null;
            foreach ($celebratedDates as $date) {
                if ((int) $celebrant->date_of_birth->format('n') === $date->month
                    && (int) $celebrant->date_of_birth->format('j') === $date->day) {
                    $on = $date;

                    break;
                }
            }
            if ($on === null) {
                continue;
            }

            $name = $celebrant->display_name;
            $title = $on->isSameDay($today)
                ? "Today is {$name}'s birthday \u{2013} send a wish"
                : "{$name}'s birthday is on {$on->format('D j M')} \u{2013} send a wish early";
            $dedupeKey = "bday-{$celebrant->id}-{$on->toDateString()}";

            foreach ($everyone as $employeeId => $userId) {
                if ($employeeId === $celebrant->id) {
                    continue;
                }
                $sent += (int) AppNotification::send($userId, $title, null, $url, $dedupeKey);
            }
        }

        return $sent;
    }
}
