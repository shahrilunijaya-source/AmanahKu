<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\StaffLevel;
use App\Support\CsvImport;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * HR rate card: position (rank) bands and their MAX salaries, from which manday /
 * manhour charge-out rates are derived (config/manday.php). Also assigns each
 * employee to a position. Privileged (management / HR) only.
 */
class PositionController extends Controller
{
    private const PRIVILEGED_ROLES = ['management', 'hr'];

    /** Data for the Position & Manday Rates screen. */
    public function screenData(Request $request): array
    {
        $this->authorize($request);

        return [
            'positions' => Position::with(['department', 'staffLevel'])
                ->orderBy('sort')->orderBy('title')->get(),
            'staff' => Employee::active()->with('department')->orderBy('name')->get(),
            // Real lookup rows for the rate-card selects (both managed in Company Settings).
            'departments' => Department::orderBy('name')->get(['id', 'name']),
            'staffLevels' => StaffLevel::orderByRaw('`rank` IS NULL, `rank`')->orderBy('name')->get(['id', 'name']),
        ];
    }

    /** Downloadable CSV template for the bulk position-band import (opens in Excel). */
    public function importTemplate(Request $request): Response
    {
        $this->authorize($request);

        $headers = ['department', 'level', 'title', 'code', 'max_salary', 'default_role', 'is_managerial', 'is_director', 'sort', 'description', 'status'];
        $sample = ['Operation', 'Manager', 'Project Manager', 'PM-01', '10000', 'manager', 'yes', 'no', '0', 'Leads delivery squads', 'active'];
        $csv = implode(',', $headers)."\n".implode(',', $sample)."\n";

        return response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="amanahku-position-import-template.csv"',
        ]);
    }

    /**
     * Bulk-create position bands from an uploaded CSV. Department and level are matched
     * by name within the current tenant and created on the fly when missing, so the
     * sheet can introduce new lookups without a separate step. Invalid rows are skipped
     * and reported; duplicate codes within the tenant are rejected per row.
     */
    public function import(Request $request): RedirectResponse
    {
        $this->authorize($request);
        $tenantId = app(CurrentTenant::class)->id();

        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:2048']]);

        [$handle, $col, $error] = CsvImport::open($request->file('file'));
        if ($error !== null) {
            return back()->with('error', $error);
        }

        // Tenant-scoped name → id maps, keyed case-insensitively (lower-cased + trimmed)
        // so a casing/whitespace variant ties to the EXISTING department / level instead
        // of spawning a duplicate (the departments table has no unique-name guard).
        // Missing lookups are still created on demand.
        $key = CsvImport::key(...);
        $departments = Department::get(['id', 'name'])->mapWithKeys(fn ($d) => [$key($d->name) => $d->id]);
        $staffLevels = StaffLevel::get(['id', 'name'])->mapWithKeys(fn ($s) => [$key($s->name) => $s->id]);
        $usedCodes = Position::whereNotNull('code')->pluck('code')
            ->mapWithKeys(fn ($c) => [mb_strtolower($c) => true]);

        $created = 0;
        $errors = [];
        $row = 1;

        while (($data = fgetcsv($handle)) !== false) {
            $row++;
            if ($created >= CsvImport::ROW_CAP) {
                $errors[] = 'Stopped at '.CsvImport::ROW_CAP.' rows.';
                break;
            }

            $get = fn (string $k) => CsvImport::cell($data, $col, $k);

            $title = $get('title');
            if ($title === '') {
                if (trim(implode('', $data)) === '') {
                    continue; // blank line
                }
                $errors[] = "Row $row: title is required.";

                continue;
            }

            $deptName = $get('department');
            $levelName = $get('level');
            if ($deptName === '' || $levelName === '') {
                $errors[] = "Row $row: department and level are required.";

                continue;
            }

            $maxSalary = $get('max_salary');
            if ($maxSalary !== '' && ! is_numeric($maxSalary)) {
                $errors[] = "Row $row: max_salary must be a number.";

                continue;
            }

            $code = $get('code') ?: null;
            if ($code !== null && $usedCodes->has(mb_strtolower($code))) {
                $errors[] = "Row $row: code '$code' already exists.";

                continue;
            }

            // Resolve (or create) the FK lookups, caching new ids back into the maps.
            $departmentId = $departments[$key($deptName)] ?? null;
            if ($departmentId === null) {
                $departmentId = Department::firstOrCreate(['name' => $deptName])->id;
                $departments[$key($deptName)] = $departmentId;
            }
            $staffLevelId = $staffLevels[$key($levelName)] ?? null;
            if ($staffLevelId === null) {
                $staffLevelId = StaffLevel::firstOrCreate(['name' => $levelName])->id;
                $staffLevels[$key($levelName)] = $staffLevelId;
            }

            $defaultRole = strtolower($get('default_role'));
            if (! in_array($defaultRole, ['employee', 'manager', 'management', 'hr'], true)) {
                $defaultRole = null;
            }

            $status = strtolower($get('status')) === 'inactive' ? 'inactive' : 'active';

            Position::create([
                'tenant_id' => $tenantId,
                'department_id' => $departmentId,
                'staff_level_id' => $staffLevelId,
                'title' => mb_substr($title, 0, 120),
                'code' => $code ? mb_substr($code, 0, 40) : null,
                'max_salary' => $maxSalary !== '' ? (float) $maxSalary : 0,
                'default_role' => $defaultRole,
                'is_managerial' => in_array(strtolower($get('is_managerial')), ['1', 'yes', 'true', 'y'], true),
                'is_director' => in_array(strtolower($get('is_director')), ['1', 'yes', 'true', 'y'], true),
                'sort' => is_numeric($get('sort')) ? (int) $get('sort') : 0,
                'description' => $get('description') ? mb_substr($get('description'), 0, 240) : null,
                'status' => $status,
            ]);
            if ($code !== null) {
                $usedCodes->put(mb_strtolower($code), true);
            }
            $created++;
        }
        fclose($handle);

        AuditLog::record('Imported positions', $created.' band(s)');

        $msg = CsvImport::summary($created, 'position band(s) imported', $errors);

        return back()->with($errors !== [] ? 'error' : 'ok', $msg);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize($request);

        $data = $this->validatePosition($request);
        $data['is_managerial'] = $request->boolean('is_managerial');
        $data['is_director'] = $request->boolean('is_director');
        $position = Position::create($data);
        AuditLog::record('Added position', $position->title.' · RM'.number_format((float) $position->max_salary, 2));

        return back()->with('ok', $position->title.' added.');
    }

    public function update(Request $request, Position $position): RedirectResponse
    {
        $this->authorize($request);
        $this->assertTenant($position->tenant_id);

        $data = $this->validatePosition($request, $position->id);
        $data['is_managerial'] = $request->boolean('is_managerial');
        $data['is_director'] = $request->boolean('is_director');
        $position->update($data);
        AuditLog::record('Updated position', $position->title.' · RM'.number_format((float) $position->max_salary, 2));

        return back()->with('ok', $position->title.' updated.');
    }

    public function destroy(Request $request, Position $position): RedirectResponse
    {
        $this->authorize($request);
        $this->assertTenant($position->tenant_id);

        $title = $position->title;
        $position->delete(); // employees.position_id is nullOnDelete
        AuditLog::record('Removed position', $title);

        return back()->with('ok', $title.' removed.');
    }

    /** Assign (or clear) an employee's position band. */
    public function assign(Request $request, Employee $employee): RedirectResponse|JsonResponse
    {
        $this->authorize($request);
        $this->assertTenant($employee->tenant_id);
        $tenantId = app(CurrentTenant::class)->id();

        $data = $request->validate([
            'position_id' => [
                'nullable',
                'integer',
                // Must be a position in the SAME tenant — never let an assignment
                // reference another company's rate card.
                "exists:positions,id,tenant_id,{$tenantId}",
            ],
        ]);

        // Assigning a band drives the employee's department, staff level and job title
        // (same as the staff form), so the directory/profile reflect the band — not stale
        // seed data. Clearing the band clears those derived fields too.
        $band = ($data['position_id'] ?? null) ? Position::find($data['position_id']) : null;
        $employee->update([
            'position_id' => $band?->id,
            'department_id' => $band?->department_id,
            'staff_level_id' => $band?->staff_level_id,
            'position' => $band?->title,
            'level' => null,
        ]);
        AuditLog::record('Assigned position', $employee->name.' → '.($band?->title ?? 'none'));

        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'title' => $band?->title]);
        }

        return back()->with('ok', $employee->name.' position updated.');
    }

    /** @return array<string,mixed> */
    private function validatePosition(Request $request, ?int $ignoreId = null): array
    {
        $tenantId = app(CurrentTenant::class)->id();

        return $request->validate([
            'department_id' => ['required', 'integer', "exists:departments,id,tenant_id,{$tenantId}"],
            'staff_level_id' => ['required', 'integer', "exists:staff_levels,id,tenant_id,{$tenantId}"],
            'title' => ['required', 'string', 'max:120'],
            'max_salary' => ['required', 'numeric', 'min:0', 'max:10000000'],
            'sort' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'code' => ['nullable', 'string', 'max:40', Rule::unique('positions', 'code')->where('tenant_id', $tenantId)->ignore($ignoreId)],
            'default_role' => ['nullable', 'in:employee,manager,management,hr'],
            'description' => ['nullable', 'string', 'max:240'],
            'status' => ['nullable', 'in:active,inactive'],
        ]);
    }

    private function authorize(Request $request): void
    {
        $this->authorizeTenantRole($request, self::PRIVILEGED_ROLES);
    }

    private function assertTenant(int $tenantId): void
    {
        abort_unless($tenantId === app(CurrentTenant::class)->id(), 403);
    }
}
