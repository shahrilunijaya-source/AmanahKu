<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\CompanySetupProgress;
use App\Models\Department;
use App\Models\EasterEgg;
use App\Models\Employee;
use App\Models\EmploymentType;
use App\Models\GreetingLine;
use App\Models\LeaveType;
use App\Models\Position;
use App\Models\PublicHoliday;
use App\Models\SalaryStructure;
use App\Models\StaffLevel;
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
            'culture' => ['label' => 'Dashboard touches', 'label_ms' => 'Sentuhan papan pemuka'],
            'finish' => ['label' => 'Review & launch', 'label_ms' => 'Semak & lancar'],
        ];
    }

    /**
     * Ordered wizard steps grouped by domain. `auto` steps are detected from data;
     * the others are manually marked complete. `screen` is the existing screen the
     * step deep-links to. `critical` marks a launch-blocking step.
     *
     * @return array<string, array{label:string,label_ms:string,desc:string,desc_ms:string,guide:string,guide_ms:string,screen:string,query:array<string,string>,auto:bool,domain:string,critical:bool}>
     */
    public function stepDefs(): array
    {
        $defs = [
            // Company basics — enable the modules this company uses FIRST. This embeds the
            // Features/Modules panel from Company Settings (leave, claims, documents and
            // more). Doing it up front means the rest of the wizard and the sidebar only
            // surface what's switched on — e.g. turning payroll on here makes the Payroll
            // step appear on the next load. Manual step (no data signal to auto-tick).
            'modules' => ['label' => 'Enable modules', 'label_ms' => 'Aktifkan modul', 'desc' => 'Turn on the features this company uses, like leave, claims and documents.', 'desc_ms' => 'Aktifkan ciri yang syarikat ini guna, seperti cuti, tuntutan dan dokumen.', 'guide' => 'Go to Company Settings, scroll to the Features card, tick the modules you use and click Save features.', 'guide_ms' => 'Pergi ke Tetapan Syarikat, skrol ke kad Ciri, tandakan modul yang anda guna dan klik Simpan ciri.', 'screen' => 'settings', 'query' => [], 'auto' => false, 'domain' => 'basics', 'critical' => false],
            'profile' => ['label' => 'Complete company profile', 'label_ms' => 'Lengkapkan profil syarikat', 'desc' => 'Logo, branding, contact details and welcome message.', 'desc_ms' => 'Logo, jenama, butiran hubungan dan mesej alu-aluan.', 'guide' => 'On Company Settings, fill the Workspace profile card (address, contact or logo is enough) and click Save changes.', 'guide_ms' => 'Di Tetapan Syarikat, isi kad Profil workspace (alamat, nombor hubungan atau logo sudah memadai) dan klik Simpan perubahan.', 'screen' => 'settings', 'query' => [], 'auto' => true, 'domain' => 'basics', 'critical' => false],
            // Manual: the Mon-Fri default is a valid answer, so no data signal says "done".
            'work_week' => ['label' => 'Set work week', 'label_ms' => 'Tetapkan minggu bekerja', 'desc' => 'Which days count as working days. Leave, timesheets and attendance follow this.', 'desc_ms' => 'Hari mana dikira hari bekerja. Cuti, timesheet dan kehadiran mengikut ini.', 'guide' => 'On Company Settings, tick the days your company works on the Work week card and click Save work week.', 'guide_ms' => 'Di Tetapan Syarikat, tandakan hari syarikat anda bekerja pada kad Minggu bekerja dan klik Simpan minggu bekerja.', 'screen' => 'settings', 'query' => ['section' => 'work_week'], 'auto' => false, 'domain' => 'basics', 'critical' => false],
            'branches' => ['label' => 'Add branches & locations', 'label_ms' => 'Tambah cawangan & lokasi', 'desc' => 'At least one branch, with its map geofence and working hours.', 'desc_ms' => 'Sekurang-kurangnya satu cawangan, dengan geofence peta dan waktu bekerja.', 'guide' => 'Go to Company Settings and click + Add on the Branches card. Give it a name and address; the map pin can wait.', 'guide_ms' => 'Pergi ke Tetapan Syarikat dan klik + Tambah pada kad Cawangan. Beri nama dan alamat; pin peta boleh ditunggu.', 'screen' => 'settings', 'query' => [], 'auto' => true, 'domain' => 'basics', 'critical' => true],
            'departments' => ['label' => 'Add departments', 'label_ms' => 'Tambah jabatan', 'desc' => 'The departments staff are organised under.', 'desc_ms' => 'Jabatan tempat staf disusun.', 'guide' => 'On Company Settings, click + Add on the Departments card, type a name and click Add.', 'guide_ms' => 'Di Tetapan Syarikat, klik + Tambah pada kad Jabatan, taip nama dan klik Tambah.', 'screen' => 'settings', 'query' => [], 'auto' => true, 'domain' => 'basics', 'critical' => true],
            'staff_levels' => ['label' => 'Configure staff levels', 'label_ms' => 'Konfigur tahap staf', 'desc' => 'Grade/level bands (e.g. L1–L6).', 'desc_ms' => 'Band gred/tahap staf (cth. L1-L6).', 'guide' => 'On Company Settings, click + Add on the Staff levels card, fill in the level and click Add.', 'guide_ms' => 'Di Tetapan Syarikat, klik + Tambah pada kad Tahap staf, isi tahap itu dan klik Tambah.', 'screen' => 'settings', 'query' => [], 'auto' => true, 'domain' => 'basics', 'critical' => false],
            'employment_types' => ['label' => 'Configure employment types', 'label_ms' => 'Konfigur jenis pekerjaan', 'desc' => 'Full-time, contract, part-time and so on.', 'desc_ms' => 'Sepenuh masa, kontrak, separuh masa dan sebagainya.', 'guide' => 'On Company Settings, click + Add on the Employment types card, fill in Full-time and click Add. Add any others you use the same way.', 'guide_ms' => 'Di Tetapan Syarikat, klik + Tambah pada kad Jenis pekerjaan, isi Sepenuh masa dan klik Tambah. Tambah jenis lain yang anda guna dengan cara yang sama.', 'screen' => 'settings', 'query' => [], 'auto' => true, 'domain' => 'basics', 'critical' => false],
            // Positions LAST in basics: a position ties a department + staff level + rate,
            // so those must exist first.
            'positions' => ['label' => 'Add positions', 'label_ms' => 'Tambah jawatan', 'desc' => 'Job positions and their rate bands.', 'desc_ms' => 'Jawatan dan band kadar gaji mereka.', 'guide' => 'Go to Positions, open the Manage bands tab, click + New band and fill in the title, department and salary band.', 'guide_ms' => 'Pergi ke Jawatan, buka tab Urus band, klik + Band baru dan isi jawatan, jabatan dan band gaji.', 'screen' => 'position', 'query' => [], 'auto' => true, 'domain' => 'basics', 'critical' => true],

            // People & access — staff FIRST: a role is assigned to a member (a login), so
            // people must exist before there is anything to assign a role to.
            'staff' => ['label' => 'Add & import staff', 'label_ms' => 'Tambah & import staf', 'desc' => 'Add employees one by one, bulk-import from CSV, and provision their logins.', 'desc_ms' => 'Tambah pekerja seorang demi seorang, import pukal dari CSV, dan sediakan log masuk mereka.', 'guide' => 'Go to Add & Import Staff. Fill the Add employee form and click Add employee, or upload a CSV under Bulk import staff.', 'guide_ms' => 'Pergi ke Tambah & Import Staf. Isi borang Tambah pekerja dan klik Tambah pekerja, atau muat naik CSV di bawah Import staf pukal.', 'screen' => 'staff-load', 'query' => [], 'auto' => true, 'domain' => 'people', 'critical' => true],
            'roles' => ['label' => 'Assign roles & access', 'label_ms' => 'Tetapkan peranan & akses', 'desc' => 'Give each member a role and data scope. The five roles are built in — you assign them, not create them.', 'desc_ms' => 'Beri setiap ahli peranan dan skop data. Lima peranan sudah sedia ada, anda tetapkan, bukan cipta.', 'guide' => 'Go to Roles & Permissions, click + Add member and give at least one person the Manager or Management role.', 'guide_ms' => 'Pergi ke Peranan & Kebenaran, klik + Tambah ahli dan beri sekurang-kurangnya seorang peranan Pengurus atau Pengurusan.', 'screen' => 'roles', 'query' => [], 'auto' => true, 'domain' => 'people', 'critical' => false],
            'acl' => ['label' => 'Review permissions', 'label_ms' => 'Semak kebenaran', 'desc' => 'Confirm what each role can see and do.', 'desc_ms' => 'Sahkan apa setiap peranan boleh lihat dan buat.', 'guide' => 'On Roles & Permissions, read what each role can do, then tick this step done in Launch Center.', 'guide_ms' => 'Di Peranan & Kebenaran, baca apa yang setiap peranan boleh buat, kemudian tandakan langkah ini selesai di Pusat Pelancaran.', 'screen' => 'roles', 'query' => [], 'auto' => false, 'domain' => 'people', 'critical' => false],

            // Attendance policy (previously orphaned)
            'attendance_policy' => ['label' => 'Set attendance policy', 'label_ms' => 'Tetapkan dasar kehadiran', 'desc' => 'Client sites, work-from-home policy and per-staff work arrangements. (Branch geofences live under Branches.)', 'desc_ms' => 'Tapak klien, dasar kerja dari rumah dan susunan kerja setiap staf. (Geofence cawangan ada di bawah Cawangan.)', 'guide' => 'Set the late grace on Attendance Setup, then on Company Settings click Edit on a branch, click Map to drop its pin and Save.', 'guide_ms' => 'Tetapkan tempoh lewat di Persediaan Kehadiran, kemudian di Tetapan Syarikat klik Sunting pada cawangan, klik Peta untuk letak pin dan Simpan.', 'screen' => 'attendance-admin', 'query' => [], 'auto' => true, 'domain' => 'attendance', 'critical' => true],

            // Time & work (previously orphaned)
            'timesheet_categories' => ['label' => 'Set up timesheet categories', 'label_ms' => 'Sediakan kategori timesheet', 'desc' => 'The categories staff allocate time against. Projects and sub-pillars live under Workplace → Projects.', 'desc_ms' => 'Kategori timesheet yang staf peruntukkan masa. Projek dan sub-tiang ada di Tempat Kerja → Projek.', 'guide' => 'Go to Timesheet Setup, click Add category, fill in the name and click Add category again to save it.', 'guide_ms' => 'Pergi ke Persediaan Timesheet, klik Tambah kategori, isi nama dan klik Tambah kategori sekali lagi untuk simpan.', 'screen' => 'timesheet-setup', 'query' => [], 'auto' => true, 'domain' => 'time', 'critical' => false],

            // Leave & requests
            'leave_types' => ['label' => 'Configure leave types', 'label_ms' => 'Konfigur jenis cuti', 'desc' => 'Annual, medical, unpaid and other leave types with entitlements.', 'desc_ms' => 'Jenis cuti tahunan, sakit, tanpa gaji dan lain-lain dengan kelayakan.', 'guide' => 'Go to Leave Setup and click Load standard Malaysian set, then adjust the days to match your policy.', 'guide_ms' => 'Pergi ke Persediaan Cuti dan klik Muat set standard Malaysia, kemudian laraskan hari mengikut dasar anda.', 'screen' => 'leave-setup', 'query' => [], 'auto' => true, 'domain' => 'requests', 'critical' => true],
            // Same screen as the step above, so it needs ?tab= to open on the right one —
            // without it the holiday step drops you on the leave types tab.
            'holidays' => ['label' => 'Add public holidays', 'label_ms' => 'Tambah cuti umum', 'desc' => 'The holiday calendar leave and attendance work against.', 'desc_ms' => 'Kalendar cuti umum yang cuti dan kehadiran ikut.', 'guide' => 'On Leave Setup, open the Public holidays tab and click Load 2026 Malaysian holidays, then check the lunar dates.', 'guide_ms' => 'Di Persediaan Cuti, buka tab Cuti umum dan klik Muat cuti Malaysia 2026, kemudian semak tarikh kalendar lunar.', 'screen' => 'leave-setup', 'query' => ['tab' => 'holidays'], 'auto' => true, 'domain' => 'requests', 'critical' => false],
        ];

        // Payroll is only relevant when the module is enabled for the tenant.
        if ($this->payrollEnabled()) {
            $defs['payroll_setup'] = ['label' => 'Configure payroll', 'label_ms' => 'Konfigur gaji', 'desc' => 'Salary structures for active employees. EPF/SOCSO/EIS/PCB follow fixed published schedules.', 'desc_ms' => 'Struktur gaji untuk pekerja aktif. EPF/SOCSO/EIS/PCB ikut jadual rasmi tetap.', 'guide' => 'Go to Payroll, open an employee under Salary structures and save their basic salary and statutory numbers.', 'guide_ms' => 'Pergi ke Gaji, buka pekerja di bawah Struktur gaji dan simpan gaji pokok serta nombor berkanun mereka.', 'screen' => 'payroll', 'query' => [], 'auto' => true, 'domain' => 'payroll', 'critical' => false];
        }

        // Dashboard touches — optional. Both banks are seeded for every company, so these
        // tick themselves and never hold setup back; they are here so HR can find the cards.
        $defs['greetings'] = ['label' => 'Dashboard greetings', 'label_ms' => 'Ucapan papan pemuka', 'desc' => 'The rotating greeting line at the top of everyone\'s dashboard, including the holiday-eve lines.', 'desc_ms' => 'Baris ucapan berputar di atas papan pemuka semua orang, termasuk baris malam sebelum cuti.', 'guide' => 'On Company Settings, scroll to the Dashboard greetings card to read or edit the lines. Already seeded, nothing to do.', 'guide_ms' => 'Di Tetapan Syarikat, skrol ke kad Ucapan papan pemuka untuk baca atau sunting baris. Sudah disemai, tiada yang perlu dibuat.', 'screen' => 'settings', 'query' => [], 'auto' => true, 'domain' => 'culture', 'critical' => false];
        $defs['eggs'] = ['label' => 'Dashboard easter eggs', 'label_ms' => 'Telur Paskah papan pemuka', 'desc' => 'The small one-off messages under the greeting (Friday after 5, late night, holiday eve).', 'desc_ms' => 'Mesej kecil sekali-sekala di bawah ucapan (Jumaat lepas 5, lewat malam, malam sebelum cuti).', 'guide' => 'On Company Settings, scroll to the Dashboard easter eggs card to read or edit them. Already seeded, nothing to do.', 'guide_ms' => 'Di Tetapan Syarikat, skrol ke kad Telur Paskah papan pemuka untuk baca atau sunting. Sudah disemai, tiada yang perlu dibuat.', 'screen' => 'settings', 'query' => [], 'auto' => true, 'domain' => 'culture', 'critical' => false];
        $defs['reactions'] = ['label' => 'Reactions', 'label_ms' => 'Reaksi', 'desc' => 'The emoji set people react with on posts and wins. Up to ten active.', 'desc_ms' => 'Set emoji untuk reaksi pada hantaran dan kejayaan. Sehingga sepuluh aktif.', 'guide' => 'On Company Settings, scroll to the Reactions card and pick up to ten emoji. The default set already works.', 'guide_ms' => 'Di Tetapan Syarikat, skrol ke kad Reaksi dan pilih sehingga sepuluh emoji. Set lalai sudah berfungsi.', 'screen' => 'settings', 'query' => [], 'auto' => true, 'domain' => 'culture', 'critical' => false];

        // Review & launch — always last.
        $defs['review'] = ['label' => 'Review & complete setup', 'label_ms' => 'Semak & selesai persediaan', 'desc' => 'Confirm everything is in place, then launch.', 'desc_ms' => 'Sahkan semuanya sudah lengkap, kemudian lancarkan.', 'guide' => 'Go to Company Setup, check every step shows done, then click Finish setup at the bottom.', 'guide_ms' => 'Pergi ke Persediaan Syarikat, pastikan setiap langkah selesai, kemudian klik Selesaikan persediaan di bahagian bawah.', 'screen' => 'setup', 'query' => [], 'auto' => false, 'domain' => 'finish', 'critical' => false];

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
            'greetings' => GreetingLine::exists(),
            'eggs' => EasterEgg::exists(),
            // Reaction::set() fills the default set on first read, so a company always has one.
            'reactions' => true,
        ];

        if ($this->payrollEnabled()) {
            $statuses['payroll_setup'] = SalaryStructure::count() > 0;
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
