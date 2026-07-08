<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\CompanySetupProgress;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmploymentType;
use App\Models\LeaveType;
use App\Models\Position;
use App\Models\PublicHoliday;
use App\Models\StaffLevel;
use App\Models\StatutoryRate;
use App\Models\TimesheetCategory;
use App\Services\FeatureManager;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Company "Launch Center" — a domain-grouped shell over the existing CRUD screens.
 * It owns no business logic: steps that map to data are auto-detected (a branch
 * exists, a leave type exists, …); the rest are marked done by the admin. Steps are
 * grouped into launch domains (basics → people → attendance → time → requests →
 * payroll) so an admin can see, per area, what is still outstanding. The CRITICAL
 * subset backs the launch lock (EnsureSystemLaunched): staff stay held until it is
 * satisfied. Privileged-only.
 */
class SetupController extends Controller
{
    private const ADMIN_ROLES = ['management', 'hr'];

    /** Steps that must be done before staff are let in (backs the launch lock). */
    private const CRITICAL = ['branches', 'departments', 'positions', 'staff', 'attendance_policy', 'leave_types'];

    /** Launch domains in recommended order: [key => [label, label_ms]]. */
    public function domainDefs(): array
    {
        return [
            'basics' => ['label' => 'Company basics', 'label_ms' => 'Asas syarikat'],
            'people' => ['label' => 'People & access', 'label_ms' => 'Kakitangan & akses'],
            'attendance' => ['label' => 'Attendance policy', 'label_ms' => 'Dasar kehadiran'],
            'time' => ['label' => 'Time & work', 'label_ms' => 'Masa & kerja'],
            'requests' => ['label' => 'Leave & requests', 'label_ms' => 'Cuti & permohonan'],
            'payroll' => ['label' => 'Payroll', 'label_ms' => 'Gaji'],
            'finish' => ['label' => 'Review & launch', 'label_ms' => 'Semak & lancar'],
        ];
    }

    /**
     * Ordered wizard steps grouped by domain. `auto` steps are detected from data;
     * the others are manually marked complete. `screen` is the existing screen the
     * step deep-links to. `critical` marks a launch-blocking step.
     *
     * @return array<string, array{label:string,label_ms:string,desc:string,screen:string,query:array<string,string>,auto:bool,domain:string,critical:bool}>
     */
    public function stepDefs(): array
    {
        $defs = [
            // Company basics
            'profile' => ['label' => 'Complete company profile', 'label_ms' => 'Lengkapkan profil syarikat', 'desc' => 'Logo, branding, contact details and welcome message.', 'screen' => 'settings', 'query' => [], 'auto' => true, 'domain' => 'basics', 'critical' => false],
            'branches' => ['label' => 'Add branches & locations', 'label_ms' => 'Tambah cawangan & lokasi', 'desc' => 'At least one branch or location for the company.', 'screen' => 'settings', 'query' => [], 'auto' => true, 'domain' => 'basics', 'critical' => true],
            'departments' => ['label' => 'Add departments', 'label_ms' => 'Tambah jabatan', 'desc' => 'The departments staff are organised under.', 'screen' => 'settings', 'query' => [], 'auto' => true, 'domain' => 'basics', 'critical' => true],
            'positions' => ['label' => 'Add positions', 'label_ms' => 'Tambah jawatan', 'desc' => 'Job positions and their rate bands.', 'screen' => 'position', 'query' => [], 'auto' => true, 'domain' => 'basics', 'critical' => true],
            'staff_levels' => ['label' => 'Configure staff levels', 'label_ms' => 'Konfigur tahap staf', 'desc' => 'Grade/level bands (e.g. L1–L6).', 'screen' => 'settings', 'query' => [], 'auto' => true, 'domain' => 'basics', 'critical' => false],
            'employment_types' => ['label' => 'Configure employment types', 'label_ms' => 'Konfigur jenis pekerjaan', 'desc' => 'Full-time, contract, part-time and so on.', 'screen' => 'settings', 'query' => [], 'auto' => true, 'domain' => 'basics', 'critical' => false],

            // People & access
            'roles' => ['label' => 'Create roles', 'label_ms' => 'Cipta peranan', 'desc' => 'Assign access roles to workspace members.', 'screen' => 'roles', 'query' => [], 'auto' => true, 'domain' => 'people', 'critical' => false],
            'acl' => ['label' => 'Review ACL permissions', 'label_ms' => 'Semak kebenaran ACL', 'desc' => 'Confirm what each role can see and do.', 'screen' => 'roles', 'query' => [], 'auto' => false, 'domain' => 'people', 'critical' => false],
            'staff' => ['label' => 'Add & invite staff', 'label_ms' => 'Tambah & jemput staf', 'desc' => 'Add your employees and provision their logins.', 'screen' => 'directory', 'query' => [], 'auto' => true, 'domain' => 'people', 'critical' => true],

            // Attendance policy (previously orphaned)
            'attendance_policy' => ['label' => 'Set attendance policy', 'label_ms' => 'Tetapkan dasar kehadiran', 'desc' => 'Office geofence, working hours, client sites and work arrangements.', 'screen' => 'attendance-admin', 'query' => [], 'auto' => true, 'domain' => 'attendance', 'critical' => true],

            // Time & work (previously orphaned)
            'timesheet_categories' => ['label' => 'Set up timesheet categories', 'label_ms' => 'Sediakan kategori timesheet', 'desc' => 'Categories, projects and sub-pillars staff allocate time against.', 'screen' => 'timesheet-setup', 'query' => [], 'auto' => true, 'domain' => 'time', 'critical' => false],

            // Leave & requests
            'leave_types' => ['label' => 'Configure leave types', 'label_ms' => 'Konfigur jenis cuti', 'desc' => 'Annual, medical, unpaid and other leave types with entitlements.', 'screen' => 'leave', 'query' => [], 'auto' => true, 'domain' => 'requests', 'critical' => true],
            'holidays' => ['label' => 'Add public holidays', 'label_ms' => 'Tambah cuti umum', 'desc' => 'The holiday calendar leave and attendance work against.', 'screen' => 'leave', 'query' => [], 'auto' => true, 'domain' => 'requests', 'critical' => false],
        ];

        // Payroll is only relevant when the module is enabled for the tenant.
        if ($this->payrollEnabled()) {
            $defs['payroll_setup'] = ['label' => 'Configure payroll', 'label_ms' => 'Konfigur gaji', 'desc' => 'Statutory rates (EPF/SOCSO/EIS) and salary structures.', 'screen' => 'payroll', 'query' => [], 'auto' => true, 'domain' => 'payroll', 'critical' => false];
        }

        // Review & launch — always last.
        $defs['review'] = ['label' => 'Review & complete setup', 'label_ms' => 'Semak & selesai persediaan', 'desc' => 'Confirm everything is in place, then launch.', 'screen' => 'setup', 'query' => [], 'auto' => false, 'domain' => 'finish', 'critical' => false];

        return $defs;
    }

