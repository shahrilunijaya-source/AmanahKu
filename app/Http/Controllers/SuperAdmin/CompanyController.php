<?php

declare(strict_types=1);

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\CompanyCategory;
use App\Models\CompanyInvite;
use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\MemberInvited;
use App\Services\CompanyProvisioner;
use App\Services\FeatureManager;
use App\Support\Permissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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

    /** List every company with headline counts, plus the signup links card. */
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
            'invites' => CompanyInvite::with(['category', 'usedByTenant'])->latest()->get(),
            // Stage 2 first: it is the spec default for self-serve companies.
            'categories' => CompanyCategory::orderByDesc('level')->get(),
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

        $tenant = app(CompanyProvisioner::class)->provision(
            company: ['name' => $data['company_name']] + Arr::only($data, [
                'registration_number', 'company_code', 'industry', 'address', 'contact_number',
                'email', 'website', 'color', 'secondary_color', 'welcome_message', 'plan',
                'subscription_start', 'subscription_end',
            ]),
            category: $category,
            structure: [
                'branch_name' => $data['branch_name'],
                'branch_state' => $data['branch_state'] ?? null,
                'department_name' => $data['department_name'],
            ],
            // One-time password, rotated on first sign-in (I-008).
            admin: [
                'name' => $data['admin_name'],
                'email' => $data['admin_email'],
                'password' => $tempPassword,
                'password_change_required' => true,
            ],
            auditAction: 'Provisioned company',
        );

        // Email the first admin their one-time credentials, after the transaction has
        // committed so a queued mail can never reference a rolled-back user.
        User::where('email', $data['admin_email'])->firstOrFail()
            ->notify(new MemberInvited($tenant, $tempPassword, 'hr'));

        // Never echo the one-time password into the flash — the signed activation link +
        // credential are delivered only in the invite email (AK-SEC-10).
        return redirect()
            ->route('superadmin.companies.index')
            ->with('ok', $tenant->name.' created. First HR admin '.$data['admin_email']
                .' has been emailed an invite to activate their account and set a password.');
    }

    /** Company detail: its members, lifecycle controls and the assign-existing-user form. */
    public function show(Tenant $tenant): ViewContract
    {
        $members = $tenant->users()->orderBy('name')->get();

        return view('superadmin.companies.show', [
            'company' => $tenant->load('companyCategory'),
            'members' => $members,
            'deletable' => self::isDeletable($tenant),
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
     * Permanently delete a company that is still empty: at most one staff record
     * (archived included), which is what a fresh signup leaves. Anything bigger is a
     * live company and stays, so this can only clean up test or mistaken signups.
     *
     * The database cascades every tenant-owned row, the company's own audit log
     * included, so the record of the delete goes to the application log instead.
     * Logins that belonged to no other company go too; super admins never do.
     *
     * The signup link this company used goes with it: that foreign key only nulls
     * itself on delete, it does not cascade, so it is removed explicitly here rather
     * than staying listed as a used link with no company to show for it.
     */
    public function destroy(Request $request, Tenant $tenant): RedirectResponse
    {
        $typed = (string) $request->input('confirm_name');

        if (! self::isDeletable($tenant)) {
            return back()->withErrors(['confirm_name' => 'Only a company with no staff yet can be deleted.']);
        }
        if ($typed !== $tenant->name) {
            return back()->withErrors(['confirm_name' => 'Type the company name exactly to confirm.']);
        }

        $name = $tenant->name;
        $removed = DB::transaction(function () use ($tenant): int {
            $loneUserIds = $tenant->users()
                ->where('is_super_admin', false)
                ->whereDoesntHave('tenants', fn ($q) => $q->where('tenants.id', '!=', $tenant->id))
                ->pluck('users.id');

            CompanyInvite::where('used_by_tenant_id', $tenant->id)->delete();

            $tenant->delete();

            return User::whereIn('id', $loneUserIds)->delete();
        });

        Log::warning('Company deleted by super admin', [
            'company' => $name,
            'slug' => $tenant->slug,
            'tenant_id' => $tenant->id,
            'by' => $request->user()->email,
            'logins_removed' => $removed,
        ]);

        return redirect()->route('superadmin.companies.index')->with('ok', $name.' was deleted.');
    }

    private static function isDeletable(Tenant $tenant): bool
    {
        return Employee::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count() <= 1;
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
                'initials' => CompanyProvisioner::initials($user->name),
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
}
