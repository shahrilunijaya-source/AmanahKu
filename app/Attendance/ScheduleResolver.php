<?php

declare(strict_types=1);

namespace App\Attendance;

use App\Models\Employee;
use Carbon\CarbonInterface;

/**
 * Resolves which site an employee is expected to clock from on a given date, based on
 * their work arrangement:
 *   - office  → their branch geofence + office hours
 *   - client  → their assigned client site (resident engineers) + the client's hours
 *   - wfh     → their registered home + company hours
 *   - hybrid  → branch on configured office weekdays, home on the rest
 */
class ScheduleResolver
{
    public function resolve(Employee $employee, CarbonInterface $date): SiteSpec
    {
        $arrangement = $employee->work_arrangement ?: 'office';

        return match ($arrangement) {
            'client' => $this->clientSite($employee),
            'wfh' => $this->homeSite($employee),
            'hybrid' => in_array($date->isoWeekday(), $employee->hybrid_office_days ?? [], true)
                ? $this->officeSite($employee)
                : $this->homeSite($employee),
            default => $this->officeSite($employee),
        };
    }

    private function officeSite(Employee $employee): SiteSpec
    {
        $b = $employee->branch;

        return new SiteSpec(
            type: 'office',
            label: $b?->name ?? 'Office',
            latitude: $b?->latitude !== null ? (float) $b->latitude : null,
            longitude: $b?->longitude !== null ? (float) $b->longitude : null,
            radiusM: (int) ($b?->radius_m ?? 200),
            workStart: $this->hhmm($b?->work_start),
            workEnd: $this->hhmm($b?->work_end),
            minHours: $b?->min_hours !== null ? (float) $b->min_hours : null,
        );
    }

    private function clientSite(Employee $employee): SiteSpec
    {
        $s = $employee->workSite;

        return new SiteSpec(
            type: 'client',
            label: $s?->name ?? 'Client site',
            latitude: $s?->latitude !== null ? (float) $s->latitude : null,
            longitude: $s?->longitude !== null ? (float) $s->longitude : null,
            radiusM: (int) ($s?->radius_m ?? 200),
            workStart: $this->hhmm($s?->work_start),
            workEnd: $this->hhmm($s?->work_end),
            minHours: $s?->min_hours !== null ? (float) $s->min_hours : null,
        );
    }

    private function homeSite(Employee $employee): SiteSpec
    {
        $hasHome = $employee->home_latitude !== null && $employee->home_longitude !== null;

        // Company rule: every WFH day follows the single company-wide WFH hours set on the
        // Attendance Setup screen (tenant.wfh_*) — never the staff's own branch. Falls back
        // to the staff's branch only when the company hours haven't been set yet.
        $t = $employee->tenant;
        $b = $employee->branch;

        $minHours = $t?->wfh_min_hours ?? $b?->min_hours;

        return new SiteSpec(
            type: 'home',
            label: 'Work from home',
            latitude: $hasHome ? (float) $employee->home_latitude : null,
            longitude: $hasHome ? (float) $employee->home_longitude : null,
            radiusM: (int) ($t?->wfh_radius_m ?? 200),
            workStart: $this->hhmm($t?->wfh_work_start) ?? $this->hhmm($b?->work_start),
            workEnd: $this->hhmm($t?->wfh_work_end) ?? $this->hhmm($b?->work_end),
            minHours: $minHours !== null ? (float) $minHours : null,
            needsHomeCapture: ! $hasHome,
        );
    }

    /** Normalise a DB time value ('HH:MM:SS') to 'HH:MM', or null. */
    private function hhmm(?string $time): ?string
    {
        return $time ? substr($time, 0, 5) : null;
    }
}