    /** Auto-detected step statuses for the active tenant. */
    private function autoStatuses(): array
    {
        $tenant = app(CurrentTenant::class)->get();

        $statuses = [
            'profile' => (bool) ($tenant->industry || $tenant->address || $tenant->contact_number || $tenant->registration_number || $tenant->logo_path),
            'branches' => Branch::count() > 0,
            'departments' => Department::count() > 0,
            'positions' => Position::count() > 0,
            'staff_levels' => StaffLevel::count() > 0,
            'employment_types' => EmploymentType::count() > 0,
            'roles' => $tenant->users()->wherePivotIn('role', ['manager', 'management', 'hr'])->exists(),
            'staff' => Employee::active()->count() > 1,
            // Attendance policy is configured once a branch has a geofence centre set.
            'attendance_policy' => Branch::whereNotNull('latitude')->exists(),
            'timesheet_categories' => TimesheetCategory::count() > 0,
            'leave_types' => LeaveType::count() > 0,
            'holidays' => PublicHoliday::count() > 0,
        ];

        if ($this->payrollEnabled()) {
            $statuses['payroll_setup'] = StatutoryRate::count() > 0;
        }

        return $statuses;
    }

    /**
     * True once every launch-critical step is satisfied. Backs the launch lock, so it
     * runs on every gated staff request: only the 6 CRITICAL detectors are evaluated
     * (short-circuiting on the first miss) instead of the full autoStatuses() set, and
     * a true result is cached per tenant — launch is effectively monotonic, and a
     * false result is never cached so staff are admitted on the very next request
     * after HR completes setup.
     */
    public function criticalDone(): bool
    {
        $tenantId = app(CurrentTenant::class)->id();
        $key = "setup:launched:{$tenantId}";

        if (Cache::get($key)) {
            return true;
        }

        $done = Branch::exists()
            && Department::exists()
            && Position::exists()
            && Employee::active()->count() > 1
            && Branch::whereNotNull('latitude')->exists()
            && LeaveType::exists();

        if ($done) {
            Cache::put($key, true, now()->addHour());
        }

        return $done;
    }

