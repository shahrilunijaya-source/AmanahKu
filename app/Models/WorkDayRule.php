<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Carbon\CarbonInterface;
use Database\Factories\WorkDayRuleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A special work day: one ISO weekday that works on certain weeks of the month, with its own
 * hours. `weeks` holds ordinals 1-4 and -1 for "the last one". `counts` is `half` (leave costs
 * 0.5, timesheet capacity 50%) or `full` (1.0 / 100%). One rule per weekday per company.
 *
 * @property int $tenant_id
 * @property int $weekday
 * @property list<int> $weeks
 * @property string $start_time
 * @property string $end_time
 * @property string $counts
 */
class WorkDayRule extends Model
{
    /** @use HasFactory<WorkDayRuleFactory> */
    use BelongsToTenant, HasFactory;

    public const HALF = 'half';

    public const FULL = 'full';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'weekday' => 'integer',
            'weeks' => 'array',
        ];
    }

    /** True when the date is this rule's weekday on one of its listed weeks (or the last one). */
    public function matches(CarbonInterface $date): bool
    {
        if ((int) $date->dayOfWeekIso !== (int) $this->weekday) {
            return false;
        }

        $weeks = array_map('intval', (array) $this->weeks);

        if (in_array((int) ceil($date->day / 7), $weeks, true)) {
            return true;
        }

        return in_array(-1, $weeks, true) && $date->copy()->addDays(7)->month !== $date->month;
    }

    /** Length of the working window in hours, e.g. 09:00-13:00 is 4.0. */
    public function lengthInHours(): float
    {
        [$sh, $sm] = array_map('intval', explode(':', $this->start_time));
        [$eh, $em] = array_map('intval', explode(':', $this->end_time));

        return round((($eh * 60 + $em) - ($sh * 60 + $sm)) / 60, 2);
    }

    /** Timesheet capacity percent this rule gives its day: 50 for half, 100 for full. */
    public function capacity(): int
    {
        return $this->counts === self::FULL ? 100 : 50;
    }

    /** Start time as H:i. */
    public function startHhmm(): string
    {
        return substr($this->start_time, 0, 5);
    }

    /** End time as H:i. */
    public function endHhmm(): string
    {
        return substr($this->end_time, 0, 5);
    }
}
