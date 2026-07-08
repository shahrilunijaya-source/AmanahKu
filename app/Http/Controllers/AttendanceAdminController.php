<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\WorkSite;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * HR setup for geofenced attendance: branch geofences + hours, client sites for resident
 * engineers, and per-employee work arrangements. Privileged (management / HR) only.
 */
class AttendanceAdminController extends Controller
{
    private const PRIVILEGED_ROLES = ['management', 'hr'];

    /** Data for the Attendance Setup screen. */
    public function screenData(Request $request): array
    {
        return [
            'branches' => Branch::orderBy('name')->get(),
            'sites' => WorkSite::orderBy('name')->get(),
            'staff' => Employee::active()->with(['branch', 'workSite'])->orderBy('name')->get(),
            'wfhPolicy' => app(CurrentTenant::class)->get(),
        ];
    }

    /** Set a branch's office geofence + working hours. */
    public function updateBranch(Request $request, Branch $branch): RedirectResponse
    {
        $this->authorize($request);
        $this->assertTenant($branch->tenant_id);

        $data = $this->validateGeofence($request);
        $branch->update($data);
        AuditLog::record('Updated branch geofence', $branch->name);

        return back()->with('ok', $branch->name.' geofence saved.');
    }

    /** Create a client site (resident-engineer location). */
    public function storeSite(Request $request): RedirectResponse
    {
        $this->authorize($request);

        $data = $this->validateSite($request);
        $site = WorkSite::create($data);
        AuditLog::record('Added client site', $site->name);

        return back()->with('ok', $site->name.' added.');
    }

    public function updateSite(Request $request, WorkSite $site): RedirectResponse
    {
        $this->authorize($request);
        $this->assertTenant($site->tenant_id);

        $site->update($this->validateSite($request));
        AuditLog::record('Updated client site', $site->name);

        return back()->with('ok', $site->name.' updated.');
    }

    public function deleteSite(Request $request, WorkSite $site): RedirectResponse
    {
        $this->authorize($request);
        $this->assertTenant($site->tenant_id);

        $name = $site->name;
        $site->delete(); // employees.work_site_id is nullOnDelete

        AuditLog::record('Removed client site', $name);

        return back()->with('ok', $name.' removed.');
    }

    /** Assign an employee's work arrangement, client site, and hybrid weekday split. */
    public function updateEmployee(Request $request, Employee $employee): RedirectResponse
    {
        $this->authorize($request);
        $this->assertTenant($employee->tenant_id);
        $tenantId = app(CurrentTenant::class)->id();

        $data = $request->validate([
            'work_arrangement' => ['required', 'in:office,client,wfh,hybrid'],
            'work_site_id' => ['nullable', 'integer', Rule::exists('work_sites', 'id')->where('tenant_id', $tenantId)],
            'hybrid_office_days' => ['nullable', 'array'],
            'hybrid_office_days.*' => ['integer', 'between:1,7'],
            'reset_home' => ['nullable', 'boolean'],
        ]);

        $arrangement = $data['work_arrangement'];

        $attributes = [
            'work_arrangement' => $arrangement,
            // Only a client arrangement keeps a client-site link.
            'work_site_id' => $arrangement === 'client' ? ($data['work_site_id'] ?? null) : null,
            // Only hybrid keeps a weekday split.
            'hybrid_office_days' => $arrangement === 'hybrid'
                ? array_values(array_unique(array_map('intval', $data['hybrid_office_days'] ?? [])))
                : null,
        ];

        // Clear a registered home so it re-captures on the next home clock-in.
        if (! empty($data['reset_home'])) {
            $attributes['home_latitude'] = null;
            $attributes['home_longitude'] = null;
            $attributes['home_locked_at'] = null;
        }

        $employee->update($attributes);
        AuditLog::record('Updated work arrangement', $employee->name);

        return back()->with('ok', $employee->name.' arrangement saved.');
    }

    /**
     * Set the single company-wide work-from-home policy (hours + geofence radius) for this
     * tenant. Every WFH / hybrid home day follows these hours (see ScheduleResolver::homeSite),
     * independent of any branch — so deleting a branch never changes WFH hours.
     */
    public function updateWfhPolicy(Request $request): RedirectResponse
    {
        $this->authorize($request);

        $data = $request->validate([
            'wfh_work_start' => ['nullable', 'date_format:H:i'],
            'wfh_work_end' => ['nullable', 'date_format:H:i'],
            'wfh_min_hours' => ['nullable', 'numeric', 'between:0,24'],
            'wfh_radius_m' => ['nullable', 'integer', 'between:20,5000'],
        ]);

        $tenant = app(CurrentTenant::class)->get();
        abort_unless($tenant !== null, 403);

        $tenant->update($data);
        AuditLog::record('Updated WFH policy', $tenant->name);

        return back()->with('ok', 'Work-from-home policy saved.');
    }

    /**
     * Register (or move) a work-from-home / hybrid employee's home geofence from the map,
     * instead of waiting for it to capture automatically on their first home clock-in.
     */
    public function updateHome(Request $request, Employee $employee): RedirectResponse
    {
        $this->authorize($request);
        $this->assertTenant($employee->tenant_id);

        abort_unless(in_array($employee->work_arrangement, ['wfh', 'hybrid'], true), 422);

        $data = $request->validate([
            'home_latitude' => ['required', 'numeric', 'between:-90,90'],
            'home_longitude' => ['required', 'numeric', 'between:-180,180'],
        ]);

        $employee->update([
            'home_latitude' => $data['home_latitude'],
            'home_longitude' => $data['home_longitude'],
            'home_locked_at' => now(),
        ]);
        AuditLog::record('Registered home address', $employee->name);

        return back()->with('ok', $employee->name.' home address saved.');
    }

    /** @return array<string,mixed> */
    private function validateGeofence(Request $request): array
    {
        return $request->validate([
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'radius_m' => ['required', 'integer', 'between:20,5000'],
            'work_start' => ['nullable', 'date_format:H:i'],
            'work_end' => ['nullable', 'date_format:H:i'],
            'min_hours' => ['nullable', 'numeric', 'between:0,24'],
        ]);
    }

    /** @return array<string,mixed> */
    private function validateSite(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'client' => ['nullable', 'string', 'max:120'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'radius_m' => ['required', 'integer', 'between:20,5000'],
            'work_start' => ['nullable', 'date_format:H:i'],
            'work_end' => ['nullable', 'date_format:H:i'],
            'min_hours' => ['nullable', 'numeric', 'between:0,24'],
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
