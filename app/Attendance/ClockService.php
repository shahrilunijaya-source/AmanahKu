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
 * enforcement for out-of-radius or early exits. Persists the attendance record.
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

        $site = $this->resolver->resolve($employee, $now);

        // First home / hybrid-home clock-in registers the home location and locks it.
        if ($site->type === 'home' && $site->needsHomeCapture && $lat !== null && $lng !== null) {
            $employee->update([
                'home_latitude' => $lat,
                'home_longitude' => $lng,
                'home_locked_at' => $now,
            ]);
            $site = $this->resolver->resolve($employee, $now);
        }

        $inRadius = $this->within($site, $lat, $lng);

        // Outside the geofence must be justified — never hard-blocked (bad GPS shouldn't strand staff).
        if ($inRadius === false && ! $this->filled($justification)) {
            return ['status' => 'needs_justification', 'message' => 'You appear to be outside '.$site->label.'. Add a reason to clock in.'];
        }

        $late = $this->isLate($site, $now);
        $flags = [];
        if ($late) {
            $flags[] = 'late';
        }
        if ($inRadius === false) {
            $flags[] = 'out_of_radius_in';
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
        ];
        if ($photoPath !== null) {
            $attributes['photo_path'] = $photoPath;
        }

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
        $record = $employee->attendanceRecords()->onDate($now)->first();
        if (! $record || ! $record->clock_in) {
            return ['status' => 'noop', 'message' => 'You have not clocked in yet today.'];
        }
        if ($record->clock_out) {
            return ['status' => 'noop', 'message' => 'Already clocked out today.'];
        }

        $site = $this->resolver->resolve($employee, $now);
        $outRadius = $this->within($site, $lat, $lng);
        $worked = $this->minutesBetween($record->clock_in, $now);
        $early = $this->isEarly($record->expected_end, $now);
        $short = $this->isShort($worked, $record->expected_min_hours);

        // Leaving the site early, off-site, or short of hours must be justified.
        if (($outRadius === false || $early || $short) && ! $this->filled($justification)) {
            return ['status' => 'needs_justification', 'message' => 'This clock-out looks early or off-site. Add a reason to clock out.'];
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

        $updates = [
            'clock_out' => $now->format('H:i:s'),
            'clock_out_latitude' => $lat,
            'clock_out_longitude' => $lng,
            'out_radius' => $outRadius,
            'clock_out_justification' => $this->filled($justification) ? $justification : null,
            'worked_minutes' => $worked,
            'flags' => array_values(array_unique($flags)),
        ];
        if ($photoPath !== null) {
            $updates['clock_out_photo_path'] = $photoPath;
        }

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

    private function isLate(SiteSpec $site, Carbon $now): bool
    {
        if (! $site->workStart) {
            return false;
        }

        return $now->gt($now->copy()->setTimeFromTimeString($site->workStart));
    }

    private function isEarly(?string $expectedEnd, Carbon $now): bool
    {
        if (! $expectedEnd) {
            return false;
        }

        return $now->lt($now->copy()->setTimeFromTimeString($expectedEnd));
    }

    private function isShort(?int $worked, mixed $minHours): bool
    {
        if ($worked === null || $minHours === null) {
            return false;
        }

        return $worked < (float) $minHours * 60;
    }

    private function minutesBetween(string $clockIn, Carbon $now): int
    {
        $start = $now->copy()->setTimeFromTimeString($clockIn);

        return (int) max(0, $start->diffInMinutes($now, false));
    }

    private function filled(?string $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }
}
