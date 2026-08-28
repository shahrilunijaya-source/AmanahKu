<?php

declare(strict_types=1);

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\CompanyCategory;
use App\Models\Department;
use App\Models\Employee;
use App\Models\PayrollItem;
use App\Models\Tenant;
use App\Models\TimesheetCategory;
use App\Models\User;
use App\Notifications\MemberInvited;
use App\Services\FeatureManager;
use App\Support\Permissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View as ViewContract;

/**
 * Cross-tenant company provisioning. Reachable only behind the super.admin guard.
 * Creating a company is a single atomic act: the tenant, its first branch and
 * department, and its first HR admin (with a one-time password) are all created
 * together or not at all.
 */
class CompanyController extends Controller
{
    private const PLANS = ['Enterprise', 'Business', 'Starter'];

    /** List every company with headline counts. */
    public function index(): ViewContract
    {
        $companies = Tenant::query()
            ->with('companyCategory')
            ->withCount(['employees', 'users'])
            ->orderBy('name')
            ->get();

        return view('superadmin.companies.index', [
            'companies' => $companies,
            'failedJobs' => $this->failedJobSummary(),
            'stuckJobs' => $this->stuckJobCount(),
        ]);
    }

    /**
     * Queued jobs that have sat waiting far longer than a healthy worker would leave
     * them — the signature of a queue worker that is not running at all.
     *
     * The failed-jobs banner cannot see this: a job nobody picks up never fails, so a
     * stopped worker shows an empty failed_jobs table while every invite and reset
     * silently piles up (prod, 2026-08-03 — seven members never got their activation
     * mail while the console looked healthy).
     */
    private function stuckJobCount(): int
    {
        // available_at is a unix timestamp, and is set into the future for delayed
        // jobs — so this counts only work that was due ten minutes ago and is still
        // sitting there, not work that is legitimately waiting for its turn.
        return DB::table('jobs')
            ->where('available_at', '<', now()->subMinutes(10)->getTimestamp())
            ->count();
    }

    /**
     * Headline state of the failed-jobs table, for the console banner.
     *
     * Every email the app sends goes out through the queue, so a non-empty
     * failed_jobs table usually means invites and password resets are not being
     * delivered. This banner is the only place a super-admin is told: the alert
     * cannot be emailed (mail is the thing that is broken) and cannot use the
     * in-app bell (AppNotification is tenant-scoped and a super-admin is not).
     *
     * @return array{count: int, latest: ?string, failedAt: ?string}
     */
    private function failedJobSummary(): array
    {
        $count = DB::table('failed_jobs')->count();

        if ($count === 0) {
            return ['count' => 0, 'latest' => null, 'failedAt' => null];
        }

        $latest = DB::table('failed_jobs')->orderByDesc('failed_at')->first(['payload', 'failed_at']);

        return [
            'count' => $count,
            // Queued notifications report the notification class as displayName, so this
            // names the actual mail that failed rather than the generic queue wrapper.
            'latest' => data_get(json_decode($latest->payload, true), 'displayName', 'Unknown job'),
            'failedAt' => $latest->failed_at,
        ];
    }

    /** New-company form. */
    public function create(): ViewContract
    {
        return view('superadmin.companies.create', [
            'plans' => self::PLANS,
            'categories' => CompanyCategory::orderBy('level')->get(),
        ]);
    }

    /** Provision a company + its first HR admin atomically. */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate($this->profileRules() + [
            'company_category_id' => ['required', 'exists:company_categories,id'],
            'branch_name' => ['required', 'string', 'max:120'],
            'branch_state' => ['nullable', 'string', 'max:80'],
            'department_name' => ['required', 'string', 'max:120'],
            'admin_name' => ['required', 'string', 'max:120'],
            'admin_email' => ['required', 'email', 'max:160', 'unique:users,email'],
        ]);

