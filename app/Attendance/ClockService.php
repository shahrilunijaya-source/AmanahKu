<?php

declare(strict_types=1);

namespace App\Attendance;

use App\Models\Employee;
use App\Support\Geo;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Clock-in / clock-out business rules: geofence checks against the expected site,
 * punctuality (late / early / short hours), home auto-registration, and justification
 * enforcement for out-of-radius or early exits. A selfie is mandatory for every punch.
 * Persists the attendance record.
 */
class ClockService
{
    public function __construct(private ScheduleResolver $resolver) {}

    /**
     * @return array{status:string, message:string}
     */
    public function clockIn(Employee $employee, ?float $lat, ?float $lng, ?string $justification, ?string $photoPath, Carbon $now): array
    {
        $existing = $employee->attendanceRecords()->onDate($now)->first();
        if ($existing && $existing->clock_in) {
            return ['status' => 'noop', 'message' => 'Already clocked in today.'];
        }

        $assigned = $this->resolver->resolve($employee, $now);

        // Clock against whichever configured location the staff member is actually standing
        // in. Runs before home capture, so someone on a home day who walks into the office
        // is matched to the office instead of registering the office as their home.
        $site = $this->resolver->matchActualSite($employee, $assigned, $lat, $lng);

        // First home / hybrid-home clock-in registers the home location and locks it.
        if ($site === $assigned && $site->type === 'home' && $site->needsHomeCapture && $lat !== null && $lng !== null) {
            $employee->update([
                'home_latitude' => $lat,
                'home_longitude' => $lng,
                'home_locked_at' => $now,
            ]);
            $site = $this->resolver->resolve($employee, $now);
        }

        $inRadius = $this->within($site, $lat, $lng);

        // A punch with no coordinates at all is allowed, but never cheap: it costs a reason
        // and a permanent flag. Blocking it instead only pushed the day off-system into a
        // hand-keyed record with no GPS and no flag — a worse audit trail than a punch that
        // says plainly that location was unavailable.
        if (($lat === null || $lng === null) && ! $this->filled($justification)) {
            return ['status' => 'needs_justification', 'message' => 'Your location could not be read. Add a reason to clock in without it.'];
        }

        // Outside the geofence must be justified — never hard-blocked (bad GPS shouldn't strand staff).
        if ($inRadius === false && ! $this->filled($justification)) {
            return ['status' => 'needs_justification', 'message' => 'You appear to be outside '.$site->label.'. Add a reason to clock in.'];
        }

        $late = $this->isLate($site->workStart, $site->workEnd, $now, $employee->tenant->late_grace_minutes ?? 0);

        // Lateness was the one anomaly recorded in silence: the flag told HR that somebody
        // was late but never why, and gave the employee no moment to say so. It now costs
        // the same typed reason as an off-site or unlocatable punch. Placed after the fence
        // checks on purpose — someone both late and off-site is told about their location,
        // which is the part they can see, and the one reason they type covers both.
        if ($late && ! $this->filled($justification)) {
            return ['status' => 'needs_justification', 'message' => 'You are clocking in after '.$site->workStart.'. Add a reason to clock in.'];
        }

        // A selfie is mandatory for every clock-in now, on-site and on-time included — it
        // proves who actually punched, not only who was standing where.
        if ($photoPath === null) {
            if ($lat === null || $lng === null) {
                return ['status' => 'needs_photo', 'message' => 'Clocking in without location needs a selfie.'];
            }
            if ($inRadius === false) {
                return ['status' => 'needs_photo', 'message' => 'You are outside '.$site->label.'. Attach a selfie to clock in from here.'];
            }

            return ['status' => 'needs_photo', 'message' => 'Attach a selfie to clock in.'];
        }

        $flags = [];
        if ($late) {
            $flags[] = 'late';
        }
        if ($inRadius === false) {
            $flags[] = 'out_of_radius_in';
        }
        if ($lat === null || $lng === null) {
            $flags[] = 'no_location';
        }

        $attributes = [
            'clock_in' => $now->format('H:i:s'),
            'status' => $late ? 'late' : 'on_time',
            'type' => $site->attendanceType(),
            'location' => $site->label,
            'latitude' => $lat,
            'longitude' => $lng,
            'expected_site_type' => $site->type,
            'expected_start' => $site->workStart,
            'expected_end' => $site->workEnd,
            'expected_min_hours' => $site->minHours,
            'in_radius' => $inRadius,
            'clock_in_justification' => $this->filled($justification) ? $justification : null,
            'flags' => $flags,
            'photo_path' => $photoPath,
        ];

        // Two rapid taps can both pass the $existing check and race into the same
        // INSERT; the (employee_id, date) unique index rejects the loser. Treat that
        // exactly like the sequential double-tap: a harmless "already clocked in".
        try {
            $employee->attendanceRecords()->updateOrCreate(['date' => $now->toDateString()], $attributes);
        } catch (UniqueConstraintViolationException) {
            return ['status' => 'noop', 'message' => 'Already clocked in today.'];
        }

        return ['status' => 'ok', 'message' => 'Clocked in at '.$now->format('H:i').'.'];
    }

