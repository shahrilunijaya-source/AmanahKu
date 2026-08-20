<?php

declare(strict_types=1);

namespace App\Attendance;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\WorkSite;
use App\Support\Geo;
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

    /**
     * Where the staff member actually is, out of every location HR has configured.
     *
     * Staff never pick their site from a list — being near a company location is not the
     * same as working there, and a free choice would let anyone living near any branch
     * clock "on-site" forever. Instead the GPS fix is matched against every branch and
     * client site in the tenant, and the one it lands inside wins (nearest, if several
     * overlap). Falls back to the assigned site when the fix is inside none of them.
     *
     * The matched site contributes only its geofence and label. Working hours stay those
     * of the employee's own arrangement, so visiting a client on an 08:30 shift does not
     * make a 09:00 office worker late.
     *
     * The work-mode pill does not weaken this: it declares *intent* ("today is a customer
     * visit"), never a site. The GPS is still measured against the same resolved site in both
     * modes — see docs/superpowers/specs/2026-08-19-attendance-work-mode-pill-design.md.
     */
    public function matchActualSite(Employee $employee, SiteSpec $assigned, ?float $lat, ?float $lng): SiteSpec
    {
        if ($lat === null || $lng === null) {
            return $assigned;
        }

        // Already standing at the expected site — the common case, and no query for it.
        if ($assigned->hasGeofence()
            && Geo::distanceMeters($lat, $lng, $assigned->latitude, $assigned->longitude) <= $assigned->radiusM) {
            return $assigned;
        }

        $best = null;
        $bestDistance = null;

        foreach ($this->configuredSites($employee->tenant_id) as [$type, $label, $sLat, $sLng, $radius]) {
            $distance = Geo::distanceMeters($lat, $lng, $sLat, $sLng);
            if ($distance <= $radius && ($bestDistance === null || $distance < $bestDistance)) {
                $best = [$type, $label, $sLat, $sLng, $radius];
                $bestDistance = $distance;
            }
        }

        if ($best === null) {
            return $assigned;
        }

        [$type, $label, $sLat, $sLng, $radius] = $best;

        return new SiteSpec(
            type: $type,
            label: $label,
            latitude: $sLat,
            longitude: $sLng,
            radiusM: $radius,
            workStart: $assigned->workStart,
            workEnd: $assigned->workEnd,
            minHours: $assigned->minHours,
        );
    }

    /**
     * Every geofenced branch and client site in the tenant.
     *
     * @return list<array{0:string,1:string,2:float,3:float,4:int}> type, label, lat, lng, radius
     */
    public function configuredSites(int $tenantId): array
    {
        // ponytail: a full scan of the tenant's sites, one query per clock event. A single
        // company has tens of them, not thousands — add a bounding-box WHERE if that changes.
        $branches = Branch::where('tenant_id', $tenantId)
            ->whereNotNull('latitude')->whereNotNull('longitude')->get()
            ->map(fn (Branch $b) => ['office', $b->name, (float) $b->latitude, (float) $b->longitude, (int) $b->radius_m]);

        $sites = WorkSite::where('tenant_id', $tenantId)
            ->whereNotNull('latitude')->whereNotNull('longitude')->get()
            ->map(fn (WorkSite $s) => ['client', $s->name, (float) $s->latitude, (float) $s->longitude, (int) $s->radius_m]);

        return $branches->concat($sites)->values()->all();
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