        $category = CompanyCategory::findOrFail($data['company_category_id']);
        $tempPassword = Str::password(14);

        $result = DB::transaction(function () use ($data, $category, $tempPassword) {
            $tenant = Tenant::create([
                'slug' => $this->uniqueSlug($data['company_name']),
                'name' => $data['company_name'],
                'registration_number' => $data['registration_number'] ?? null,
                'company_code' => $data['company_code'] ?? null,
                'industry' => $data['industry'] ?? null,
                'address' => $data['address'] ?? null,
                'contact_number' => $data['contact_number'] ?? null,
                'email' => $data['email'] ?? null,
                'website' => $data['website'] ?? null,
                'initials' => $this->initials($data['company_name']),
                'color' => $data['color'] ?? config('amanahku.brand_color'),
                'secondary_color' => $data['secondary_color'] ?? null,
                'welcome_message' => $data['welcome_message'] ?? null,
                'plan' => $data['plan'],
                'company_category_id' => $category->id,
                'meta' => '1 branch · 1 employee',
                'status' => 'active',
                // New companies enforce the onboarding gates (launch lock + staff
                // profile completion) from day one.
                'onboarding_enforced' => true,
                'subscription_start' => $data['subscription_start'] ?? null,
                'subscription_end' => $data['subscription_end'] ?? null,
            ]);

            // Seed the feature entitlement from the chosen category package. From here
            // the resolved entitlement — not the category — is the source of truth.
            app(FeatureManager::class)->applyCategoryPackage($tenant, $category->level);

            // Every tenant needs the statutory pay-item catalogue for payroll to work —
            // no deploy step is guaranteed to run PayrollItemSeeder for a company created
            // after that deploy, so seed it here instead.
            PayrollItem::seedFor($tenant);

            // Same reasoning for the timesheet's effort types: the capture screen has no
            // category picker any more (its rows arrive from board cards), so a company
            // with no categories could not cost a single hour until someone added them by
            // hand on Timesheet Setup.
            TimesheetCategory::seedFor($tenant);

            $branch = Branch::create([
                'tenant_id' => $tenant->id,
                'name' => $data['branch_name'],
                'state' => $data['branch_state'] ?? null,
            ]);

            $department = Department::create([
                'tenant_id' => $tenant->id,
                'name' => $data['department_name'],
            ]);

            // The seed account is a full HR admin so the new company is immediately operable.
            $admin = User::create([
                'name' => $data['admin_name'],
                'email' => $data['admin_email'],
                'password' => Hash::make($tempPassword),
            ]);
            // Force rotation of the one-time password on first sign-in (I-008). Not in
            // $fillable, so set it explicitly.
            $admin->forceFill(['password_change_required' => true])->save();
            $admin->tenants()->attach($tenant->id, ['role' => 'hr']);

            Employee::create([
                'tenant_id' => $tenant->id,
                'user_id' => $admin->id,
                'department_id' => $department->id,
                'branch_id' => $branch->id,
                'name' => $data['admin_name'],
                'email' => $data['admin_email'],
                'position' => 'HR Admin',
                'status' => 'active',
                'workload' => 'green',
                'workload_label' => 'Healthy',
                'initials' => $this->initials($data['admin_name']),
                'avatar_color' => config('amanahku.brand_color'),
                'joined_at' => now()->toDateString(),
            ]);

            // No active tenant in the super-admin context, so the BelongsToTenant
            // auto-fill is a no-op — stamp the new tenant explicitly.
            AuditLog::create([
                'tenant_id' => $tenant->id,
                'user_id' => auth()->id(),
                'actor_name' => auth()->user()?->name ?? 'Super Admin',
                'action' => 'Provisioned company',
                'target' => $tenant->name.' · admin '.$data['admin_email'],
            ]);

            // Email the first admin their one-time credentials.
            $admin->notify(new MemberInvited($tenant, $tempPassword, 'hr'));

            return $tenant;
        });

