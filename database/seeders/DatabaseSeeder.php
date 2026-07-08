<?php

namespace Database\Seeders;

use App\Models\Achievement;
use App\Models\Announcement;
use App\Models\AppNotification;
use App\Models\Asset;
use App\Models\AttendanceRecord;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\CareerTimelineEntry;
use App\Models\Claim;
use App\Models\CompanyCategory;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmploymentType;
use App\Models\HandbookSection;
use App\Models\KpiItem;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\OnboardingProfile;
use App\Models\OnboardingTask;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\PerformanceReview;
use App\Models\PolicyAcknowledgement;
use App\Models\PublicHoliday;
use App\Models\SalaryStructure;
use App\Models\StaffLevel;
use App\Models\StatutoryRate;
use App\Models\Tenant;
use App\Models\TrainingRecord;
use App\Models\User;
use App\Models\WorkItem;
use App\Models\WorkSite;
use App\Services\Payroll\PayrollCalculator;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // This seeder ships demo data — including a super-admin with a known password.
        // It must never run against a real deployment. Allow only local development and
        // the test suite (which seeds intentionally). Production is provisioned through
        // the admin console, not this seeder.
        if (! app()->environment('local', 'testing')) {
            throw new \RuntimeException(
                'DatabaseSeeder ships demo data and a known-password super-admin; it is refused outside local/testing environments.'
            );
        }

        $demo = User::create([
            'name' => 'Aisyah Rahman',
            'email' => 'aisyah.rahman@unijaya.example',
            'password' => Hash::make('password'),
        ]);

        // Platform super-admin: provisions new companies from /admin/companies.
        // Belongs to no tenant — operates above every workspace.
        User::create([
            'name' => 'Platform Admin',
            'email' => 'superadmin@amanahku.com',
            'password' => Hash::make('password'),
        ])->forceFill(['is_super_admin' => true])->save();

        // Category metadata only (not a restrictive package re-apply) so the demo
        // workspaces keep every module on, matching the rich seed data + screen tests.
        $stage1 = CompanyCategory::where('level', 1)->value('id');
        $stage2 = CompanyCategory::where('level', 2)->value('id');
        $stage3 = CompanyCategory::where('level', 3)->value('id');

        $unijaya = Tenant::create(['slug' => 'unijaya', 'name' => 'Unijaya Resources', 'initials' => 'UR', 'color' => '#d6232b', 'plan' => 'Enterprise', 'company_category_id' => $stage3, 'meta' => '4 branches · 186 employees']);
        $shell = Tenant::create(['slug' => 'shell-s2', 'name' => 'Shell Seremban 2', 'initials' => 'SS', 'color' => '#1f8a65', 'plan' => 'Business', 'company_category_id' => $stage2, 'meta' => '2 branches · 142 employees']);
        $petron = Tenant::create(['slug' => 'petron-tl', 'name' => 'Petron Tg Lumpur', 'initials' => 'PT', 'color' => '#3a6ea5', 'plan' => 'Business', 'company_category_id' => $stage1, 'meta' => '1 branch · 84 employees']);

        // Demo user can access all three; HR role so the persona toggle stays usable.
        $demo->tenants()->attach([$unijaya->id => ['role' => 'hr'], $shell->id => ['role' => 'manager'], $petron->id => ['role' => 'employee']]);

        // Org lookups for the main demo tenant (grades L1–L6 + employment types).
        foreach (['L1', 'L2', 'L3', 'L4', 'L5', 'L6'] as $i => $lv) {
            StaffLevel::create(['tenant_id' => $unijaya->id, 'name' => $lv, 'rank' => $i + 1]);
        }
        foreach (['Full-time', 'Contract', 'Part-time', 'Intern'] as $et) {
            EmploymentType::create(['tenant_id' => $unijaya->id, 'name' => $et]);
        }

        $this->seedUnijaya($unijaya, $demo);
        $this->seedMinimal($shell);
        $this->seedMinimal($petron);

        // New modules — guarded + self-scope tenant_id (no tenant session in seeders).
        $this->call(RosterSeeder::class);
        $this->call(SurveySeeder::class);
        $this->call(HelpdeskSeeder::class);
        $this->call(EventSeeder::class);
        $this->call(SharedResourceSeeder::class);
        $this->call(OffboardingSeeder::class);
        $this->call(GoalSeeder::class);
        $this->call(RecruitmentSeeder::class);
        $this->call(LoanSeeder::class);
        $this->call(TravelSeeder::class);
        $this->call(RoomSeeder::class);
        $this->call(CaseSeeder::class);
        $this->call(IdeaSeeder::class);
        $this->call(KnowledgeSeeder::class);
        $this->call(BenefitSeeder::class);
        $this->call(ExpenseSeeder::class);
        $this->call(ProbationSeeder::class);
        $this->call(OvertimeSeeder::class);
        $this->call(ResignationSeeder::class);
        $this->call(ComplianceSeeder::class);
        $this->call(TimesheetCategorySeeder::class);
        $this->call(ProjectSeeder::class);
        $this->call(TimesheetSeeder::class);
        $this->call(LearningSeeder::class);
        $this->call(SkillSeeder::class);
        $this->call(ReferralSeeder::class);
        $this->call(ShiftSwapSeeder::class);
        $this->call(PettyCashSeeder::class);
        $this->call(VehicleSeeder::class);
        $this->call(WellnessSeeder::class);
        $this->call(ProfileTestSeeder::class);

        // Seeded logins are pre-verified so the demo never hits the email-verification gate.
        User::query()->whereNull('email_verified_at')->update(['email_verified_at' => now()]);
    }

    private function seedUnijaya(Tenant $t, User $demo): void
    {
        $tid = $t->id;

        $depts = collect(['People & Culture', 'Operations', 'Finance', 'Information Tech', 'Marketing', 'Procurement', 'Sales', 'Logistics'])
            ->mapWithKeys(fn ($n) => [$n => Department::create(['tenant_id' => $tid, 'name' => $n])->id]);

        $branches = collect([
            ['PJ HQ', 'Selangor'], ['Seremban 2', 'Negeri Sembilan'], ['Klang', 'Selangor'], ['Kuala Lumpur', 'WP Kuala Lumpur'],
        ])->mapWithKeys(fn ($b) => [$b[0] => Branch::create(['tenant_id' => $tid, 'name' => $b[0], 'state' => $b[1]])->id]);

        // Geofence + standard office hours (10:00–19:00, 8h minimum, 200m radius) per branch.
        $branchGeo = [
            'PJ HQ' => [3.1073000, 101.6067000],
            'Seremban 2' => [2.7297000, 101.9381000],
            'Klang' => [3.0449000, 101.4451000],
            'Kuala Lumpur' => [3.1390000, 101.6869000],
        ];
        foreach ($branchGeo as $bname => [$blat, $blng]) {
            Branch::where('tenant_id', $tid)->where('name', $bname)->update([
                'latitude' => $blat, 'longitude' => $blng, 'radius_m' => 200,
                'work_start' => '10:00', 'work_end' => '19:00', 'min_hours' => 8.0,
            ]);
        }

        // A resident-engineer client site, on the client's own hours.
        $petronSite = WorkSite::create([
            'tenant_id' => $tid,
            'name' => 'Petron Tg Lumpur', 'client' => 'Petron',
            'latitude' => 3.7960000, 'longitude' => 103.3760000, 'radius_m' => 250,
            'work_start' => '08:30', 'work_end' => '17:30', 'min_hours' => 8.0,
        ]);

        // name, position, dept, branch, level, status, workload, wl label, email, initials, avatar, photo, leave, kpi
        $rows = [
            ['Aisyah Rahman', 'HR Manager', 'People & Culture', 'PJ HQ', 'L6', 'active', 'green', 'Healthy', 'aisyah.rahman@unijaya.example', 'AR', '#3a6ea5', 47, 12.5, 78],
            ['Nurul Iman binti Hassan', 'Senior HR Executive', 'People & Culture', 'PJ HQ', 'L4', 'active', 'green', 'Healthy', 'nurul.iman@unijaya.example', 'NI', '#1f8a65', 44, 9.0, 91],
            ['Faizal Othman', 'Operations Lead', 'Operations', 'Seremban 2', 'L5', 'active', 'red', 'Overloaded', 'faizal.othman@unijaya.example', 'FO', '#d6232b', 13, 6.0, 72],
            ['Tan Wei Ming', 'Finance Analyst', 'Finance', 'PJ HQ', 'L3', 'active', 'amber', 'Near capacity', 'weiming.tan@unijaya.example', 'TW', '#c08532', 33, 8.0, 80],
            ['Ravi Kumar', 'IT Support Engineer', 'Information Tech', 'PJ HQ', 'L3', 'active', 'green', 'Healthy', 'ravi.kumar@unijaya.example', 'RK', '#7a5bb0', 68, 10.0, 75],
            ['Siti Khadijah', 'Marketing Executive', 'Marketing', 'PJ HQ', 'L3', 'on_leave', 'grey', 'On leave', 'siti.k@unijaya.example', 'SK', '#3a6ea5', 45, 5.0, 66],
            ['Lim Chee Keong', 'Procurement Officer', 'Procurement', 'Klang', 'L4', 'active', 'amber', 'Near capacity', 'cklim@unijaya.example', 'LC', '#1f8a65', 53, 7.5, 70],
            ['Farah Aziz', 'Marketing Executive', 'Marketing', 'PJ HQ', 'L2', 'probation', 'green', 'Healthy', 'farah.aziz@unijaya.example', 'FA', '#c08532', 20, 14.0, 60],
            ['Daniel Lee', 'Sales Executive', 'Sales', 'Kuala Lumpur', 'L3', 'active', 'green', 'Healthy', 'daniel.lee@unijaya.example', 'DL', '#3a6ea5', 60, 11.0, 84],
            ['Hafiz Zulkifli', 'Warehouse Supervisor', 'Logistics', 'Klang', 'L4', 'active', 'red', 'Overloaded', 'hafiz.z@unijaya.example', 'HZ', '#d6232b', 15, 4.5, 68],
        ];

        // Dates of birth drive the SOCSO/EIS contribution category. Faizal is ≥60 (Category 2 —
        // SOCSO Employment-Injury only, no EIS); Siti is left null to exercise the missing-DOB path.
        $dob = [
            'Faizal Othman' => Carbon::create(1962, 4, 10),
            'Tan Wei Ming' => Carbon::create(1990, 8, 2),
            'Ravi Kumar' => Carbon::create(1988, 1, 19),
            'Lim Chee Keong' => Carbon::create(1979, 11, 5),
            'Farah Aziz' => Carbon::create(1998, 6, 30),
            'Daniel Lee' => Carbon::create(1992, 3, 22),
            'Hafiz Zulkifli' => Carbon::create(1985, 9, 14),
        ];

        $emp = [];
        foreach ($rows as $r) {
            $emp[$r[0]] = Employee::create([
                'tenant_id' => $tid,
                'user_id' => $r[0] === 'Aisyah Rahman' ? $demo->id : null,
                'department_id' => $depts[$r[2]],
                'branch_id' => $branches[$r[3]],
                'name' => $r[0], 'position' => $r[1], 'level' => $r[4],
                'status' => $r[5], 'workload' => $r[6], 'workload_label' => $r[7],
                'email' => $r[8], 'initials' => $r[9], 'avatar_color' => $r[10],
                'photo' => null, // off-origin avatars are blocked by CSP; UI uses the initials circle
                'staff_id' => 'UR-'.str_pad((string) (rand(100, 999)), 4, '0', STR_PAD_LEFT),
                'joined_at' => Carbon::create(2022, 3, 14),
                'date_of_birth' => $dob[$r[0]] ?? null,
                'leave_balance' => $r[12], 'kpi_pct' => $r[13],
            ]);
        }

        // Demo work arrangements driving the geofenced attendance rules:
        // Ravi is a resident engineer at the client site, Daniel is hybrid (Mon–Wed office),
        // Tan works fully from home (his home registers on his first home clock-in).
        $emp['Ravi Kumar']->update(['work_arrangement' => 'client', 'work_site_id' => $petronSite->id]);
        $emp['Daniel Lee']->update(['work_arrangement' => 'hybrid', 'hybrid_office_days' => [1, 2, 3]]);
        $emp['Tan Wei Ming']->update(['work_arrangement' => 'wfh']);

        // Reporting lines + Nurul's profile detail (the 360 profile screen subject).
        foreach (['Nurul Iman binti Hassan', 'Farah Aziz', 'Siti Khadijah'] as $name) {
            $emp[$name]->update(['reports_to_id' => $emp['Aisyah Rahman']->id]);
        }
        $emp['Nurul Iman binti Hassan']->update([
            'skills' => ['Payroll', 'EPF/SOCSO', 'Employee Relations', 'HRIS', 'Recruitment', 'Policy Drafting'],
            'interests' => ['People Analytics', 'Leadership', 'L&D Strategy', 'Org Design'],
            'personality' => [
                'type' => 'ENFJ-A · The Protagonist',
                'animal' => 'Dolphin',
                'blurb' => 'Works best leading cross-functional initiatives and mentoring. Prefers clear ownership and advance notice on changes.',
                'traits' => [
                    ['label' => 'People-focused', 'pct' => 88, 'color' => 'green'],
                    ['label' => 'Structured & organised', 'pct' => 81, 'color' => 'info'],
                    ['label' => 'Detail orientation', 'pct' => 64, 'color' => 'amber'],
                ],
            ],
        ]);
        foreach ([
            ['Promoted to Senior HR Executive', 'Jan 2025', 'green', 3],
            ['Completed HR Analytics certification', 'Nov 2024', 'info', 2],
            ['Joined Unijaya Resources', 'Mar 2022', 'muted', 1],
        ] as $c) {
            CareerTimelineEntry::create(['employee_id' => $emp['Nurul Iman binti Hassan']->id, 'title' => $c[0], 'date_label' => $c[1], 'category' => $c[2], 'sort' => $c[3]]);
        }

        // Work items (board + employee dashboard) for Aisyah.
        $aisyah = $emp['Aisyah Rahman']->id;
        foreach ([
            ['Prepare Q3 payroll reconciliation', 'assignment', 'prog', 'high', 'Due tomorrow', 8, 60],
            ['Review new hire documents — Farah Aziz', 'task', 'todo', 'medium', 'Due Thu, 25 Jun', 4, 25],
            ['Urgent: respond to EPF audit query', 'adhoc', 'todo', 'high', 'Due today', 3, 10],
            ['Update leave policy v3.2 handbook entry', 'assignment', 'review', 'low', 'Due Fri, 26 Jun', 6, 80],
            ['Draft FY26 leave policy revision', 'assignment', 'review', 'medium', 'Reviewer: Nurul', 8, 90],
            ['Close FY25 payroll audit', 'task', 'done', 'high', 'Closed', 12, 100],
            ['Onboarding pack — Farah Aziz', 'task', 'done', 'medium', 'Closed', 5, 100],
        ] as $w) {
            WorkItem::create(['tenant_id' => $tid, 'employee_id' => $aisyah, 'title' => $w[0], 'type' => $w[1], 'status' => $w[2], 'priority' => $w[3], 'due_label' => $w[4], 'estimate_hours' => $w[5], 'progress' => $w[6]]);
        }

        // Live-workload backing data. The Employee `workload` accessor derives green/amber/red
        // from each person's OPEN (not-done) work-item count, so the AI Workforce Intelligence
        // screen, its recommendations and the directory tiles need real items to tell their
        // story. Faizal & Hafiz land in the red band (>= 7 open), Tan & Lim in amber (4–6);
        // everyone else stays green on zero items. This replaces the old frozen `workload` column.
        $loadCycle = ['todo', 'prog', 'review'];
        foreach (['Faizal Othman' => 8, 'Hafiz Zulkifli' => 7, 'Tan Wei Ming' => 5, 'Lim Chee Keong' => 4] as $name => $openCount) {
            for ($i = 0; $i < $openCount; $i++) {
                WorkItem::create([
                    'tenant_id' => $tid, 'employee_id' => $emp[$name]->id,
                    'title' => $name.' — open task '.($i + 1), 'type' => 'task',
                    'status' => $loadCycle[$i % count($loadCycle)], 'priority' => 'medium',
                ]);
            }
        }

        // Attendance — this week for Aisyah.
        foreach ([
            [Carbon::create(2026, 6, 22), '09:01', 'on_time'],
            [Carbon::create(2026, 6, 18), '09:18', 'late'],
            [Carbon::create(2026, 6, 17), '08:55', 'on_time'],
            [Carbon::create(2026, 6, 16), '08:48', 'on_time'],
            [Carbon::create(2026, 6, 23), null, 'pending'],
        ] as $a) {
            AttendanceRecord::create([
                'tenant_id' => $tid, 'employee_id' => $aisyah, 'date' => $a[0],
                'clock_in' => $a[1], 'status' => $a[2], 'type' => 'standard',
                'location' => 'Unijaya PJ HQ', 'lat' => '3.1073', 'lng' => '101.6067',
            ]);
        }

        // Leave types + balances + a pending request + team calendar + holidays.
        // Aligned with the Employment Act 1955 (2022 amendments). Columns:
        //   name, entitlement (paid days), requires_attachment (legal proof),
        //   is_unplanned (emergency-style), min_notice_days (advance-application rule).
        //   Sick/Medical & Hospitalisation → medical certificate (s.60F)
        //   Maternity (98 days, s.37) & Paternity (7 days, s.60FA) → supporting document
        //   Annual is planned leave → 3 days' notice. Emergency is unplanned, has no
        //   entitlement of its own and deducts from Annual (wired below).
        $types = collect([
            ['Annual', 16, false, false, 3],
            ['Medical', 14, true, false, 0],
            ['Hospitalization', 60, true, false, 0],
            ['Maternity', 98, true, false, 0],
            ['Paternity', 7, true, false, 0],
            ['Replacement', 4, false, false, 0],
            ['Emergency', 0, false, true, 0],
            ['Compassionate', 3, false, false, 0],
            ['Marriage', 3, false, false, 0],
            ['Unpaid', 0, false, false, 0],
        ])->mapWithKeys(fn ($x) => [$x[0] => LeaveType::create([
            'tenant_id' => $tid,
            'name' => $x[0],
            'entitlement' => $x[1],
            'requires_attachment' => $x[2],
            'is_unplanned' => $x[3],
            'min_notice_days' => $x[4],
        ])->id]);

        // Emergency leave is not a privilege — it spends the Annual balance.
        LeaveType::whereKey($types['Emergency'])->update(['deducts_from_leave_type_id' => $types['Annual']]);

        // Opening balances only for types with an entitlement of their own (Emergency
        // has none — it draws down Annual).
        foreach ([['Annual', 12.5], ['Medical', 14], ['Replacement', 2]] as $b) {
            LeaveBalance::create(['employee_id' => $aisyah, 'leave_type_id' => $types[$b[0]], 'balance' => $b[1]]);
        }
        LeaveRequest::create(['tenant_id' => $tid, 'employee_id' => $aisyah, 'leave_type_id' => $types['Annual'], 'date_from' => Carbon::create(2026, 7, 1), 'date_to' => Carbon::create(2026, 7, 3), 'days' => 3, 'reason' => 'Family trip — Hari Raya Haji long weekend.', 'status' => 'submitted']);
        // Team calendar entries (others on leave).
        LeaveRequest::create(['tenant_id' => $tid, 'employee_id' => $emp['Siti Khadijah']->id, 'leave_type_id' => $types['Annual'], 'date_from' => Carbon::create(2026, 6, 23), 'date_to' => Carbon::create(2026, 6, 27), 'days' => 5, 'status' => 'approved']);
        LeaveRequest::create(['tenant_id' => $tid, 'employee_id' => $emp['Daniel Lee']->id, 'leave_type_id' => $types['Medical'], 'date_from' => Carbon::create(2026, 6, 24), 'date_to' => Carbon::create(2026, 6, 24), 'days' => 1, 'status' => 'approved']);

        PublicHoliday::create(['tenant_id' => $tid, 'name' => 'Hari Raya Aidiladha', 'date' => Carbon::create(2026, 6, 27), 'state' => 'Selangor']);
        PublicHoliday::create(['tenant_id' => $tid, 'name' => 'Awal Muharram', 'date' => Carbon::create(2026, 7, 16), 'state' => 'National']);

        // KPI items for Aisyah (H1 cycle).
        foreach ([
            ['Reduce payroll error rate', 'results', '< 0.5%', '0.3%', 100, '30%', 'green'],
            ['Onboarding cycle time', 'execution', '≤ 10 days', '9 days', 90, '25%', 'green'],
            ['Policy acknowledgement rate', 'execution', '100%', '92%', 72, '20%', 'amber'],
            ['Leadership 360 score', 'behaviour', '4 / 5', '3.6', 72, '15%', 'amber'],
            ['HR Analytics certification', 'development', 'Certified', 'In progress', 55, '10%', 'amber'],
        ] as $k) {
            KpiItem::create(['tenant_id' => $tid, 'employee_id' => $aisyah, 'title' => $k[0], 'category' => $k[1], 'target' => $k[2], 'actual' => $k[3], 'progress' => $k[4], 'weight' => $k[5], 'status' => $k[6]]);
        }

        // Onboarding — Farah Aziz.
        $onb = OnboardingProfile::create([
            'tenant_id' => $tid, 'employee_id' => $emp['Farah Aziz']->id,
            'mentor_id' => $emp['Siti Khadijah']->id, 'manager_id' => $aisyah,
            'start_date' => Carbon::create(2026, 6, 11), 'day_number' => 12, 'total_days' => 90,
        ]);
        $general = [
            ['Company introduction & history', true], ['Vision, mission & values', true],
            ['Employee handbook acknowledgement', true], ['IT security & acceptable use policy', true],
            ['Submit required documents', false], ['Digital acceptance of policies', false],
        ];
        $position = [
            ['Review job description & standard tasks', true], ['Access to Marketing systems (Canva, CRM)', true],
            ['Read brand SOPs', false], ['Meet assigned mentor — Siti Khadijah', true],
            ['30-day plan agreed with manager', false], ['60-day plan', false], ['90-day plan & confirmation checklist', false],
        ];
        foreach ($general as $i => $g) {
            OnboardingTask::create(['onboarding_profile_id' => $onb->id, 'track' => 'general', 'title' => $g[0], 'done' => $g[1], 'sort' => $i]);
        }
        foreach ($position as $i => $p) {
            OnboardingTask::create(['onboarding_profile_id' => $onb->id, 'track' => 'position', 'title' => $p[0], 'done' => $p[1], 'sort' => $i]);
        }

        foreach ([
            ['Hari Raya Aidiladha — office closed', '27 Jun 2026', 'Holiday'],
            ['New medical claim limits effective July', '20 Jun 2026', 'Policy'],
            ['Townhall recording now available', '18 Jun 2026', 'Company'],
        ] as $a) {
            Announcement::create(['tenant_id' => $tid, 'title' => $a[0], 'date' => Carbon::create(2026, 6, (int) substr($a[1], 0, 2)), 'tag' => $a[2]]);
        }

        // Recognition feed + leaderboard. [employee_id, who, title, category, icon, points, date, date_label]
        foreach ([
            [$aisyah, 'Aisyah Rahman', 'Closed FY25 payroll audit with zero findings', 'Milestone', 'medal', 120, '2026-06-21', '2 days ago'],
            [$emp['Nurul Iman binti Hassan']->id, 'Nurul Iman', 'Cut onboarding time from 14 to 9 days', 'Award', 'trophy', 150, '2026-06-16', 'last week'],
            [$emp['Daniel Lee']->id, 'Daniel Lee', 'Closed RM 1.2M in Q2 sales — top performer', 'Award', 'trophy', 200, '2026-06-10', '2 weeks ago'],
            [$emp['Ravi Kumar']->id, 'Ravi Kumar', 'Resolved a P1 outage in under 30 minutes', 'Spot Award', 'zap', 80, '2026-06-05', '3 weeks ago'],
            [$emp['Tan Wei Ming']->id, 'Tan Wei Ming', 'Automated the monthly close — saved 2 days', 'Recognition', 'star', 60, '2026-05-28', 'last month'],
            [$emp['Nurul Iman binti Hassan']->id, 'Nurul Iman', '5 years of service at Unijaya', 'Milestone', 'star', 100, '2026-03-22', 'Mar 2026'],
        ] as $a) {
            Achievement::create(['tenant_id' => $tid, 'employee_id' => $a[0], 'who' => $a[1], 'title' => $a[2], 'category' => $a[3], 'icon' => $a[4], 'points' => $a[5], 'date' => Carbon::parse($a[6]), 'date_label' => $a[7]]);
        }

        // Performance reviews — Aisyah's own history + open cycle, plus team reviews for the HR/manager view.
        PerformanceReview::create([
            'tenant_id' => $tid, 'employee_id' => $aisyah, 'reviewer_id' => null,
            'cycle' => '2025 H1', 'period_label' => 'Jan–Jun 2025', 'status' => 'acknowledged',
            'overall_rating' => 4.0, 'rating_label' => 'Meets & exceeds',
            'strengths' => 'Reliable delivery and strong stakeholder trust across departments.',
            'improvements' => 'Increase the visibility of HR metrics to senior leadership.',
            'goals' => 'Roll out the revised leave policy company-wide.',
            'self_assessment' => 'Delivered every payroll cycle on time and rebuilt the onboarding flow.',
            'competencies' => [['label' => 'Delivery & results', 'score' => 4.2], ['label' => 'Collaboration', 'score' => 4.0], ['label' => 'Leadership', 'score' => 3.7]],
            'review_date' => Carbon::create(2025, 7, 15), 'acknowledged_at' => Carbon::create(2025, 7, 20),
        ]);
        PerformanceReview::create([
            'tenant_id' => $tid, 'employee_id' => $aisyah, 'reviewer_id' => null,
            'cycle' => '2025 H2', 'period_label' => 'Jul–Dec 2025', 'status' => 'completed',
            'overall_rating' => 4.2, 'rating_label' => 'Exceeds expectations',
            'strengths' => 'Strong payroll governance; closed the year-end audit with zero findings; mentors junior HR staff well.',
            'improvements' => 'Delegate more operational tasks to build team bench strength.',
            'goals' => 'Lead the HRIS migration and complete the HR Analytics certification.',
            'self_assessment' => 'Focused on audit readiness and modernising the leave policy this half.',
            'competencies' => [['label' => 'Delivery & results', 'score' => 4.5], ['label' => 'Collaboration', 'score' => 4.0], ['label' => 'Leadership', 'score' => 4.0], ['label' => 'Innovation', 'score' => 3.8], ['label' => 'Communication', 'score' => 4.3]],
            'review_date' => Carbon::create(2026, 1, 20),
        ]);
        PerformanceReview::create([
            'tenant_id' => $tid, 'employee_id' => $aisyah, 'reviewer_id' => null,
            'cycle' => '2026 H1', 'period_label' => 'Jan–Jun 2026', 'status' => 'in_progress',
            'goals' => 'Mid-year review window open until 15 July.',
        ]);
        PerformanceReview::create([
            'tenant_id' => $tid, 'employee_id' => $emp['Nurul Iman binti Hassan']->id, 'reviewer_id' => $aisyah,
            'cycle' => '2026 H1', 'period_label' => 'Jan–Jun 2026', 'status' => 'completed',
            'overall_rating' => 4.6, 'rating_label' => 'Outstanding',
            'strengths' => 'Process innovation — reduced onboarding time by 36%. Trusted across the team.',
            'improvements' => 'Document playbooks so the gains survive a handover.',
            'goals' => 'Own the people-analytics dashboard for 2026 H2.',
            'competencies' => [['label' => 'Delivery & results', 'score' => 4.8], ['label' => 'Collaboration', 'score' => 4.5], ['label' => 'Leadership', 'score' => 4.4]],
            'review_date' => Carbon::create(2026, 6, 18),
        ]);
        PerformanceReview::create([
            'tenant_id' => $tid, 'employee_id' => $emp['Faizal Othman']->id, 'reviewer_id' => $aisyah,
            'cycle' => '2026 H1', 'period_label' => 'Jan–Jun 2026', 'status' => 'in_progress',
        ]);

        // Claims (expense / mileage / medical).
        foreach ([
            [$aisyah, 'mileage', 'Client visit — Klang HQ', 184.50, 'approved', '2026-06-12'],
            [$aisyah, 'medical', 'GP consultation + meds', 95.00, 'submitted', '2026-06-20'],
            [$emp['Faizal Othman']->id, 'travel', 'Seremban site — tolls & fuel', 142.30, 'submitted', '2026-06-19'],
            [$emp['Ravi Kumar']->id, 'expense', 'USB-C dock for workstation', 219.00, 'approved', '2026-06-05'],
            [$emp['Daniel Lee']->id, 'mileage', 'Sales calls — KL central', 88.00, 'paid', '2026-05-28'],
        ] as $c) {
            Claim::create(['tenant_id' => $tid, 'employee_id' => $c[0], 'type' => $c[1], 'title' => $c[2], 'amount' => $c[3], 'status' => $c[4], 'date' => Carbon::parse($c[5])]);
        }

        // Assets register.
        foreach ([
            ['MacBook Pro 14"', 'laptop', 'C02ABCXY1234', $aisyah, 'assigned'],
            ['iPhone 15', 'phone', '356789101112', $aisyah, 'assigned'],
            ['Dell Latitude 5440', 'laptop', 'DLAT5440-771', $emp['Ravi Kumar']->id, 'assigned'],
            ['Toyota Hilux (WXY 1234)', 'vehicle', 'VIN-HLX-2023', $emp['Faizal Othman']->id, 'assigned'],
            ['Lenovo ThinkPad T14', 'laptop', 'TP14-0098', null, 'available'],
            ['Standing desk', 'furniture', null, null, 'maintenance'],
        ] as $a) {
            Asset::create(['tenant_id' => $tid, 'name' => $a[0], 'category' => $a[1], 'serial' => $a[2], 'employee_id' => $a[3], 'status' => $a[4], 'assigned_at' => $a[3] ? Carbon::create(2024, 1, 15) : null]);
        }

        // Training records (some mandatory + overdue — matches HR dashboard signal).
        foreach ([
            [$aisyah, 'Anti-Bribery & Corruption 2026', 'Compliance Dept', 'completed', true, '2026-03-31', '2026-03-12'],
            [$aisyah, 'Leadership Essentials', 'Talent Academy', 'in_progress', false, '2026-07-30', null],
            [$emp['Farah Aziz']->id, 'New Hire Security Awareness', 'IT Security', 'not_started', true, '2026-06-20', null],
            [$emp['Faizal Othman']->id, 'Workplace Safety (OSH)', 'OSH Malaysia', 'not_started', true, '2026-06-15', null],
            [$emp['Ravi Kumar']->id, 'ITIL Foundation', 'PeopleCert', 'in_progress', false, '2026-08-15', null],
            [$emp['Nurul Iman binti Hassan']->id, 'HR Analytics', 'Coursera', 'in_progress', false, '2026-09-01', null],
        ] as $t) {
            TrainingRecord::create(['tenant_id' => $tid, 'employee_id' => $t[0], 'course' => $t[1], 'provider' => $t[2], 'status' => $t[3], 'mandatory' => $t[4], 'due_at' => Carbon::parse($t[5]), 'completed_at' => $t[6] ? Carbon::parse($t[6]) : null]);
        }

        // Handbook / policy sections.
        $sections = [];
        foreach ([
            ['Conduct', 'Code of Conduct', '2.1', true, 'All employees must act with integrity, avoid conflicts of interest, and treat colleagues and clients with respect. Breaches are subject to disciplinary action.'],
            ['Leave & Benefits', 'Leave Policy', '3.2', true, 'Annual, medical, replacement and emergency leave entitlements, application notice periods, and carry-forward rules. Updated July 2026.'],
            ['Leave & Benefits', 'Medical & Claims', '1.4', false, 'Outpatient and specialist claim limits, panel clinics, and the reimbursement process for medical and travel expenses.'],
            ['IT & Security', 'IT Acceptable Use', '2.0', true, 'Acceptable use of company devices, data handling, password hygiene, and reporting of security incidents.'],
            ['Health & Safety', 'Workplace Safety (OSH)', '1.0', false, 'Emergency procedures, incident reporting, and occupational safety responsibilities under the OSH Act.'],
        ] as $i => $s) {
            $sections[] = HandbookSection::create(['tenant_id' => $tid, 'category' => $s[0], 'title' => $s[1], 'version' => $s[2], 'requires_ack' => $s[3], 'body' => $s[4], 'sort' => $i]);
        }
        // Aisyah has acknowledged Conduct + IT, but not the new Leave v3.2.
        foreach ([0, 3] as $idx) {
            PolicyAcknowledgement::create(['tenant_id' => $tid, 'employee_id' => $aisyah, 'handbook_section_id' => $sections[$idx]->id, 'version' => $sections[$idx]->version, 'acknowledged_at' => Carbon::create(2026, 5, 2)]);
        }
        PolicyAcknowledgement::create(['tenant_id' => $tid, 'employee_id' => $emp['Nurul Iman binti Hassan']->id, 'handbook_section_id' => $sections[0]->id, 'version' => '2.1', 'acknowledged_at' => Carbon::create(2026, 5, 3)]);

        // Audit history.
        foreach ([
            ['Aisyah Rahman', 'Approved leave', 'Daniel Lee · 1d', '2026-06-21 14:32:00'],
            ['Aisyah Rahman', 'Approved claim', 'Ravi Kumar · RM 219.00', '2026-06-20 09:15:00'],
            ['Aisyah Rahman', 'Changed role', 'Nurul Iman → manager', '2026-06-18 16:40:00'],
            ['Aisyah Rahman', 'Updated company settings', 'Unijaya Resources', '2026-06-15 11:05:00'],
            ['Aisyah Rahman', 'Acknowledged policy', 'IT Acceptable Use v2.0', '2026-05-02 08:50:00'],
        ] as $a) {
            $log = AuditLog::create(['tenant_id' => $tid, 'user_id' => $demo->id, 'actor_name' => $a[0], 'action' => $a[1], 'target' => $a[2]]);
            $log->forceFill(['created_at' => Carbon::parse($a[3])])->save();
        }

        // A couple of unread notifications so the header bell has signal on first load.
        AppNotification::create(['tenant_id' => $tid, 'user_id' => $demo->id, 'title' => 'You were recognised', 'body' => 'Milestone · Closed FY25 payroll audit with zero findings', 'url' => '/app/achievements']);

        $this->seedPayroll($tid, $demo, $emp);
    }

    /**
     * Payroll: editable statutory rate tables, salary structures for every employee,
     * and one finalized prior-month (May 2026) run with payslips computed by the real
     * PayrollCalculator so the screen shows live figures on first load.
     *
     * @param  array<string, Employee>  $emp
     */
    private function seedPayroll(int $tid, User $demo, array $emp): void
    {
        // Statutory rate tables — seeded from current published MY defaults (editable in-app).
        foreach (StatutoryRate::defaults() as $type => $config) {
            // forceFill: tenant_id is set explicitly here (multi-tenant seed loop) and is
            // not mass-assignable on the tightened payroll models.
            (new StatutoryRate)->forceFill([
                'tenant_id' => $tid, 'type' => $type, 'config' => $config, 'label' => strtoupper($type),
            ])->save();
        }

        // [basic, [[allowance name, amount]], manual PCB, OT hours, bonus] keyed by employee name.
        $salaries = [
            'Aisyah Rahman' => [11000, [['Transport', 400], ['Phone', 150]], 950, 0, 0],
            'Nurul Iman binti Hassan' => [6500, [['Transport', 300]], 320, 0, 0],
            'Faizal Othman' => [8500, [['Transport', 350], ['Site allowance', 400]], 540, 12, 0],
            'Tan Wei Ming' => [5200, [['Transport', 250]], 180, 0, 0],
            'Ravi Kumar' => [4800, [['Transport', 250], ['Phone', 100]], 120, 8, 0],
            'Siti Khadijah' => [4500, [['Transport', 250]], 95, 0, 0],
            'Lim Chee Keong' => [6000, [['Transport', 300]], 260, 0, 0],
            'Farah Aziz' => [3200, [['Transport', 200]], 0, 0, 0],
            'Daniel Lee' => [4500, [['Transport', 300], ['Comms', 150]], 110, 0, 800],
            'Hafiz Zulkifli' => [5800, [['Transport', 300]], 240, 6, 0],
        ];

        $calculator = new PayrollCalculator;
        $rates = StatutoryRate::defaults();
        $banks = ['Maybank', 'CIMB Bank', 'Public Bank', 'RHB Bank', 'Hong Leong Bank'];

        // forceFill: tenant_id/status/finalized_at are not mass-assignable on the tightened model.
        $run = (new PayrollRun)->forceFill([
            'tenant_id' => $tid, 'period' => '2026-05', 'label' => 'May 2026', 'status' => 'finalized',
            'run_by_id' => $demo->id, 'approved_by_id' => $demo->id, 'finalized_at' => Carbon::create(2026, 5, 28, 17, 0),
        ]);
        $run->save();

        foreach ($salaries as $name => $row) {
            if (! isset($emp[$name])) {
                continue;
            }
            $employee = $emp[$name];
            $allowances = collect($row[1])->map(fn ($a) => ['name' => $a[0], 'amount' => (float) $a[1]])->all();

            $eid = $employee->id;
            // forceFill: tenant_id is set explicitly in this multi-tenant seed loop.
            (new SalaryStructure)->forceFill([
                'tenant_id' => $tid, 'employee_id' => $eid,
                'basic_salary' => $row[0], 'allowances' => $allowances,
                'effective_from' => $name === 'Farah Aziz' ? Carbon::create(2026, 6, 11) : Carbon::create(2024, 1, 1),
                'bank_name' => $banks[$eid % count($banks)],
                'bank_account_no' => '51'.str_pad((string) ($eid * 7919 % 100000000), 8, '0', STR_PAD_LEFT),
                'epf_no' => 'EPF'.str_pad((string) (1000000 + $eid * 31), 8, '0', STR_PAD_LEFT),
                'socso_no' => 'SOC'.str_pad((string) (2000000 + $eid * 17), 8, '0', STR_PAD_LEFT),
                'nric' => str_pad((string) (850000 + $eid), 6, '0', STR_PAD_LEFT).'-'.str_pad((string) (10 + $eid % 14), 2, '0', STR_PAD_LEFT).'-'.str_pad((string) (1000 + $eid * 13 % 9000), 4, '0', STR_PAD_LEFT),
            ])->save();

            $comp = $calculator->compute([
                'basic' => $row[0],
                'allowances_total' => collect($allowances)->sum('amount'),
                'pcb' => $row[2],
                'overtime_hours' => $row[3],
                'bonus' => $row[4],
                'statutory_category' => $employee->statutoryCategory(Carbon::create(2026, 5, 31)),
            ], $rates);

            // forceFill: computed amount columns + tenant_id are not mass-assignable.
            (new Payslip)->forceFill(array_merge($comp->toPayslipAttributes(), [
                'tenant_id' => $tid, 'payroll_run_id' => $run->id, 'employee_id' => $employee->id,
            ]))->save();
        }

        $slips = $run->payslips()->get();
        // totals is a computed cache column excluded from $fillable — set it directly.
        $run->forceFill(['totals' => [
            'headcount' => $slips->count(),
            'gross' => round((float) $slips->sum('gross'), 2),
            'deductions' => round((float) $slips->sum('total_deductions'), 2),
            'net' => round((float) $slips->sum('net_pay'), 2),
            'employer_cost' => round((float) $slips->sum('employer_cost'), 2),
        ]])->save();
    }

    /** Lightweight seed so other tenants exist for switching + isolation testing. */
    private function seedMinimal(Tenant $t): void
    {
        $tid = $t->id;
        $dept = Department::create(['tenant_id' => $tid, 'name' => 'Operations'])->id;
        $branch = Branch::create(['tenant_id' => $tid, 'name' => 'Main', 'state' => 'Selangor'])->id;
        foreach ([['Ahmad Faizal', 'Station Manager', 'AF', '#1f8a65'], ['Mei Ling', 'Cashier Lead', 'ML', '#3a6ea5']] as $r) {
            Employee::create([
                'tenant_id' => $tid, 'department_id' => $dept, 'branch_id' => $branch,
                'name' => $r[0], 'position' => $r[1], 'level' => 'L3', 'status' => 'active',
                'workload' => 'green', 'workload_label' => 'Healthy', 'initials' => $r[2],
                'avatar_color' => $r[3], 'leave_balance' => 10, 'kpi_pct' => 70,
            ]);
        }
        Announcement::create(['tenant_id' => $tid, 'title' => 'Welcome to '.$t->name, 'date' => Carbon::create(2026, 6, 1), 'tag' => 'Company']);
    }
}
