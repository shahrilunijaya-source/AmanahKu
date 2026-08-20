<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AttendanceRecord;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\WorkSite;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * HR setup for geofenced attendance: branch geofences + hours, client sites for resident
 * engineers, and per-employee work arrangements. Privileged (management / HR) only.
 */
class AttendanceAdminController extends Controller
{
    private const PRIVILEGED_ROLES = ['management', 'hr'];

    /**
     * Reversing a punch (undoing a misclick that clocked someone in or out) is a step above
     * the rest of this screen: 'management' — a plain manager elevated for setup — is left
     * out on purpose, only HR, a director (board tier), or a super-admin observer may do it.
     */
    private const REVERSE_ROLES = ['hr', 'director'];

    /** Clock-in/out selfies live on the private disk — mirrors AttendanceController. */
    private const PHOTO_DISK = 'local';

    /** Data for the Attendance Setup screen. */
    public function screenData(Request $request): array
    {
        return [
            'sites' => WorkSite::orderBy('name')->get(),
            'staff' => Employee::active()->with(['branch', 'workSite'])->orderBy('name')->get(),
            'wfhPolicy' => app(CurrentTenant::class)->get(),
        ];
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

        $employee->update($attributes);
        AuditLog::record('Updated work arrangement', $employee->name);

        return back()->with('ok', $employee->name.' arrangement saved.');
    }

    /**
     * Set the single company-wide work-from-home policy (hours) for this
     * tenant. Every WFH / hybrid home day follows these hours (see ScheduleResolver::homeSite),
     * independent of any branch — so deleting a branch never changes WFH hours.
     *
     * late_grace_minutes posts separately here too: it is not WFH-specific, but this is the
     * one company-wide attendance policy endpoint, and ClockService::isLate() reads it for
     * every arrangement (office, client, WFH, hybrid alike).
     */
    public function updateWfhPolicy(Request $request): RedirectResponse
    {
        $this->authorize($request);

        $data = $request->validate([
            'wfh_work_start' => ['nullable', 'date_format:H:i'],
            'wfh_work_end' => ['nullable', 'date_format:H:i'],
            'wfh_min_hours' => ['nullable', 'numeric', 'between:0,24'],
            // sometimes: the WFH-hours form posts to this same endpoint and omits this key
            // entirely. required: a cleared box still posts the key as '', which
            // ConvertEmptyStringsToNull turns into null — reject that instead of zeroing the
            // company's grace. A deliberate 0 still passes required (only null/''/[] fail it).
            'late_grace_minutes' => ['sometimes', 'required', 'integer', 'between:0,120'],
        ]);

        $tenant = app(CurrentTenant::class)->get();
        abort_unless($tenant !== null, 403);

        $tenant->update($data);
        AuditLog::record('Updated WFH policy', $tenant->name);

        return back()->with('ok', 'Work-from-home policy saved.');
    }

    /**
     * Undo the most recent punch on a record: clears clock-out if the record has one (the
     * employee stays clocked in and can clock out again), otherwise deletes the whole record
     * (the employee stays not-clocked-in and can clock in again). Never partially undoes a
     * clock-in that already has a clock-out — clear the clock-out first.
     */
    public function reversePunch(Request $request, AttendanceRecord $record): RedirectResponse
    {
        $this->authorizeReverse($request);
        $this->assertTenant($record->tenant_id);

        $employee = $record->employee;
        abort_unless($record->clock_in !== null, 422);

        if ($record->clock_out !== null) {
            if ($record->clock_out_photo_path) {
                Storage::disk(self::PHOTO_DISK)->delete($record->clock_out_photo_path);
            }

            $flags = array_values(array_diff($record->flags ?? [], [
                'out_of_radius_out', 'early_out', 'short_hours', 'site_visit_out',
                // 'no_location' only ever meant the clock-OUT had no fix when the clock-IN
                // did — a clock-in with no fix carries the same flag and must keep it.
                ...($record->latitude !== null ? ['no_location'] : []),
            ]));

            $record->update([
                'clock_out' => null,
                'clock_out_latitude' => null,
                'clock_out_longitude' => null,
                'out_radius' => null,
                'clock_out_work_mode' => null,
                'clock_out_justification' => null,
                'clock_out_photo_path' => null,
                'worked_minutes' => null,
                'flags' => $flags,
            ]);

            AuditLog::record('Reversed clock-out', $employee->name ?? 'Unknown employee');

            return back()->with('ok', 'Clock-out reversed. '.($employee->name ?? 'The employee').' can clock out again.');
        }

        if ($record->photo_path) {
            Storage::disk(self::PHOTO_DISK)->delete($record->photo_path);
        }

        $name = $employee->name ?? 'Unknown employee';
        $record->delete();

        AuditLog::record('Reversed clock-in', $name);

        return back()->with('ok', 'Clock-in reversed. '.$name.' can clock in again.');
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

    /** Super-admin observers carry no tenant membership row, so hasTenantRole() alone
     * would never admit them — same reason every other super-admin-reaching gate in
     * this codebase checks it explicitly rather than through the tenant role. */
    private function canReversePunch(Request $request): bool
    {
        return (bool) $request->user()?->isSuperAdmin() || $this->hasTenantRole($request, self::REVERSE_ROLES);
    }

    private function authorizeReverse(Request $request): void
    {
        abort_unless($this->canReversePunch($request), 403);
    }

    private function assertTenant(int $tenantId): void
    {
        abort_unless($tenantId === app(CurrentTenant::class)->id(), 403);
    }
}
