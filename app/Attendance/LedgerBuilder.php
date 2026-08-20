<?php

declare(strict_types=1);

namespace App\Attendance;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\LeaveRequest;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Turns the employee list and the records into one row per person per working day.
 *
 * Built from the EMPLOYEE list, never from the records. A report assembled out of
 * attendance rows cannot show non-attendance: someone who never clocked, or who
 * stopped three weeks ago, produces no record and so would silently vanish. That
 * failure is what retired the pre-roster version of this screen; the rule stands.
 */
final class LedgerBuilder
{
    /** A worked span under this reads as a half day rather than a short full day. */
    private const HALF_DAY_MINUTES = 300;

    /** Record flag → the short name this screen renders. */
    private const FLAG_MAP = [
        'out_of_radius_in' => 'off',
        'out_of_radius_out' => 'off',
        'short_hours' => 'short',
        'early_out' => 'early',
        'no_location' => 'noloc',
        // An HR-typed clock-out is not a punch. It has to be visible on the row, or
        // the ledger shows a time nobody actually recorded and says nothing about it.
        'amended' => 'amended',
    ];

    /**
     * @param  Collection<int, Employee>  $employees
     * @param  Collection<int, AttendanceRecord>  $records
     * @param  Collection<int, LeaveRequest>  $leaveRequests
     * @param  list<string>  $workingDays
     * @return Collection<int, array<string, mixed>>
     */
    public function build(
        Collection $employees,
        Collection $records,
        Collection $leaveRequests,
        array $workingDays,
        CarbonImmutable $today,
    ): Collection {
        $byEmployeeDate = [];
        foreach ($records as $r) {
            $byEmployeeDate[$r->employee_id][$r->date->toDateString()] = $r;
        }

        $leave = [];
        foreach ($leaveRequests as $l) {
            $leave[$l->employee_id][] = [
                'from' => $l->date_from->toDateString(),
                'to' => $l->date_to->toDateString(),
                'type' => $l->leaveType?->name,
            ];
        }

        $todayStr = $today->toDateString();
        $rows = [];

        foreach ($employees as $emp) {
            foreach ($workingDays as $date) {
                $rows[] = $this->row(
                    $emp,
                    $byEmployeeDate[$emp->id][$date] ?? null,
                    $leave[$emp->id] ?? [],
                    $date,
                    $todayStr,
                );
            }
        }

        return collect($rows);
    }

    /**
     * @param  list<array{from: string, to: string, type: string|null}>  $leave
     * @return array<string, mixed>
     */
    private function row(Employee $emp, ?AttendanceRecord $r, array $leave, string $date, string $todayStr): array
    {
        $base = [
            'employeeId' => $emp->id,
            'name' => $emp->display_name,
            'initials' => $emp->initials,
            'color' => $emp->avatar_color,
            'dept' => $emp->department?->name,
            'date' => $date,
            'dow' => CarbonImmutable::parse($date)->dayOfWeek,
            'in' => null,
            'out' => null,
            'hours' => null,
            'flags' => [],
            'leaveType' => null,
            'recordId' => $r?->id,
            'points' => [],
            'hasPoint' => false,
        ];

        if ($r === null || $r->clock_in === null) {
            $covering = $this->leaveOn($leave, $date);

            return array_merge($base, [
                'status' => match (true) {
                    $covering !== false => 'leave',
                    $date === $todayStr => 'pending',
                    default => 'absent',
                },
                'leaveType' => $covering === false ? null : ($covering ?: null),
            ]);
        }

        $minutes = (int) ($r->worked_minutes ?? 0);
        // An open punch dated today is still mid-shift; only a past one is broken.
        $missing = $r->clock_out === null && $date !== $todayStr;

        $status = match (true) {
            $missing => 'miss',
            $r->clock_out !== null && $minutes > 0 && $minutes < self::HALF_DAY_MINUTES => 'half',
            $r->status === 'late' => 'late',
            default => 'ontime',
        };

        $points = $this->points($r);

        return array_merge($base, [
            'in' => $this->hhmm($r->clock_in),
            'out' => $r->clock_out !== null ? $this->hhmm($r->clock_out) : null,
            'hours' => $r->clock_out !== null && $minutes > 0 ? round($minutes / 60, 2) : null,
            'status' => $status,
            'flags' => $this->flags($r),
            'points' => $points,
            'hasPoint' => $points !== [],
        ]);
    }

    /** @return list<string> */
    private function flags(AttendanceRecord $r): array
    {
        $out = [];
        foreach ($r->flags ?? [] as $flag) {
            // 'late' is a status, not a flag — rendering it twice reads as two problems.
            if ($flag === 'late') {
                continue;
            }
            $mapped = self::FLAG_MAP[$flag] ?? null;
            if ($mapped !== null && ! in_array($mapped, $out, true)) {
                $out[] = $mapped;
            }
        }

        if ($r->work_mode === 'site_visit' || $r->clock_out_work_mode === 'site_visit') {
            array_unshift($out, 'visit');
        }

        return $out;
    }

    /**
     * The map points for one record, in the shape partials/map-view.blade.php consumes.
     *
     * Only punches that were actually off-site or a declared site visit. within()
     * returns null (never false) when coordinates or the site geofence are missing, so
     * an out_of_radius_* flag already implies a usable point; the null-checks guard
     * hand-edited rows, not the normal path.
     *
     * @return list<array{lat: float, lng: float, labelEn: string, labelMs: string}>
     */
    private function points(AttendanceRecord $r): array
    {
        $points = [];

        if (($this->flagged($r, 'out_of_radius_in') || $r->work_mode === 'site_visit')
            && $r->latitude !== null && $r->longitude !== null) {
            $at = $this->hhmm((string) $r->clock_in);
            $points[] = [
                'lat' => (float) $r->latitude,
                'lng' => (float) $r->longitude,
                'labelEn' => 'Clocked in '.$at,
                'labelMs' => 'Clock in '.$at,
            ];
        }

        if (($this->flagged($r, 'out_of_radius_out') || $r->clock_out_work_mode === 'site_visit')
            && $r->clock_out_latitude !== null && $r->clock_out_longitude !== null) {
            $at = $r->clock_out !== null ? $this->hhmm($r->clock_out) : '';
            $points[] = [
                'lat' => (float) $r->clock_out_latitude,
                'lng' => (float) $r->clock_out_longitude,
                'labelEn' => 'Clocked out '.$at,
                'labelMs' => 'Clock out '.$at,
            ];
        }

        return $points;
    }

    private function flagged(AttendanceRecord $r, string $flag): bool
    {
        return in_array($flag, $r->flags ?? [], true);
    }

    /** @param  list<array{from: string, to: string, type: string|null}>  $leave */
    private function leaveOn(array $leave, string $date): string|false
    {
        foreach ($leave as $l) {
            if ($date >= $l['from'] && $date <= $l['to']) {
                return $l['type'] ?? '';
            }
        }

        return false;
    }

    private function hhmm(string $time): string
    {
        return (string) Str::of($time)->limit(5, '');
    }
}
