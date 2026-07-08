<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Position;
use App\Models\Timesheet;
use App\Models\TimesheetEntry;

/**
 * Resolves manday/manhour charge-out rates and timesheet costs from an
 * employee's position band. Kept out of controllers/blade so the costing rule
 * is unit-testable in isolation. Constants live in config/manday.php.
 */
class MandayRateService
{
    /** Daily charge-out rate for a position band. */
    public function mandayRate(Position $position): float
    {
        return $position->mandayRate();
    }

    /** Hourly charge-out rate for a position band. */
    public function manhourRate(Position $position): float
    {
        return $position->manhourRate();
    }

    /**
     * Total RM cost of a timesheet = total hours * the owner's manhour rate.
     * Returns null when the employee has no position assigned — there is no band
     * to cost against, and callers must surface that as "rate not set" rather
     * than silently treating it as zero.
     */
    public function timesheetCost(Timesheet $timesheet): ?float
    {
        $position = $timesheet->employee?->positionBand;

        if (! $position) {
            return null;
        }

        return round((float) $timesheet->total_hours * $position->manhourRate(), 2);
    }

    /** RM cost of a single entry = entry hours * the band's manhour rate. */
    public function entryCost(TimesheetEntry $entry, Position $position): float
    {
        return round((float) $entry->hours * $position->manhourRate(), 2);
    }
}
