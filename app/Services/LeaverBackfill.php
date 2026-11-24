<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\EmployeeProgression;
use App\Support\CsvImport;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Repair for staff marked "resigned" without going through the resignation flow (bulk
 * import, the old system). They have no leaving dates and were never archived, so the
 * year-end forms (Form E, EA, CP8D, which read archived_at and last_working_day) treat
 * them as still employed, and HR cannot enter the dates in the app because the
 * resignation form refuses anyone already marked resigned.
 *
 * Each row (name, last_working_day, resigned_on, joined_on; the last two optional) records
 * the dates and a "resigned" timeline entry (unless one exists), then archives the person
 * dated their last working day, the same date the staff import gives leavers. Safe to
 * re-run: a person already archived or already holding a resignation entry is not touched
 * twice. Used by the staff:backfill-leavers command and the directory's upload screen.
 * Runs inside the current tenant.
 */
final class LeaverBackfill
{
    public function __construct(private EmploymentRecordService $records, private StaffArchiver $archiver) {}

    /**
     * Read the CSV at $path into rows, by header name. Null when the file can't be read.
     *
     * @return list<array{name: string, last_working_day: string, resigned_on: string, joined_on: string}>|null
     */
    public static function readCsv(string $path): ?array
    {
        $handle = is_readable($path) ? fopen($path, 'r') : false;
        $header = $handle ? fgetcsv($handle) : false;
        if (! $handle || ! $header) {
            return null;
        }
        $col = array_flip(array_map(fn ($h) => strtolower(trim((string) $h)), $header));

        $rows = [];
        while (($data = fgetcsv($handle)) !== false && count($rows) < CsvImport::ROW_CAP) {
            $row = [];
            foreach (['name', 'last_working_day', 'resigned_on', 'joined_on'] as $field) {
                $row[$field] = CsvImport::cell($data, $col, $field);
            }
            if ($row['name'] !== '') {
                $rows[] = $row;
            }
        }
        fclose($handle);

        return $rows;
    }

    /**
     * What would happen to each row, without writing anything.
     *
     * @param  list<array{name: string, last_working_day: string, resigned_on: string, joined_on: string}>  $rows
     * @return list<array{name: string, last_working_day: string, resigned_on: string, joined_on: string, outcome: string, detail: string}>
     */
    public function plan(array $rows): array
    {
        return array_map(fn (array $row) => $this->check($row)['item'], $rows);
    }

    /**
     * Write every row that is ready. Each row is checked again just before it is written,
     * so a file that names the same person twice only changes them once.
     *
     * @param  list<array{name: string, last_working_day: string, resigned_on: string, joined_on: string}>  $rows
     * @return int how many people were updated
     */
    public function apply(array $rows): int
    {
        $done = 0;
        foreach ($rows as $row) {
            ['item' => $item, 'employee' => $employee, 'hasResignation' => $hasResignation] = $this->check($row);
            if ($item['outcome'] !== 'ready') {
                continue;
            }

            $lastDay = $row['last_working_day'];
            $resignedOn = $row['resigned_on'] ?: $lastDay;
            $joinedOn = $row['joined_on'];

            DB::transaction(function () use ($employee, $lastDay, $resignedOn, $joinedOn, $hasResignation) {
                if (! $hasResignation) {
                    if ($joinedOn !== '' && $employee->joined_at?->toDateString() !== $joinedOn) {
                        // Keep the "hired" timeline entry on the same day as the corrected join date.
                        EmployeeProgression::where('employee_id', $employee->id)->where('type', 'hired')
                            ->update(['effective_on' => $joinedOn]);
                        $employee->joined_at = $joinedOn;
                    }
                    $employee->forceFill(['resigned_at' => $resignedOn, 'last_working_day' => $lastDay])->save();
                    EmployeeProgression::create([
                        'tenant_id' => $employee->tenant_id,
                        'employee_id' => $employee->id,
                        'type' => 'resigned',
                        'effective_on' => $resignedOn,
                        'snapshot' => $this->records->snapshot($employee) + ['reason' => 'resigned', 'last_working_day' => $lastDay],
                        'changed_fields' => [],
                        'remark' => 'Backfilled from the old HR system.',
                    ]);
                }

                if (! $employee->isArchived()) {
                    $employee->forceFill(['archived_at' => $lastDay])->save();
                    $this->archiver->detach($employee);
                }
            });
            AuditLog::record('Backfilled leaving dates and archived', $employee->name);
            $done++;
        }

        return $done;
    }

    /**
     * Check one row. outcome is "ready", "done" (nothing left to change) or "skipped"
     * (detail says why). Only people already marked resigned are accepted: anyone still
     * working goes through the resignation form on their profile instead.
     *
     * @param  array{name: string, last_working_day: string, resigned_on: string, joined_on: string}  $row
     * @return array{item: array{name: string, last_working_day: string, resigned_on: string, joined_on: string, outcome: string, detail: string}, employee: ?Employee, hasResignation: bool}
     */
    private function check(array $row): array
    {
        $skip = fn (string $why) => ['item' => $row + ['outcome' => 'skipped', 'detail' => $why], 'employee' => null, 'hasResignation' => false];

        foreach (['last_working_day', 'resigned_on', 'joined_on'] as $field) {
            if ($row[$field] !== '' && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $row[$field])) {
                return $skip('dates must be YYYY-MM-DD');
            }
        }
        if ($row['last_working_day'] === '') {
            return $skip('last_working_day is required');
        }

        $matches = Employee::where('name', $row['name'])->get();
        if ($matches->count() !== 1) {
            return $skip($matches->isEmpty() ? 'no staff record with this exact name' : 'more than one staff record with this name');
        }
        $employee = $matches->first();

        if ($employee->status !== 'resigned') {
            return $skip('not marked resigned; record their resignation on their profile instead');
        }
        $joined = $row['joined_on'] !== '' ? $row['joined_on'] : $employee->joined_at?->toDateString();
        if ($joined !== null && CarbonImmutable::parse($row['last_working_day'])->lt(CarbonImmutable::parse($joined))) {
            return $skip('last working day is before their join date; add the real joined_on');
        }

        $hasResignation = EmployeeProgression::where('employee_id', $employee->id)->where('type', 'resigned')->exists();
        $archived = $employee->isArchived();
        [$outcome, $detail] = match (true) {
            $hasResignation && $archived => ['done', 'already done'],
            $hasResignation => ['ready', 'archive'],
            $archived => ['ready', 'record resignation (already archived)'],
            default => ['ready', 'record resignation, archive'],
        };

        return ['item' => $row + ['outcome' => $outcome, 'detail' => $detail], 'employee' => $employee, 'hasResignation' => $hasResignation];
    }
}