    /**
     * @return array{status:string, message:string}
     */
    public function clockOut(Employee $employee, ?float $lat, ?float $lng, ?string $justification, ?string $photoPath, Carbon $now): array
    {
        // Not onDate($now): a shift that crosses midnight (clock in 23:00, out 01:30) has
        // its open record dated *yesterday*, so looking up "today" found nothing and told
        // an employee mid-shift they had never clocked in. Look for the still-open punch
        // instead, bounded to yesterday-or-today so a genuinely forgotten clock-out from
        // days ago doesn't get attributed to whatever the employee is doing right now.
        $record = $employee->attendanceRecords()
            ->whereNotNull('clock_in')
            ->whereNull('clock_out')
            ->where('date', '>=', $now->copy()->subDay()->toDateString())
            ->orderByDesc('date')
            ->first();
        if (! $record) {
            return ['status' => 'noop', 'message' => 'You have not clocked in yet today.'];
        }

        $site = $this->resolver->matchActualSite($employee, $this->resolver->resolve($employee, $now), $lat, $lng);
        $outRadius = $this->within($site, $lat, $lng);
        $worked = $this->minutesBetween($record->date, $record->clock_in, $now);
        $early = $this->isEarly($record->expected_start, $record->expected_end, $now);
        $short = $this->isShort($worked, $record->expected_min_hours);

        // Same price as an unlocatable clock-in: a reason and a flag.
        if (($lat === null || $lng === null) && ! $this->filled($justification)) {
            return ['status' => 'needs_justification', 'message' => 'Your location could not be read. Add a reason to clock out without it.'];
        }

        // Leaving the site early, off-site, or short of hours must be justified.
        if (($outRadius === false || $early || $short) && ! $this->filled($justification)) {
            return ['status' => 'needs_justification', 'message' => 'This clock-out looks early or off-site. Add a reason to clock out.'];
        }

        // A selfie is mandatory for every clock-out now, not only the off-site one.
        if ($photoPath === null) {
            if ($lat === null || $lng === null) {
                return ['status' => 'needs_photo', 'message' => 'Clocking out without location needs a selfie.'];
            }
            if ($outRadius === false) {
                return ['status' => 'needs_photo', 'message' => 'You are outside '.$site->label.'. Attach a selfie to clock out from here.'];
            }

            return ['status' => 'needs_photo', 'message' => 'Attach a selfie to clock out.'];
        }

        $flags = $record->flags ?? [];
        if ($outRadius === false) {
            $flags[] = 'out_of_radius_out';
        }
        if ($early) {
            $flags[] = 'early_out';
        }
        if ($short) {
            $flags[] = 'short_hours';
        }
        if ($lat === null || $lng === null) {
            $flags[] = 'no_location';
        }

        $updates = [
            'clock_out' => $now->format('H:i:s'),
            'clock_out_latitude' => $lat,
            'clock_out_longitude' => $lng,
            'out_radius' => $outRadius,
            'clock_out_justification' => $this->filled($justification) ? $justification : null,
            'worked_minutes' => $worked,
            'flags' => array_values(array_unique($flags)),
            'clock_out_photo_path' => $photoPath,
        ];

        $record->update($updates);

        return ['status' => 'ok', 'message' => 'Clocked out at '.$now->format('H:i').'.'];
    }

    /** True/false inside the geofence, or null when no geofence or no GPS to judge. */
    private function within(SiteSpec $site, ?float $lat, ?float $lng): ?bool
    {
        if (! $site->hasGeofence() || $lat === null || $lng === null) {
            return null;
        }

        return Geo::distanceMeters($lat, $lng, $site->latitude, $site->longitude) <= $site->radiusM;
    }

    /**
     * $workStart/$workEnd describing an overnight window (e.g. 22:00-06:00, where the start
     * is numerically later than the end) need their boundary anchored onto the *correct*
     * calendar day relative to $now, not always $now's own date — otherwise a clock-in just
     * after midnight compares against a start that is still hours in the future, and reads
     * as on-time instead of hours late.
     */
    private function isLate(?string $workStart, ?string $workEnd, Carbon $now, int $graceMinutes = 0): bool
    {
        if (! $workStart) {
            return false;
        }

        $start = $now->copy()->setTimeFromTimeString($workStart);
        if ($workEnd && $this->overnight($workStart, $workEnd) && $this->minutesOfDay($now) < $this->toMinutes($workEnd)) {
            $start->subDay();
        }

        return $now->gt($start->addMinutes($graceMinutes));
    }

    /** Same overnight anchoring as isLate(), for the shift's end boundary instead of its start. */
    private function isEarly(?string $expectedStart, ?string $expectedEnd, Carbon $now): bool
    {
        if (! $expectedEnd) {
            return false;
        }

        $end = $now->copy()->setTimeFromTimeString($expectedEnd);
        if ($expectedStart && $this->overnight($expectedStart, $expectedEnd) && $this->minutesOfDay($now) >= $this->toMinutes($expectedStart)) {
            $end->addDay();
        }

        return $now->lt($end);
    }

    private function overnight(string $workStart, string $workEnd): bool
    {
        return $this->toMinutes($workStart) > $this->toMinutes($workEnd);
    }

    private function toMinutes(string $time): int
    {
        [$h, $m] = array_map('intval', explode(':', $time));

        return $h * 60 + $m;
    }

    private function minutesOfDay(Carbon $now): int
    {
        return $now->hour * 60 + $now->minute;
    }

    private function isShort(?int $worked, mixed $minHours): bool
    {
        if ($worked === null || $minHours === null) {
            return false;
        }

        return $worked < (float) $minHours * 60;
    }

    private function minutesBetween(Carbon $date, string $clockIn, Carbon $now): int
    {
        // Anchored to the record's own date, not $now's: for an overnight shift $now
        // has already rolled to the next calendar day, and anchoring there would put
        // "start" after "now" and clamp a real multi-hour shift to 0.
        $start = $date->copy()->setTimeFromTimeString($clockIn);

        return (int) max(0, $start->diffInMinutes($now, false));
    }

    private function filled(?string $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }
}
