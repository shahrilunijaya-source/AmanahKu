<?php

declare(strict_types=1);

namespace App\Attendance;

use Illuminate\Support\Collection;

/**
 * Totals and lens counts for a set of ledger rows.
 *
 * Callers must pass SCOPED rows — filtered by department and staff search, but
 * NOT by the exception lens. Department and name change whose period this is;
 * the lens only changes which rows you are looking at within it. A block
 * captioned "month to date" that reads 19 present because somebody clicked
 * "missing clock-out" is not a total, it is a lie.
 */
final class LedgerTotals
{
    /**
     * @param  Collection<int, array<string, mixed>>  $scopedRows
     * @return array<string, mixed>
     */
    public static function of(Collection $scopedRows): array
    {
        $leaveByType = [];
        foreach ($scopedRows as $row) {
            if ($row['status'] === 'leave' && ($row['leaveType'] ?? null)) {
                $leaveByType[$row['leaveType']] = ($leaveByType[$row['leaveType']] ?? 0) + 1;
            }
        }
        arsort($leaveByType);

        return [
            'present' => $scopedRows->whereNotIn('status', ['absent', 'leave', 'pending'])->count(),
            'absent' => $scopedRows->where('status', 'absent')->count(),
            'late' => $scopedRows->where('status', 'late')->count(),
            'leave' => $scopedRows->where('status', 'leave')->count(),
            'staff' => $scopedRows->pluck('employeeId')->unique()->count(),
            'hours' => round((float) $scopedRows->sum(fn (array $r) => $r['hours'] ?? 0), 1),
            'leaveByType' => $leaveByType,
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $scopedRows
     * @return array<string, int>
     */
    public static function counts(Collection $scopedRows): array
    {
        return [
            'all' => $scopedRows->count(),
            'miss' => $scopedRows->where('status', 'miss')->count(),
            'absent' => $scopedRows->where('status', 'absent')->count(),
            'short' => $scopedRows->filter(fn (array $r) => in_array('short', $r['flags'], true))->count(),
            'late' => $scopedRows->where('status', 'late')->count(),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    public static function applyLens(Collection $rows, ?string $lens): Collection
    {
        return match ($lens) {
            'miss' => $rows->where('status', 'miss')->values(),
            'absent' => $rows->where('status', 'absent')->values(),
            'late' => $rows->where('status', 'late')->values(),
            'short' => $rows->filter(fn (array $r) => in_array('short', $r['flags'], true))->values(),
            default => $rows->values(),
        };
    }
}
