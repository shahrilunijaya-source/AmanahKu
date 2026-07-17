<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\EmploymentType;
use App\Models\Position;
use App\Services\StaffArchiver;
use App\Support\CsvImport;
use App\Tenancy\CurrentTenant;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Rules\Unique;

class EmployeeController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $this->authorizePermission('staff.create');
        $tenantId = app(CurrentTenant::class)->id();
        $this->nullifyEmptyFks($request);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            // Active-only, tenant-scoped uniqueness (AK-DB-03): two live employees must not
            // share an email/staff id, or login provisioning later marks one email_taken and
            // strands a directory record. Archived rows are excluded so an email frees up on
            // archive. MySQL has no partial unique index, so this is enforced in the app layer.
            'email' => ['nullable', 'email', 'max:160', $this->activeUnique('email', $tenantId)],
            'staff_id' => ['nullable', 'string', 'max:50', $this->activeUnique('staff_id', $tenantId)],
            'joined_at' => ['nullable', 'date', 'before_or_equal:today'],
            'date_of_birth' => ['nullable', 'date', 'before:today'],
            'position_id' => ['nullable', 'integer', $this->inTenant('positions', $tenantId)],
            'salary' => ['nullable', 'numeric', 'min:0', 'max:10000000'],
            'branch_id' => ['nullable', 'integer', $this->inTenant('branches', $tenantId)],
            'employment_type_id' => ['nullable', 'integer', $this->inTenant('employment_types', $tenantId)],
            'status' => ['required', 'in:active,probation,on_leave,resigned'],
        ]);

        // Salary is board + HR only (director/hr). A management-role creator sees no salary
        // field and cannot set one via a forged POST — the person starts with no salary and
        // HR fills it in later. Mirrors the read gate in BuildsPeopleData::peopleData().
        $canSetSalary = $this->hasTenantRole($request, ['director', 'hr']);

        Employee::create([
            'tenant_id' => $tenantId,
            'name' => $data['name'],
            'email' => $data['email'] ?? null,
            'staff_id' => $data['staff_id'] ?? null,
            'joined_at' => $data['joined_at'] ?? now()->toDateString(),
            'date_of_birth' => $data['date_of_birth'] ?? null,
            'salary' => $canSetSalary ? ($data['salary'] ?? null) : null,
            'branch_id' => $data['branch_id'] ?? null,
            'employment_type_id' => $data['employment_type_id'] ?? null,
            'status' => $data['status'],
            'workload' => 'green',
            'workload_label' => 'Healthy',
            'initials' => $this->initials($data['name']),
            'avatar_color' => config('amanahku.avatar_color'),
        ] + $this->bandFields(isset($data['position_id']) ? (int) $data['position_id'] : null));

        AuditLog::record('Added employee', $data['name']);

        return back()->with('ok', $data['name'].' added to the directory.');
    }

    public function update(Request $request, Employee $employee): RedirectResponse
    {
        $this->authorizePermission('staff.update');
        $tenantId = app(CurrentTenant::class)->id();
        abort_unless($employee->tenant_id === $tenantId, 403);
        $this->nullifyEmptyFks($request);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            // Active-only, tenant-scoped uniqueness (AK-DB-03), ignoring this row itself.
            'email' => ['nullable', 'email', 'max:160', $this->activeUnique('email', $tenantId, $employee->id)],
            'staff_id' => ['nullable', 'string', 'max:50', $this->activeUnique('staff_id', $tenantId, $employee->id)],
            'joined_at' => ['nullable', 'date', 'before_or_equal:today'],
            'date_of_birth' => ['nullable', 'date', 'before:today'],
            'position_id' => ['nullable', 'integer', $this->inTenant('positions', $tenantId)],
            'salary' => ['nullable', 'numeric', 'min:0', 'max:10000000'],
            'branch_id' => ['nullable', 'integer', $this->inTenant('branches', $tenantId)],
            'employment_type_id' => ['nullable', 'integer', $this->inTenant('employment_types', $tenantId)],
            'work_arrangement' => ['nullable', 'in:office,client,wfh,hybrid'],
            // Reporting line that builds the org chart. Must be another current staff
            // member in this tenant — never the person themselves, and never a manager
            // whose own chain leads back here (that would loop the tree builder).
            'reports_to_id' => [
                'nullable', 'integer',
                Rule::notIn([$employee->id]),
                // Must be an ACTIVE manager — never an archived one. Upholds the invariant
                // "no active employee reports to an archived person" even against a crafted
                // POST (the UI picker is already active-only). whereNull('archived_at') so
                // this stays a plain exists rule, not the generic tenant-only inTenant().
                Rule::exists('employees', 'id')->where(fn ($q) => $q->where('tenant_id', $tenantId)->whereNull('archived_at')),
                function (string $attr, mixed $value, \Closure $fail) use ($employee): void {
                    if ($value && $this->wouldCycle($employee->id, (int) $value)) {
                        $fail('That reporting line creates a loop — pick a different manager.');
                    }
                },
            ],
            'status' => ['required', 'in:active,probation,on_leave,resigned'],
        ]);

        // Salary is board + HR only (director/hr). Anyone else editing this person (e.g. the
        // management role, who no longer sees the field) keeps the existing salary untouched
        // — the absent field must NOT be read as "clear it", and a forged POST is ignored.
        $canSetSalary = $this->hasTenantRole($request, ['director', 'hr']);

        $employee->update([
            'name' => $data['name'],
            'email' => $data['email'] ?? null,
            'staff_id' => $data['staff_id'] ?? null,
            // Hire date never silently clears: keep the existing value when left blank,
            // falling back to today — matching store() / import().
            'joined_at' => $data['joined_at'] ?? $employee->joined_at ?? now()->toDateString(),
            'date_of_birth' => $data['date_of_birth'] ?? null,
            'salary' => $canSetSalary ? ($data['salary'] ?? null) : $employee->salary,
            'branch_id' => $data['branch_id'] ?? null,
            'employment_type_id' => $data['employment_type_id'] ?? null,
            'reports_to_id' => $data['reports_to_id'] ?? null,
            'status' => $data['status'],
            'initials' => $this->initials($data['name']),
        ] + $this->bandFields(isset($data['position_id']) ? (int) $data['position_id'] : null)
          + $this->arrangementFields($employee, $data['work_arrangement'] ?? $employee->work_arrangement ?? 'office'));

        AuditLog::record('Updated employee', $employee->name);

        return back()->with('ok', $employee->name.' updated.');
    }

    /**
     * Archive a staff member. Sets archived_at, which drops them from the directory and
     * every active picker (Employee::active()), while their payroll / attendance /
     * timesheet / approval history still resolves their name everywhere it is
     * referenced. Restorable by clearing archived_at.
     *
     * Archive also DETACHES their live ties (detachArchivedResponsibilities): the login can
     * no longer act (EnsureNotArchived middleware), their reports move up-chain, reporting
     * pivots drop, open tasks pass to their manager, and pending requests close — so an
     * archived person carries no live responsibility. Historical records are untouched.
     */
    public function destroy(Request $request, Employee $employee, StaffArchiver $archiver): RedirectResponse
    {
        $this->authorizePermission('staff.delete');
        abort_unless($employee->tenant_id === app(CurrentTenant::class)->id(), 403);

        // Archive + detach in one place — the same path the scheduled departed-staff job uses.
        $archiver->archive($employee);

        return redirect('/app/directory')->with('ok', $employee->name.' archived.');
    }

    /**
     * Restore an archived staff member by clearing archived_at — the inverse of
     * destroy(). Returns them to the directory and every active picker. No-op if the
     * person is not archived. Gated by the same staff.delete permission as archiving.
     */
    public function restore(Request $request, Employee $employee): RedirectResponse
    {
        $this->authorizePermission('staff.delete');
        abort_unless($employee->tenant_id === app(CurrentTenant::class)->id(), 403);

        if ($employee->isArchived()) {
            $employee->update(['archived_at' => null]);
            AuditLog::record('Restored employee', $employee->name);
        }

        return redirect('/app/directory?view=archived')->with('ok', $employee->name.' restored.');
    }

    /**
     * Permanently delete an ARCHIVED staff member (HR/management, staff.delete).
     *
     * Guarded hard delete — the terminal step after archive, never a shortcut past it.
     * Cascades the person's operational history (attendance, leave, claims, tasks,
     * goals, documents) and null-outs any subordinate's reports_to_id (nullOnDelete),
     * then revokes their login. REFUSES when the person has payroll records (salary
     * structure / payslips): those carry a retention duty (AK-DB-01, restrictOnDelete),
     * so such a person can only stay archived. Unlike destroy() — which archives — this
     * is irreversible.
     */
    public function forceDelete(Request $request, Employee $employee): RedirectResponse
    {
        $this->authorizePermission('staff.delete');
        abort_unless($employee->tenant_id === app(CurrentTenant::class)->id(), 403);

        // Delete only follows archive — the UI exposes it on archived rows only, and the
        // server holds the same line so a crafted request can't skip the archive step.
        if (! $employee->isArchived()) {
            return redirect('/app/directory?view=archived')
                ->with('error', $employee->name.' must be archived before it can be permanently deleted.');
        }

        // Retention guard (AK-DB-01): payroll data must outlive the directory record. The
        // DB would reject the delete anyway (restrictOnDelete); we pre-check for a clear
        // message instead of surfacing a raw constraint error.
        $hasPayroll = DB::table('salary_structures')->where('employee_id', $employee->id)->exists()
            || DB::table('payslips')->where('employee_id', $employee->id)->exists();

        if ($hasPayroll) {
            return redirect('/app/directory?view=archived')
                ->with('error', $employee->name.' cannot be permanently deleted — they have payroll records (payslips or a salary structure) the company must retain by law. Typing the name only confirms intent; it cannot override retention. Archived is as far as this record goes.');
        }

        $name = $employee->name;
        $tenantId = $employee->tenant_id;
        $user = $employee->user;

        DB::transaction(function () use ($employee, $user, $tenantId) {
            // Cascades child records; sets subordinates' reports_to_id to null.
            $employee->delete();

            // Revoke login. Drop this tenant's membership, then delete the account only if
            // it no longer belongs to any tenant — a shared multi-tenant login survives.
            if ($user) {
                $user->tenants()->detach($tenantId);
                if ($user->tenants()->count() === 0) {
                    $user->delete();
                }
            }
        });

        AuditLog::record('Deleted employee', $name);

        return redirect('/app/directory?view=archived')->with('ok', $name.' permanently deleted.');
    }

    /** Downloadable CSV template for the bulk staff import. */
    public function importTemplate(Request $request): Response
    {
        $this->authorizePermission('staff.import');

        // reports_to holds the manager's full name (matched against other staff, including
        // other rows in this same file). Leave blank for top-level people. The two sample
        // rows show a manager and a report that points back to her by name.
        $headers = ['name', 'email', 'staff_id', 'joined', 'date_of_birth', 'position_band', 'salary', 'branch', 'employment_type', 'status', 'reports_to'];
        $manager = ['Aisyah Rahman', 'aisyah@example.com', 'UR-0001', '2020-01-06', '1985-02-20', 'Manager', '9000', 'Head Office', 'Full-time', 'active', ''];
        $report = ['Ali bin Ahmad', 'ali@example.com', 'UR-0002', '2022-03-14', '1990-05-12', 'Executive', '4500', 'Head Office', 'Full-time', 'active', 'Aisyah Rahman'];
        $csv = implode(',', $headers)."\n".implode(',', $manager)."\n".implode(',', $report)."\n";

        return response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="amanahku-staff-import-template.csv"',
        ]);
    }

    /**
     * Bulk-create staff directory records from an uploaded CSV. Mirrors the New
     * employee form: the position band drives department, job title and staff level
     * (matched by band title), while branch / employment-type are matched by name —
     * all within the current tenant. Unknown names are left blank rather than failing
     * the row. Invalid rows are skipped and reported. Creates directory records only —
     * login accounts are provisioned separately (per-member invite).
     */
    public function import(Request $request): RedirectResponse
    {
        $this->authorizePermission('staff.import');
        $tenantId = app(CurrentTenant::class)->id();

        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:2048']]);

        [$handle, $col, $error] = CsvImport::open($request->file('file'));
        if ($error !== null) {
            return back()->with('error', $error);
        }

        // Tenant-scoped FK lookups (match by name/title, case-insensitively). Position
        // bands match on title so the band derives department / staff level / job title,
        // exactly like the form.
        $key = CsvImport::key(...);
        $positions = Position::get(['id', 'title'])->mapWithKeys(fn ($p) => [$key($p->title) => $p->id]);
        $branches = Branch::get(['id', 'name'])->mapWithKeys(fn ($b) => [$key($b->name) => $b->id]);
        $employmentTypes = EmploymentType::get(['id', 'name'])->mapWithKeys(fn ($e) => [$key($e->name) => $e->id]);

        // Re-uploading is an UPSERT, not insert-only: a row that matches an existing active
        // person (by staff ID, else email, else name) UPDATES that record with the row's
        // non-empty fields — so a second file that just fills in emails enriches the
        // directory instead of being skipped as a duplicate. Existing staff are indexed by
        // all three natural keys; rows created this run are added so a later row updates them.
        $existing = Employee::active()->get();
        $byStaffId = $existing->filter(fn ($e) => $e->staff_id)->keyBy(fn ($e) => $key($e->staff_id));
        $byEmail = $existing->filter(fn ($e) => $e->email)->keyBy(fn ($e) => $key($e->email));
        $byName = $existing->keyBy(fn ($e) => $key($e->name));

        $errors = [];
        $row = 1;
        // Reporting lines are resolved in a second pass: a manager named in one row may
        // be created by a later row, so we can only link names to ids once every row exists.
        $pendingLinks = [];

        // One transaction around the whole import: a mid-file crash leaves nothing
        // behind instead of an unreported partial directory.
        [$created, $updated, $errors, $linked] = DB::transaction(function () use ($handle, $col, $positions, $branches, $employmentTypes, $key, $tenantId, $errors, $row, $pendingLinks, $byStaffId, $byEmail, $byName) {
            $created = 0;
            $updated = 0;

            while (($data = fgetcsv($handle)) !== false) {
                $row++;
                if ($created + $updated >= CsvImport::ROW_CAP) {
                    $errors[] = 'Stopped at '.CsvImport::ROW_CAP.' rows.';
                    break;
                }

                $get = fn (string $k) => CsvImport::cell($data, $col, $k);

                $name = $get('name');
                if ($name === '') {
                    if (trim(implode('', $data)) === '') {
                        continue; // blank line
                    }
                    $errors[] = "Row $row: name is required.";

                    continue;
                }

                $email = $get('email');
                if ($email !== '' && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $errors[] = "Row $row: invalid email.";

                    continue;
                }

                $staffId = $get('staff_id');

                // Validate the row's provided attributes once — used by both create + update.
                $statusRaw = strtolower(str_replace(' ', '_', $get('status')));
                $statusGiven = $get('status') !== '' && in_array($statusRaw, ['active', 'probation', 'on_leave', 'resigned'], true);

                $dob = $get('date_of_birth');
                $dobValue = null;
                if ($dob !== '') {
                    $dobValue = $this->parseIsoDate($dob);
                    if ($dobValue === null) {
                        $errors[] = "Row $row: invalid date of birth (use YYYY-MM-DD).";

                        continue;
                    }
                }

                $joined = $get('joined');
                $joinedValue = null;
                if ($joined !== '') {
                    $joinedValue = $this->parseIsoDate($joined);
                    if ($joinedValue === null) {
                        $errors[] = "Row $row: invalid joined date (use YYYY-MM-DD).";

                        continue;
                    }
                }

                // Tolerate spreadsheet formatting: thousands separators (7,250.00),
                // stray spaces and a leading currency symbol (RM 7250) all normalise to a
                // plain number. A cell that still isn't numeric is left as null.
                $salaryRaw = trim(str_replace([',', ' ', 'RM', 'rm'], '', $get('salary')));
                $salary = ($salaryRaw !== '' && is_numeric($salaryRaw)) ? (float) $salaryRaw : null;

                $positionId = $positions[$key($get('position_band'))] ?? null;
                $branchId = $get('branch') !== '' ? ($branches[$key($get('branch'))] ?? null) : null;
                $etId = $get('employment_type') !== '' ? ($employmentTypes[$key($get('employment_type'))] ?? null) : null;

                // Find an existing record to update: staff ID, then email, then name.
                $match = ($staffId !== '' ? $byStaffId->get($key($staffId)) : null)
                    ?? ($email !== '' ? $byEmail->get($key($email)) : null)
                    ?? $byName->get($key($name));

                // The row's email must not already belong to a DIFFERENT active person.
                if ($email !== '') {
                    $emailOwner = $byEmail->get($key($email));
                    if ($emailOwner && (! $match || $emailOwner->id !== $match->id)) {
                        $errors[] = "Row $row: email already used by another staff member.";

                        continue;
                    }
                }

                if ($match) {
                    // UPSERT — overwrite only the fields the row actually provides, so a
                    // blank cell never wipes existing data.
                    $fields = [];
                    if ($email !== '') {
                        $fields['email'] = $email;
                    }
                    if ($staffId !== '') {
                        $fields['staff_id'] = $staffId;
                    }
                    if ($joinedValue !== null) {
                        $fields['joined_at'] = $joinedValue;
                    }
                    if ($dobValue !== null) {
                        $fields['date_of_birth'] = $dobValue;
                    }
                    if ($salary !== null) {
                        $fields['salary'] = $salary;
                    }
                    if ($branchId !== null) {
                        $fields['branch_id'] = $branchId;
                    }
                    if ($etId !== null) {
                        $fields['employment_type_id'] = $etId;
                    }
                    if ($statusGiven) {
                        $fields['status'] = $statusRaw;
                    }
                    if ($positionId) {
                        $fields += $this->bandFields($positionId);
                    }

                    if ($fields !== []) {
                        $match->update($fields);
                        $updated++;
                        if ($email !== '') {
                            $byEmail->put($key($email), $match);
                        }
                        if ($staffId !== '') {
                            $byStaffId->put($key($staffId), $match);
                        }
                    }

                    $managerName = $get('reports_to');
                    if ($managerName !== '') {
                        $pendingLinks[] = ['emp' => $match, 'manager' => $managerName];
                    }

                    continue;
                }

                // CREATE — a new person.
                $employee = Employee::create([
                    'tenant_id' => $tenantId,
                    'name' => $name,
                    'email' => $email ?: null,
                    'staff_id' => $staffId ?: null,
                    'joined_at' => $joinedValue ?? now()->toDateString(),
                    'date_of_birth' => $dobValue,
                    'salary' => $salary,
                    'branch_id' => $branchId,
                    'employment_type_id' => $etId,
                    'status' => $statusGiven ? $statusRaw : 'active',
                    'workload' => 'green',
                    'workload_label' => 'Healthy',
                    'initials' => $this->initials($name),
                    'avatar_color' => config('amanahku.avatar_color'),
                ] + $this->bandFields($positionId));
                $created++;

                // Register so a later row in THIS file updates it instead of duplicating.
                $byName->put($key($name), $employee);
                if ($email !== '') {
                    $byEmail->put($key($email), $employee);
                }
                if ($staffId !== '') {
                    $byStaffId->put($key($staffId), $employee);
                }

                $managerName = $get('reports_to');
                if ($managerName !== '') {
                    $pendingLinks[] = ['emp' => $employee, 'manager' => $managerName];
                }
            }

            $linked = $this->applyImportedReportingLines($pendingLinks, $key);

            return [$created, $updated, $errors, $linked];
        });
        fclose($handle);

        AuditLog::record('Imported staff', $created.' created, '.$updated.' updated');

        $bits = [];
        if ($updated > 0) {
            $bits[] = "$updated updated.";
        }
        if ($linked > 0) {
            $bits[] = "$linked reporting line(s) set.";
        }
        $msg = CsvImport::summary($created, 'staff imported', $errors, implode(' ', $bits));

        return back()->with($errors !== [] ? 'error' : 'ok', $msg);
    }

    /**
     * Second-pass resolver for CSV reporting lines. Each pending link names a manager
     * by full name; we match it against the full active staff set (so a manager created
     * in the same file resolves too) and set reports_to_id. Unmatched names, self-links
     * and links that would loop the org tree are silently skipped — the directory record
     * is still created, just without a reporting line. Returns how many links were set.
     *
     * @param  array<int, array{emp: Employee, manager: string}>  $pendingLinks
     * @param  callable(string):string  $key  Name normaliser (lower + trim), shared with import().
     */
    private function applyImportedReportingLines(array $pendingLinks, callable $key): int
    {
        if ($pendingLinks === []) {
            return 0;
        }

        $byName = Employee::active()->get(['id', 'name'])->keyBy(fn ($e) => $key($e->name));

        $linked = 0;
        foreach ($pendingLinks as $link) {
            $managerId = $byName[$key($link['manager'])]->id ?? null;
            if (! $managerId || $managerId === $link['emp']->id || $this->wouldCycle($link['emp']->id, $managerId)) {
                continue;
            }

            $link['emp']->update(['reports_to_id' => $managerId]);
            $linked++;
        }

        return $linked;
    }

    /**
     * Gate a staff action by the acting user's effective permission (role + per-user
     * overrides) in the active tenant. By default management/hr hold staff.* and
     * employee/manager do not — matching the previous role gate — but an override can
     * now grant or deny a specific member.
     */
    private function authorizePermission(string $permission): void
    {
        $tenant = app(CurrentTenant::class)->get();
        $user = request()->user();

        abort_unless($tenant && $user && $user->canInTenant($tenant, $permission), 403);
    }

    /** Tenant-scoped existence rule for a foreign-key option. */
    private function inTenant(string $table, int $tenantId): Exists
    {
        return Rule::exists($table, 'id')->where('tenant_id', $tenantId);
    }

    /**
     * Uniqueness of a column among the tenant's ACTIVE (non-archived) employees (AK-DB-03).
     * Pass $ignoreId on update() so a row does not clash with itself.
     */
    private function activeUnique(string $column, int $tenantId, ?int $ignoreId = null): Unique
    {
        $rule = Rule::unique('employees', $column)
            ->where(fn ($q) => $q->where('tenant_id', $tenantId)->whereNull('archived_at'));

        return $ignoreId ? $rule->ignore($ignoreId) : $rule;
    }

    /** Empty <select> values arrive as '' — treat them as null so nullable+exists pass. */
    private function nullifyEmptyFks(Request $request): void
    {
        $request->merge([
            'position_id' => $request->input('position_id') ?: null,
            'branch_id' => $request->input('branch_id') ?: null,
            'employment_type_id' => $request->input('employment_type_id') ?: null,
            'reports_to_id' => $request->input('reports_to_id') ?: null,
        ]);
    }

    /**
     * Would pointing $employeeId at $managerId form a reporting loop? Walks up the
     * proposed manager's chain; a cycle exists if we reach the employee being edited.
     * The visited guard also breaks on any pre-existing loop in stored data, so this
     * can never spin. Tenant-scoped automatically via the Employee global scope.
     */
    private function wouldCycle(int $employeeId, int $managerId): bool
    {
        $cursor = $managerId;
        $seen = [];

        while ($cursor !== null) {
            if ($cursor === $employeeId) {
                return true;
            }
            if (isset($seen[$cursor])) {
                break;
            }
            $seen[$cursor] = true;
            $cursor = Employee::whereKey($cursor)->value('reports_to_id');
        }

        return false;
    }

    /**
     * Parse a CSV date strictly as ISO YYYY-MM-DD (the format the import template
     * ships). Anything else — including ambiguous DD/MM/YYYY that Carbon::parse would
     * silently mis-read as MM/DD — returns null so the row is reported as invalid
     * rather than stored with a wrong date.
     */
    private function parseIsoDate(string $value): ?string
    {
        try {
            $date = Carbon::createFromFormat('!Y-m-d', trim($value));
        } catch (\Throwable) {
            return null;
        }

        $parseErrors = Carbon::getLastErrors();
        if ($date === false || ($parseErrors && ($parseErrors['error_count'] > 0 || $parseErrors['warning_count'] > 0))) {
            return null;
        }

        return $date->toDateString();
    }

    /**
     * Fields derived from the chosen Position band: department, staff level and the
     * job title all follow the band so they stay consistent with the rate card. The
     * legacy free-text grade (level) is cleared. Passing null clears the band.
     *
     * @return array<string,mixed>
     */
    private function bandFields(?int $positionId): array
    {
        $band = $positionId ? Position::find($positionId) : null;

        return [
            'position_id' => $band?->id,
            'department_id' => $band?->department_id,
            'staff_level_id' => $band?->staff_level_id,
            'position' => $band?->title,
            'level' => null,
        ];
    }

    /**
     * Work-arrangement fields for the profile edit form. Sets work_arrangement and, only
     * when the arrangement actually changes, clears the detail fields that no longer apply
     * (client site link off client; office-day split off hybrid). Leaving them untouched on
     * an unchanged arrangement preserves the client site / hybrid days configured on the
     * Attendance Setup screen — this form has no inputs for those, so a blind clear would
     * wipe them. The fuller per-employee editor (site picker, weekday split, home reset)
     * still lives on Attendance Setup (AttendanceAdminController::updateEmployee).
     *
     * @return array<string,mixed>
     */
    private function arrangementFields(Employee $employee, string $arrangement): array
    {
        $fields = ['work_arrangement' => $arrangement];

        if ($arrangement !== $employee->work_arrangement) {
            if ($arrangement !== 'client') {
                $fields['work_site_id'] = null;
            }
            if ($arrangement !== 'hybrid') {
                $fields['hybrid_office_days'] = null;
            }
        }

        return $fields;
    }

    private function initials(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $first = mb_substr($parts[0] ?? '', 0, 1);
        $last = count($parts) > 1 ? mb_substr((string) end($parts), 0, 1) : '';

        return mb_strtoupper($first.$last) ?: 'NA';
    }
}
