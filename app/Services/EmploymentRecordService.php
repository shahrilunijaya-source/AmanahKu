<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\EmployeeProgression;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The one place employment state changes. Both the profile's Employment edit modal and the
 * Progression screen call these, so every path leaves the same append-only timeline row.
 */
final class EmploymentRecordService
{
    /**
     * Keys in the snapshot. Lookup names are stored as strings so a later rename of a
     * department or position never rewrites history.
     *
     * @return array<string, mixed>
     */
    public function snapshot(Employee $e): array
    {
        $e->loadMissing(['department', 'branch', 'positionBand', 'reportsTo', 'employmentType']);

        return [
            'status' => $e->status,
            'department' => $e->department?->name,
            'division' => $e->division,
            'section' => $e->section,
            'position' => $e->positionBand?->title,
            'job_grade' => $e->job_grade,
            'category' => $e->category,
            'line' => $e->line,
            'branch' => $e->branch?->name,
            'reports_to' => $e->reportsTo ? ['id' => $e->reportsTo->id, 'name' => $e->reportsTo->name] : null,
            'employment_type' => $e->employmentType?->name,
            'probation_months' => $e->probation_months,
            'probation_days' => $e->probation_days,
            'basic_salary' => $e->salary === null ? null : (float) $e->salary,
            'pay_mode' => $e->pay_mode,
            'payment_term' => $e->payment_term,
            'payment_method' => $e->payment_method,
        ];
    }

    /** First row for a new (or backfilled) staff member; no diff, no status change. */
    public function hire(Employee $e, ?Employee $by = null): EmployeeProgression
    {
        return $this->record($e, 'hired', $e->joined_at?->toDateString() ?? now()->toDateString(), null, $by, null);
    }

    /** @param array<string, mixed> $fields */
    public function confirm(Employee $e, string $confirmedOn, array $fields, ?string $remark, ?Employee $by): EmployeeProgression
    {
        $this->assertStatus($e, ['probation'], 'Only staff on probation can be confirmed.');
        $this->assertOnOrAfterHire($e, $confirmedOn);

        return DB::transaction(function () use ($e, $confirmedOn, $fields, $remark, $by) {
            $previous = $this->snapshot($e);
            $e->fill($this->only($fields))->forceFill(['confirmed_at' => $confirmedOn, 'status' => 'active'])->save();
            $row = $this->record($e, 'confirmed', $confirmedOn, $remark, $by, $previous);
            AuditLog::record('Confirmed employee', $e->name);

            return $row;
        });
    }

    /**
     * Null when nothing in the snapshot changed: no row, no audit entry.
     *
     * @param  array<string, mixed>  $fields
     */
    public function update(Employee $e, string $effectiveOn, array $fields, ?string $remark, ?Employee $by, ?string $updateType = null): ?EmployeeProgression
    {
        $this->assertStatus($e, ['active', 'probation', 'on_leave'], 'Resigned staff must be rehired before their record can change.');
        $this->assertOnOrAfterHire($e, $effectiveOn);

        return DB::transaction(function () use ($e, $effectiveOn, $fields, $remark, $by, $updateType) {
            $previous = $this->snapshot($e);
            $e->fill($this->only($fields))->save();
            $e->unsetRelations();
            if ($this->diff($previous, $this->snapshot($e)) === []) {
                return null;
            }
            $row = $this->record($e, 'updated', $effectiveOn, $remark, $by, $previous, $updateType ? ['update_type' => $updateType] : []);
            AuditLog::record('Updated employment record', $e->name);

            return $row;
        });
    }

    public function resign(Employee $e, string $resignedOn, string $lastWorkingDay, string $reason, ?string $remark, ?Employee $by): EmployeeProgression
    {
        $this->assertStatus($e, ['active', 'probation', 'on_leave'], 'This person has already left.');
        $this->assertOnOrAfterHire($e, $resignedOn);
        if (CarbonImmutable::parse($lastWorkingDay)->lt(CarbonImmutable::parse($resignedOn))) {
            throw new EmploymentTransitionException('Last working day cannot be before the resignation date.');
        }

        return DB::transaction(function () use ($e, $resignedOn, $lastWorkingDay, $reason, $remark, $by) {
            $previous = $this->snapshot($e);
            $e->forceFill(['resigned_at' => $resignedOn, 'last_working_day' => $lastWorkingDay, 'status' => 'resigned'])->save();
            $row = $this->record($e, 'resigned', $resignedOn, $remark, $by, $previous, ['reason' => $reason, 'last_working_day' => $lastWorkingDay]);
            AuditLog::record('Recorded resignation', $e->name);

            return $row;
        });
    }

    /** @param array<string, mixed> $fields */
    public function rehire(Employee $e, string $hiredOn, array $fields, ?string $remark, ?Employee $by): EmployeeProgression
    {
        $this->assertStatus($e, ['resigned'], 'Only resigned staff can be rehired.');

        return DB::transaction(function () use ($e, $hiredOn, $fields, $remark, $by) {
            $previous = $this->snapshot($e);
            $e->fill($this->only($fields))->forceFill([
                'joined_at' => $hiredOn, 'resigned_at' => null, 'last_working_day' => null, 'confirmed_at' => null, 'status' => 'probation',
            ])->save();
            $row = $this->record($e, 'rehired', $hiredOn, $remark, $by, $previous);
            AuditLog::record('Rehired employee', $e->name);

            return $row;
        });
    }

    /**
     * Snapshot keys whose value differs. Loose comparison on purpose: 5000 and 5000.0 are
     * the same salary.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return list<string>
     */
    public function diff(array $before, array $after): array
    {
        return array_keys(array_filter($after, fn ($v, $k) => ($before[$k] ?? null) != $v, ARRAY_FILTER_USE_BOTH));
    }

    /**
     * @param  array<string, mixed>|null  $previous
     * @param  array<string, mixed>  $extra
     */
    /** Worksy's Update Type list on a progression update. */
    public const UPDATE_TYPES = ['increment' => ['Increment', 'Kenaikan'], 'promotion' => ['Promotion', 'Kenaikan Pangkat'], 'role_transfer' => ['Role Transfer', 'Pertukaran Peranan'], 'salary_adjustment' => ['Salary Adjustment', 'Pelarasan Gaji']];

    private function record(Employee $e, string $type, string $on, ?string $remark, ?Employee $by, ?array $previous, array $extra = []): EmployeeProgression
    {
        $e->unsetRelations();
        $snapshot = $this->snapshot($e) + $extra;

        return EmployeeProgression::create([
            'tenant_id' => $e->tenant_id,
            'employee_id' => $e->id,
            'type' => $type,
            'effective_on' => $on,
            'snapshot' => $snapshot,
            'changed_fields' => $previous === null ? [] : array_values(array_diff($this->diff($previous, $snapshot), ['update_type'])),
            'remark' => $remark ?: null,
            'recorded_by_employee_id' => $by?->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    private function only(array $fields): array
    {
        return array_intersect_key($fields, array_flip(Employee::EMPLOYMENT_FIELDS));
    }

    /** @param list<string> $allowed */
    private function assertStatus(Employee $e, array $allowed, string $message): void
    {
        if (! in_array($e->status, $allowed, true)) {
            throw new EmploymentTransitionException($message);
        }
    }

    private function assertOnOrAfterHire(Employee $e, string $date): void
    {
        if ($e->joined_at && CarbonImmutable::parse($date)->lt($e->joined_at)) {
            throw new EmploymentTransitionException('Date cannot be before the hire date ('.$e->joined_at->format('d M Y').').');
        }
    }
}
