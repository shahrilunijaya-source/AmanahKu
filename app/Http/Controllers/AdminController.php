<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmploymentType;
use App\Models\PettyCashFloat;
use App\Models\StaffLevel;
use App\Models\User;
use App\Models\UserPermission;
use App\Services\FeatureManager;
use App\Support\Features;
use App\Support\Permissions;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminController extends Controller
{
    private const ADMIN_ROLES = ['management', 'hr'];

    // 'director' is management + final-approval authority (Permissions::MANAGEMENT_TIER);
    // assignable from the roles screen like any other role.
    private const ASSIGNABLE_ROLES = ['employee', 'manager', 'management', 'director', 'hr'];

    /**
     * Company-admin company profile editing. Scope is deliberately limited to
     * branding + contact details. Category, subscription plan, slug, status and
     * subscription dates are super-admin-only — they are not in the form and are
     * never accepted here, so a company admin cannot escalate their own entitlement.
     */
    public function updateSettings(Request $request): RedirectResponse
    {
        $this->authorizeAdmin($request);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'industry' => ['nullable', 'string', 'max:120'],
            'address' => ['nullable', 'string', 'max:240'],
            'contact_number' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:160'],
            'website' => ['nullable', 'url', 'max:160'],
            'welcome_message' => ['nullable', 'string', 'max:240'],
            'color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'secondary_color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'logo' => ['nullable', 'image', 'max:2048'],
        ]);

        $tenant = app(CurrentTenant::class)->get();

        $update = [
            'name' => $data['name'],
            'industry' => $data['industry'] ?? null,
            'address' => $data['address'] ?? null,
            'contact_number' => $data['contact_number'] ?? null,
            'email' => $data['email'] ?? null,
            'website' => $data['website'] ?? null,
            'welcome_message' => $data['welcome_message'] ?? null,
            // Colours fall back to the current value so a blank field never wipes branding.
            'color' => $data['color'] ?? $tenant->color,
            'secondary_color' => $data['secondary_color'] ?? $tenant->secondary_color,
        ];

        if ($request->hasFile('logo')) {
            $update['logo_path'] = $request->file('logo')->store('logos', 'public');
        }

        $tenant->update($update);
        AuditLog::record('Updated company settings', $data['name']);

        return back()->with('ok', 'Company settings saved.');
    }

    /**
     * Persist this company's feature overrides. Only tenant-scope keys are
     * accepted; platform-scope keys (e.g. platform.registration) are never
     * exposed here. A LOCKED key is rejected — the override would be a no-op
     * anyway since locked keys resolve to the platform value.
     */
    public function updateFeatures(Request $request): RedirectResponse
    {
        $this->authorizeAdmin($request);

        $tenant = app(CurrentTenant::class)->get();
        $features = app(FeatureManager::class);
        $submitted = (array) $request->input('features', []);

        $applied = 0;
        $rejected = [];

        foreach ($this->tenantFeatureKeys() as $key) {
            if (! array_key_exists($key, $submitted)) {
                // Unchecked bool modules/settings don't appear in the payload; fall
                // back to a falsy value so a toggle-off persists. Enum keys always
                // submit, so a missing enum simply means "not on this form".
                if ($this->isBoolKey($key) && $request->has('features_present')) {
                    $value = false;
                } else {
                    continue;
                }
            } else {
                $value = $submitted[$key];
            }

            // Never let a tenant override a locked feature.
            if ($features->platformLocked($key)) {
                $rejected[] = $key;

                continue;
            }

            $casted = $this->validateFeatureValue($key, $value);
            if ($casted === null && ! $this->isBoolKey($key)) {
                $rejected[] = $key;

                continue;
            }

            $features->setTenant($tenant, $key, $casted);
            $applied++;
        }

        AuditLog::record('Updated feature settings', $applied.' feature(s)');

        $msg = $applied.' feature setting'.($applied === 1 ? '' : 's').' saved.';
        if ($rejected !== []) {
            $msg .= ' Locked or invalid keys were ignored.';
        }

        return back()->with('ok', $msg);
    }

    /** Tenant-scope feature keys: every module + non-platform setting. */
    private function tenantFeatureKeys(): array
    {
        $keys = array_keys(Features::MODULES);
        foreach (Features::SETTINGS as $key => $meta) {
            if (($meta['scope'] ?? 'tenant') === 'tenant') {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    private function isBoolKey(string $key): bool
    {
        if (isset(Features::MODULES[$key])) {
            return true;
        }

        return (Features::meta($key)['type'] ?? null) === 'bool';
    }

    /**
     * Validate + cast a submitted value against the registry. Returns the casted
     * value, or null when an enum value is not in its allowed options.
     */
    private function validateFeatureValue(string $key, mixed $value): mixed
    {
        $meta = Features::meta($key);

        if ($meta && ($meta['type'] ?? null) === 'enum') {
            return array_key_exists((string) $value, $meta['options']) ? (string) $value : null;
        }

        if ($meta && ($meta['type'] ?? null) === 'number') {
            if (! is_numeric($value)) {
                return null;
            }

            return max($meta['min'] ?? 0, min($meta['max'] ?? PHP_FLOAT_MAX, (float) $value));
        }

        // Modules + bool settings.
        return Features::asBool($value);
    }

    public function updateRole(Request $request, User $user): RedirectResponse
    {
        $this->authorizeAdmin($request);

        $tenant = app(CurrentTenant::class)->get();
        abort_unless($user->tenants->contains('id', $tenant->id), 404);

        $role = $request->validate(['role' => ['required', 'in:'.implode(',', self::ASSIGNABLE_ROLES)]])['role'];

        $user->tenants()->updateExistingPivot($tenant->id, ['role' => $role]);
        AuditLog::record('Changed role', $user->name.' → '.$role);

        return back()->with('ok', $user->name.' is now '.ucfirst($role).'.');
    }

    /**
     * Set a member's data access scope (own/team/department/branch/company). Layered on
     * the role: the role decides WHAT a member can do, the scope decides WHICH records
     * they see. 'company' keeps full visibility (the default).
     */
    public function updateScope(Request $request, User $user): RedirectResponse
    {
        $this->authorizeAdmin($request);

        $tenant = app(CurrentTenant::class)->get();
        abort_unless($user->tenants->contains('id', $tenant->id), 404);

        $scope = $request->validate(['data_scope' => ['required', Rule::in(Permissions::SCOPES)]])['data_scope'];

        $user->tenants()->updateExistingPivot($tenant->id, ['data_scope' => $scope]);
        AuditLog::record('Changed data scope', $user->name.' → '.$scope);

        return back()->with('ok', $user->name.' scope set to '.Permissions::SCOPE_LABELS[$scope].'.');
    }

    /**
     * Set per-user permission overrides (inherit / grant / deny) for a member. An
     * override is only persisted when it differs from what the member's role already
     * grants — a redundant override is dropped so the record stays minimal.
     */
    public function updatePermissions(Request $request, User $user): RedirectResponse
    {
        $this->authorizeAdmin($request);

        $tenant = app(CurrentTenant::class)->get();
        abort_unless($user->tenants->contains('id', $tenant->id), 404);

        $submitted = (array) $request->input('perm', []);
        $rolePerms = Permissions::forRole($user->roleIn($tenant));

        // Only persist overrides for permissions that are actually enforced (AK-AUTHZ-04) —
        // a crafted POST for an unenforced key is ignored, never stored as a dead override.
        foreach (Permissions::overridable() as $perm) {
            $choice = $submitted[$perm] ?? 'inherit';
            $roleGrants = in_array($perm, $rolePerms, true);

            $base = UserPermission::where('tenant_id', $tenant->id)->where('user_id', $user->id)->where('permission', $perm);

            // Inherit, or an override that just restates the role, means "no override".
            if ($choice === 'inherit'
                || ($choice === 'grant' && $roleGrants)
                || ($choice === 'deny' && ! $roleGrants)) {
                $base->delete();

                continue;
            }

            UserPermission::updateOrCreate(
                ['tenant_id' => $tenant->id, 'user_id' => $user->id, 'permission' => $perm],
                ['granted' => $choice === 'grant'],
            );
        }

        AuditLog::record('Updated permission overrides', $user->name);

        return back()->with('ok', 'Permission overrides saved for '.$user->name.'.');
    }

    // --- Branches -----------------------------------------------------------
    // Name + state only. Geofence/working-hours stay on the Attendance Setup
    // screen (AttendanceAdminController::updateBranch). tenant_id is auto-filled
    // and route-model binding is tenant-scoped via BelongsToTenant.

    private const LOCATION_TYPES = ['Headquarters', 'Branch', 'Office', 'Outlet', 'Project Site', 'Operational', 'Other'];

    public function storeBranch(Request $request): RedirectResponse
    {
        $this->authorizeAdmin($request);

        $data = $this->validateBranch($request);
        $branch = Branch::create($data);
        AuditLog::record('Added branch', $branch->name);

        return back()->with('ok', $branch->name.' added.');
    }

    public function updateBranch(Request $request, Branch $branch): RedirectResponse
    {
        $this->authorizeAdmin($request);
        $this->assertTenant($branch->tenant_id);

        $branch->update($this->validateBranch($request, $branch->id));
        AuditLog::record('Updated branch', $branch->name);

        return back()->with('ok', $branch->name.' updated.');
    }

    public function deleteBranch(Request $request, Branch $branch): RedirectResponse
    {
        $this->authorizeAdmin($request);
        $this->assertTenant($branch->tenant_id);

        // Block deletion only while ACTIVE staff are assigned: employees.branch_id is
        // nullOnDelete so they'd null out safely, but silently unassigning live staff
        // is bad UX — make HR reassign first. Archived staff are intentionally NOT
        // counted: they're hidden from the directory (unreachable for reassignment) and
        // their branch_id nulls harmlessly on delete, so counting them would wedge the
        // branch permanently. petty_cash_floats.branch_id is cascadeOnDelete, so a
        // branch in use would silently wipe financial records — block that outright.
        if (Employee::active()->where('branch_id', $branch->id)->exists()) {
            return back()->with('error', $branch->name.' has active employees assigned. Reassign them first.');
        }
        if (PettyCashFloat::where('branch_id', $branch->id)->exists()) {
            return back()->with('error', $branch->name.' has petty cash records and cannot be deleted.');
        }

        $name = $branch->name;
        $branch->delete();
        AuditLog::record('Deleted branch', $name);

        return back()->with('ok', $name.' deleted.');
    }

    /** @return array<string,mixed> */
    private function validateBranch(Request $request, ?int $ignoreId = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'state' => ['nullable', 'string', 'max:120'],
            'code' => ['nullable', 'string', 'max:40', Rule::unique('branches', 'code')->where('tenant_id', app(CurrentTenant::class)->id())->ignore($ignoreId)],
            'type' => ['nullable', Rule::in(self::LOCATION_TYPES)],
            'address' => ['nullable', 'string', 'max:240'],
            'contact_number' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:160'],
            'status' => ['nullable', 'in:active,inactive'],
            'effective_date' => ['nullable', 'date'],
        ]);
    }

    /** Location types exposed to the branch form. */
    public function locationTypes(): array
    {
        return self::LOCATION_TYPES;
    }

    // --- Departments --------------------------------------------------------

    public function storeDepartment(Request $request): RedirectResponse
    {
        $this->authorizeAdmin($request);

        $data = $request->validate(['name' => ['required', 'string', 'max:120']]);
        $department = Department::create($data);
        AuditLog::record('Added department', $department->name);

        return back()->with('ok', $department->name.' added.');
    }

    public function updateDepartment(Request $request, Department $department): RedirectResponse
    {
        $this->authorizeAdmin($request);
        $this->assertTenant($department->tenant_id);

        $data = $request->validate(['name' => ['required', 'string', 'max:120']]);
        $department->update($data);
        // Position bands reference departments by FK, so the rename is reflected live.
        AuditLog::record('Updated department', $department->name);

        return back()->with('ok', $department->name.' updated.');
    }

    public function deleteDepartment(Request $request, Department $department): RedirectResponse
    {
        $this->authorizeAdmin($request);
        $this->assertTenant($department->tenant_id);

        // employees.department_id is nullOnDelete, but silently unassigning active
        // staff is bad UX — block while the department is still in use. Archived staff
        // aren't counted: they're hidden from the directory (unreachable for
        // reassignment) and their department_id nulls harmlessly on delete, so counting
        // them would wedge the department permanently.
        if ($department->employees()->active()->exists()) {
            return back()->with('error', $department->name.' has active employees assigned. Reassign them first.');
        }

        $name = $department->name;
        $department->delete();
        AuditLog::record('Deleted department', $name);

        return back()->with('ok', $name.' deleted.');
    }

    // --- Staff levels (grades) -------------------------------------------------

    public function storeStaffLevel(Request $request): RedirectResponse
    {
        $this->authorizeAdmin($request);

        $level = StaffLevel::create($this->validateStaffLevel($request));
        AuditLog::record('Added staff level', $level->name);

        return back()->with('ok', $level->name.' added.');
    }

    public function updateStaffLevel(Request $request, StaffLevel $staffLevel): RedirectResponse
    {
        $this->authorizeAdmin($request);
        $this->assertTenant($staffLevel->tenant_id);

        $staffLevel->update($this->validateStaffLevel($request, $staffLevel->id));
        // Position bands reference staff levels by FK, so the rename is reflected live.
        AuditLog::record('Updated staff level', $staffLevel->name);

        return back()->with('ok', $staffLevel->name.' updated.');
    }

    public function deleteStaffLevel(Request $request, StaffLevel $staffLevel): RedirectResponse
    {
        $this->authorizeAdmin($request);
        $this->assertTenant($staffLevel->tenant_id);

        if ($staffLevel->employees()->exists()) {
            return back()->with('error', $staffLevel->name.' is assigned to staff. Reassign them first.');
        }

        $name = $staffLevel->name;
        $staffLevel->delete();
        AuditLog::record('Deleted staff level', $name);

        return back()->with('ok', $name.' deleted.');
    }

    /** @return array{name:string,code:?string,rank:?int} */
    private function validateStaffLevel(Request $request, ?int $ignoreId = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:80', Rule::unique('staff_levels', 'name')->where('tenant_id', app(CurrentTenant::class)->id())->ignore($ignoreId)],
            'code' => ['nullable', 'string', 'max:20'],
            'rank' => ['nullable', 'integer', 'min:0', 'max:65535'],
        ]);
    }

    // --- Employment types ------------------------------------------------------

    public function storeEmploymentType(Request $request): RedirectResponse
    {
        $this->authorizeAdmin($request);

        $type = EmploymentType::create($this->validateEmploymentType($request));
        AuditLog::record('Added employment type', $type->name);

        return back()->with('ok', $type->name.' added.');
    }

    public function updateEmploymentType(Request $request, EmploymentType $employmentType): RedirectResponse
    {
        $this->authorizeAdmin($request);
        $this->assertTenant($employmentType->tenant_id);

        $employmentType->update($this->validateEmploymentType($request, $employmentType->id));
        AuditLog::record('Updated employment type', $employmentType->name);

        return back()->with('ok', $employmentType->name.' updated.');
    }

    public function deleteEmploymentType(Request $request, EmploymentType $employmentType): RedirectResponse
    {
        $this->authorizeAdmin($request);
        $this->assertTenant($employmentType->tenant_id);

        if ($employmentType->employees()->exists()) {
            return back()->with('error', $employmentType->name.' is assigned to staff. Reassign them first.');
        }

        $name = $employmentType->name;
        $employmentType->delete();
        AuditLog::record('Deleted employment type', $name);

        return back()->with('ok', $name.' deleted.');
    }

    /** @return array{name:string,code:?string} */
    private function validateEmploymentType(Request $request, ?int $ignoreId = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:80', Rule::unique('employment_types', 'name')->where('tenant_id', app(CurrentTenant::class)->id())->ignore($ignoreId)],
            'code' => ['nullable', 'string', 'max:20'],
        ]);
    }

    private function authorizeAdmin(Request $request): void
    {
        $this->authorizeTenantRole($request, self::ADMIN_ROLES);
    }

    /** Block any write against a row owned by a different tenant. */
    private function assertTenant(int $tenantId): void
    {
        abort_unless($tenantId === app(CurrentTenant::class)->id(), 403);
    }
}