    /** Compute the full step list, per-domain rollups + headline stats for the active tenant. */
    public function compute(): array
    {
        $progress = CompanySetupProgress::forCurrentTenant();
        $manual = $progress->steps ?? [];
        $auto = $this->autoStatuses();

        $rows = [];
        foreach ($this->stepDefs() as $key => $def) {
            $done = $def['auto'] ? ($auto[$key] ?? false) : in_array($key, $manual, true);
            $rows[] = array_merge($def, ['key' => $key, 'done' => $done]);
        }

        $doneCount = count(array_filter($rows, fn ($r) => $r['done']));
        $total = count($rows);

        // Per-domain rollups, in domain order, skipping domains with no steps.
        $domains = [];
        foreach ($this->domainDefs() as $dkey => $meta) {
            $dRows = array_values(array_filter($rows, fn ($r) => $r['domain'] === $dkey));
            if ($dRows === []) {
                continue;
            }
            $dDone = count(array_filter($dRows, fn ($r) => $r['done']));
            $domains[] = array_merge($meta, [
                'key' => $dkey,
                'rows' => $dRows,
                'done' => $dDone,
                'total' => count($dRows),
                'pct' => (int) round($dDone / max(count($dRows), 1) * 100),
                'complete' => $dDone === count($dRows),
            ]);
        }

        // Launch-blocking steps still outstanding (for the "what's blocking" list).
        $blocking = array_values(array_filter(
            $rows,
            fn ($r) => $r['critical'] && ! $r['done'],
        ));

        return [
            'rows' => $rows,
            'domains' => $domains,
            'done' => $doneCount,
            'total' => $total,
            'pct' => (int) round($doneCount / max($total, 1) * 100),
            'allDone' => $doneCount === $total,
            'complete' => $progress->completed_at !== null,
            'criticalDone' => count($blocking) === 0,
            'blocking' => $blocking,
        ];
    }

    /** Data for the setup wizard screen. */
    public function screenData(Request $request): array
    {
        $c = $this->compute();

        return [
            'setupDomains' => $c['domains'],
            'setupSteps' => $c['rows'],
            'setupDone' => $c['done'],
            'setupTotal' => $c['total'],
            'setupPct' => $c['pct'],
            'setupAllDone' => $c['allDone'],
            'setupComplete' => $c['complete'],
            'setupCriticalDone' => $c['criticalDone'],
            'setupBlocking' => $c['blocking'],
        ];
    }

    /** Compact summary for the dashboard progress card. */
    public function summary(): array
    {
        $c = $this->compute();

        return ['pct' => $c['pct'], 'done' => $c['done'], 'total' => $c['total'], 'complete' => $c['complete'], 'criticalDone' => $c['criticalDone']];
    }

    /** Toggle a manual step's completion. Auto steps are ignored (data-driven). */
    public function markStep(Request $request): RedirectResponse
    {
        $this->authorizeAdmin($request);

        $key = $request->validate(['step' => ['required', 'string']])['step'];
        $defs = $this->stepDefs();
        abort_unless(array_key_exists($key, $defs), 422);

        // Auto steps reflect real data and cannot be toggled by hand.
        if ($defs[$key]['auto']) {
            return back();
        }

        $progress = CompanySetupProgress::forCurrentTenant();
        $steps = $progress->steps ?? [];
        $steps = in_array($key, $steps, true)
            ? array_values(array_diff($steps, [$key]))
            : array_values(array_merge($steps, [$key]));
        $progress->update(['steps' => $steps]);

        return back()->with('ok', 'Setup updated.');
    }

    /** Mark the whole wizard complete (only when every step is done). */
    public function finish(Request $request): RedirectResponse
    {
        $this->authorizeAdmin($request);

        if (! $this->compute()['allDone']) {
            return back()->with('error', 'Complete every step before finishing setup.');
        }

        CompanySetupProgress::forCurrentTenant()->update(['completed_at' => now()]);
        AuditLog::record('Completed company setup');

        return back()->with('ok', 'Setup complete — your workspace is ready.');
    }

    private function authorizeAdmin(Request $request): void
    {
        $this->authorizeTenantRole($request, self::ADMIN_ROLES);
    }

    private function payrollEnabled(): bool
    {
        $tenant = app(CurrentTenant::class)->get();

        return $tenant !== null && app(FeatureManager::class)->screenAllowed($tenant, 'payroll');
    }
}
