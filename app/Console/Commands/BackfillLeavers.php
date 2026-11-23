<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\EmployeeProgression;
use App\Models\Tenant;
use App\Services\EmploymentRecordService;
use App\Services\StaffArchiver;
use App\Tenancy\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-off repair for staff marked "resigned" without going through the resignation flow
 * (bulk import, the old system). They have no leaving dates and were never archived, so
 * the year-end forms (Form E, EA, CP8D, which read archived_at and last_working_day)
 * treat them as still employed, and HR cannot enter the dates in the app because the
 * resignation form refuses anyone already marked resigned.
 *
 * Reads a CSV (name,last_working_day,resigned_on,joined_on; the last two optional) that
 * stays OUT of the repo, since it holds staff names. For each row it records the dates
 * and a "resigned" timeline entry (unless one exists), then archives the person dated
 * their last working day, the same date the staff import gives leavers. A dry run unless
 * --apply is passed. Safe to re-run: a person already archived or already holding a
 * resignation entry is not touched twice.
 */
class BackfillLeavers extends Command
{
    protected $signature = 'staff:backfill-leavers {file : CSV with name,last_working_day,resigned_on,joined_on}
        {--tenant= : Company slug}
        {--apply : Write the changes (default is a dry run)}';

    protected $description = 'Record leaving dates for resigned staff from a CSV and archive them on their last working day.';

    public function handle(CurrentTenant $context, EmploymentRecordService $records, StaffArchiver $archiver): int
    {
        $tenant = Tenant::where('slug', (string) $this->option('tenant'))->first();
        $handle = is_readable((string) $this->argument('file')) ? fopen((string) $this->argument('file'), 'r') : false;
        if (! $tenant || ! $handle) {
            $this->error(! $tenant ? 'Unknown --tenant slug.' : 'Cannot read the CSV file.');

            return self::FAILURE;
        }
        $context->set($tenant);
        $apply = (bool) $this->option('apply');

        fgetcsv($handle); // header
        $done = 0;
        while (($row = fgetcsv($handle)) !== false) {
            [$name, $lastDay, $resignedOn, $joinedOn] = array_map(fn ($v) => trim((string) $v), array_pad($row, 4, ''));
            if ($name === '') {
                continue;
            }

            $problem = $this->problem($name, $lastDay, $resignedOn, $joinedOn, $employee);
            if ($problem !== null) {
                $this->warn("{$name}: {$problem} (skipped)");

                continue;
            }

            $hasResignation = EmployeeProgression::where('employee_id', $employee->id)->where('type', 'resigned')->exists();
            if ($hasResignation && $employee->isArchived()) {
                $this->line("{$name}: already done");

                continue;
            }
            $this->line(sprintf('%s: %slast day %s%s', $name,
                $hasResignation ? '' : 'record resignation, ',
                $lastDay, $employee->isArchived() ? ' (already archived)' : ', archive'));

            if (! $apply) {
                continue;
            }

            DB::transaction(function () use ($employee, $lastDay, $resignedOn, $joinedOn, $hasResignation, $records, $archiver) {
                if (! $hasResignation) {
                    if ($joinedOn !== '' && $employee->joined_at?->toDateString() !== $joinedOn) {
                        // Keep the "hired" timeline entry on the same day as the corrected join date.
                        EmployeeProgression::where('employee_id', $employee->id)->where('type', 'hired')
                            ->update(['effective_on' => $joinedOn]);
                        $employee->joined_at = $joinedOn;
                    }
                    $employee->forceFill(['resigned_at' => $resignedOn ?: $lastDay, 'last_working_day' => $lastDay])->save();
                    EmployeeProgression::create([
                        'tenant_id' => $employee->tenant_id,
                        'employee_id' => $employee->id,
                        'type' => 'resigned',
                        'effective_on' => $resignedOn ?: $lastDay,
                        'snapshot' => $records->snapshot($employee) + ['reason' => 'resigned', 'last_working_day' => $lastDay],
                        'changed_fields' => [],
                        'remark' => 'Backfilled from the old HR system.',
                    ]);
                }

                if (! $employee->isArchived()) {
                    $employee->forceFill(['archived_at' => $lastDay])->save();
                    $archiver->detach($employee);
                }
            });
            AuditLog::record('Backfilled leaving dates and archived', $employee->name);
            $done++;
        }
        fclose($handle);
        $context->set(null);

        $this->info($apply ? "Updated {$done} leaver(s)." : 'Dry run only. Re-run with --apply to write these changes.');

        return self::SUCCESS;
    }

    /**
     * Why a row cannot be applied, or null when it can. Resolves the employee by exact
     * name within the tenant, and only accepts people already marked resigned: anyone
     * still working goes through the resignation form on their profile instead.
     */
    private function problem(string $name, string $lastDay, string $resignedOn, string $joinedOn, ?Employee &$employee): ?string
    {
        foreach ([$lastDay, $resignedOn, $joinedOn] as $date) {
            if ($date !== '' && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                return 'dates must be YYYY-MM-DD';
            }
        }
        if ($lastDay === '') {
            return 'last_working_day is required';
        }

        $matches = Employee::where('name', $name)->get();
        if ($matches->count() !== 1) {
            return $matches->isEmpty() ? 'no staff record with this exact name' : 'more than one staff record with this name';
        }
        $employee = $matches->first();

        if ($employee->status !== 'resigned') {
            return 'not marked resigned; record their resignation on their profile instead';
        }
        $joined = $joinedOn !== '' ? $joinedOn : $employee->joined_at?->toDateString();
        if ($joined !== null && CarbonImmutable::parse($lastDay)->lt(CarbonImmutable::parse($joined))) {
            return 'last working day is before their join date; add the real joined_on';
        }

        return null;
    }
}