        // Never echo the one-time password into the flash — the signed activation link +
        // credential are delivered only in the invite email (AK-SEC-10).
        return redirect()
            ->route('superadmin.companies.index')
            ->with('ok', $result->name.' created. First HR admin '.$data['admin_email']
                .' has been emailed an invite to activate their account and set a password.');
    }

    /** Company detail: its members, lifecycle controls and the assign-existing-user form. */
    public function show(Tenant $tenant): ViewContract
    {
        $members = $tenant->users()->orderBy('name')->get();

        return view('superadmin.companies.show', [
            'company' => $tenant->load('companyCategory'),
            'members' => $members,
            'categories' => CompanyCategory::orderBy('level')->get(),
        ]);
    }

    /** Full profile edit form — super-admin owns every field incl. slug/status/subscription. */
    public function edit(Tenant $tenant): ViewContract
    {
        return view('superadmin.companies.edit', [
            'company' => $tenant,
            'plans' => self::PLANS,
            'categories' => CompanyCategory::orderBy('level')->get(),
        ]);
    }

    /**
     * Persist super-admin-owned company fields. Unlike the company-admin settings
     * screen, this is the ONLY place slug, status and subscription dates can change.
     * Category is changed via updateCategory() so the package re-apply stays explicit.
     */
    public function update(Request $request, Tenant $tenant): RedirectResponse
    {
        $data = $request->validate($this->profileRules($tenant) + [
            'slug' => ['required', 'string', 'max:60', 'alpha_dash', Rule::unique('tenants', 'slug')->ignore($tenant->id)],
            'status' => ['required', 'in:active,suspended'],
        ]);

        $tenant->update([
            'slug' => Str::slug($data['slug']),
            'name' => $data['company_name'],
            'registration_number' => $data['registration_number'] ?? null,
            'company_code' => $data['company_code'] ?? null,
            'industry' => $data['industry'] ?? null,
            'address' => $data['address'] ?? null,
            'contact_number' => $data['contact_number'] ?? null,
            'email' => $data['email'] ?? null,
            'website' => $data['website'] ?? null,
            'color' => $data['color'] ?? $tenant->color,
            'secondary_color' => $data['secondary_color'] ?? null,
            'welcome_message' => $data['welcome_message'] ?? null,
            'plan' => $data['plan'],
            'status' => $data['status'],
            'subscription_start' => $data['subscription_start'] ?? null,
            'subscription_end' => $data['subscription_end'] ?? null,
        ]);

        AuditLog::create([
            'tenant_id' => $tenant->id,
            'user_id' => auth()->id(),
            'actor_name' => auth()->user()?->name ?? 'Super Admin',
            'action' => 'Updated company profile',
            'target' => $tenant->name,
        ]);

        return redirect()->route('superadmin.companies.show', $tenant)
            ->with('ok', $tenant->name.' updated.');
    }

    /**
     * Change a company's category and re-seed its feature package. Explicit + audited
     * because re-applying overwrites the tenant's module overrides for that stage.
     * Super-admin only — a company admin can never reach this.
     */
    public function updateCategory(Request $request, Tenant $tenant): RedirectResponse
    {
        $data = $request->validate([
            'company_category_id' => ['required', 'exists:company_categories,id'],
        ]);

        $category = CompanyCategory::findOrFail($data['company_category_id']);
        $tenant->update(['company_category_id' => $category->id]);
        app(FeatureManager::class)->applyCategoryPackage($tenant, $category->level);

        AuditLog::create([
            'tenant_id' => $tenant->id,
            'user_id' => auth()->id(),
            'actor_name' => auth()->user()?->name ?? 'Super Admin',
            'action' => 'Changed company category',
            'target' => $tenant->name.' → '.$category->name,
        ]);

        return back()->with('ok', $tenant->name.' set to '.$category->name.' — feature package re-applied.');
    }

    /** Activate or suspend a company. Suspended companies block all tenant routes. */
    public function setStatus(Request $request, Tenant $tenant): RedirectResponse
    {
        $status = $request->validate(['status' => ['required', 'in:active,suspended']])['status'];
        $tenant->update(['status' => $status]);

        AuditLog::create([
            'tenant_id' => $tenant->id,
            'user_id' => auth()->id(),
            'actor_name' => auth()->user()?->name ?? 'Super Admin',
            'action' => $status === 'suspended' ? 'Suspended company' : 'Activated company',
            'target' => $tenant->name,
        ]);

        return back()->with('ok', $tenant->name.' is now '.$status.'.');
    }

    /**
     * Shared validation for the company profile fields (create + edit). `company_code`
     * is globally unique across tenants (sparse — nulls allowed); ignore self on edit.
     *
     * @return array<string, array<int, mixed>>
     */
    private function profileRules(?Tenant $tenant = null): array
    {
        return [
            'company_name' => ['required', 'string', 'max:120'],
            'registration_number' => ['nullable', 'string', 'max:80'],
            'company_code' => ['nullable', 'string', 'max:40', Rule::unique('tenants', 'company_code')->ignore($tenant?->id)],
            'industry' => ['nullable', 'string', 'max:120'],
            'plan' => ['required', 'in:'.implode(',', self::PLANS)],
            'color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'secondary_color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'welcome_message' => ['nullable', 'string', 'max:240'],
            'address' => ['nullable', 'string', 'max:240'],
            'contact_number' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:160'],
            'website' => ['nullable', 'url', 'max:160'],
            'subscription_start' => ['nullable', 'date'],
            'subscription_end' => ['nullable', 'date', 'after_or_equal:subscription_start'],
        ];
    }

    /**
     * Attach an existing user account to this company with a role. This is the
     * super-admin override of the tenant-level invite (which refuses existing
     * emails): provisioning a genuine multi-workspace user is an admin act.
     */
    public function assignMember(Request $request, Tenant $tenant): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'exists:users,email'],
            'role' => ['required', 'in:employee,manager,management,hr'],
        ]);

        $user = User::where('email', $data['email'])->firstOrFail();

        if ($user->tenants()->where('tenants.id', $tenant->id)->exists()) {
            return back()->withErrors(['email' => 'That user already belongs to this company.']);
        }

        $user->tenants()->attach($tenant->id, [
            'role' => $data['role'],
            'data_scope' => Permissions::defaultScopeForRole($data['role']),
        ]);

        // Mirror an employee record if one does not yet exist for this tenant.
        if (! Employee::where('tenant_id', $tenant->id)->where('user_id', $user->id)->exists()) {
            Employee::create([
                'tenant_id' => $tenant->id,
                'user_id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'position' => ucfirst($data['role']),
                'status' => 'active',
                'workload' => 'green',
                'workload_label' => 'Healthy',
                'initials' => $this->initials($user->name),
                'avatar_color' => config('amanahku.avatar_color'),
                'joined_at' => now()->toDateString(),
            ]);
        }

        AuditLog::create([
            'tenant_id' => $tenant->id,
            'user_id' => auth()->id(),
            'actor_name' => auth()->user()?->name ?? 'Super Admin',
            'action' => 'Assigned existing user',
            'target' => $user->email.' → '.$data['role'],
        ]);

        return back()->with('ok', $user->name.' assigned to '.$tenant->name.' as '.ucfirst($data['role']).'.');
    }

    /** Slug from name, guaranteed unique against existing tenants. */
    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'company';
        $slug = $base;
        $i = 2;

        while (Tenant::where('slug', $slug)->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }

    private function initials(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $first = mb_substr($parts[0] ?? '', 0, 1);
        $last = count($parts) > 1 ? mb_substr((string) end($parts), 0, 1) : '';

        return mb_strtoupper($first.$last) ?: 'NA';
    }
}
