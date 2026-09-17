<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\CompanyCategory;
use App\Models\Department;
use App\Models\Employee;
use App\Models\PayrollItem;
use App\Models\Tenant;
use App\Models\TimesheetCategory;
use App\Models\User;
use App\Support\EasterEggBank;
use App\Support\GreetingBank;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Creates a company as one atomic act: tenant, feature package, seed catalogues,
 * first branch and department, first HR admin (new or existing user) with an active
 * employee row, and the audit line. Both the super-admin console and the invite-link
 * signup call this, so the two paths can never drift apart.
 *
 * Sends nothing. Callers decide whether an email goes out.
 */
final class CompanyProvisioner
{
    /**
     * @param  array<string, mixed>  $company  Tenant columns; 'name' is required, the rest optional
     * @param  array{branch_name: string, branch_state?: ?string, department_name: string}  $structure
     * @param  User|array{name: string, email: string, password: string, password_change_required: bool, email_verified_at?: ?\DateTimeInterface}  $admin  An existing user to attach as hr, or the fields for a new one (plain password, hashed here)
     */
    public function provision(array $company, CompanyCategory $category, array $structure, User|array $admin, string $auditAction): Tenant
    {
        return DB::transaction(function () use ($company, $category, $structure, $admin, $auditAction): Tenant {
            $tenant = Tenant::create([
                'slug' => $this->uniqueSlug($company['name']),
                'name' => $company['name'],
                'registration_number' => $company['registration_number'] ?? null,
                'company_code' => $company['company_code'] ?? null,
                'industry' => $company['industry'] ?? null,
                'address' => $company['address'] ?? null,
                'contact_number' => $company['contact_number'] ?? null,
                'email' => $company['email'] ?? null,
                'website' => $company['website'] ?? null,
                'initials' => self::initials($company['name']),
                'color' => $company['color'] ?? config('amanahku.brand_color'),
                'secondary_color' => $company['secondary_color'] ?? null,
                'welcome_message' => $company['welcome_message'] ?? null,
                'plan' => $company['plan'] ?? 'Starter',
                'company_category_id' => $category->id,
                'meta' => '1 branch · 1 employee',
                'status' => 'active',
                // New companies enforce the onboarding gates (launch lock + staff
                // profile completion) from day one.
                'onboarding_enforced' => true,
                'subscription_start' => $company['subscription_start'] ?? null,
                'subscription_end' => $company['subscription_end'] ?? null,
            ]);

            // Seed the feature entitlement from the chosen category package. From here
            // the resolved entitlement — not the category — is the source of truth.
            app(FeatureManager::class)->applyCategoryPackage($tenant, $category->level);

            // Every tenant needs the statutory pay-item catalogue, the timesheet effort
            // types, and the greeting / easter-egg banks from day one; no deploy step is
            // guaranteed to seed a company created after that deploy.
            PayrollItem::seedFor($tenant);
            TimesheetCategory::seedFor($tenant);
            GreetingBank::seed($tenant->id);
            EasterEggBank::seed($tenant->id);

            $branch = Branch::create([
                'tenant_id' => $tenant->id,
                'name' => $structure['branch_name'],
                'state' => $structure['branch_state'] ?? null,
            ]);

            $department = Department::create([
                'tenant_id' => $tenant->id,
                'name' => $structure['department_name'],
            ]);

            $user = $admin instanceof User ? $admin : $this->createAdmin($admin);
            // The seed account is a full HR admin so the new company is immediately operable.
            $user->tenants()->attach($tenant->id, ['role' => 'hr']);

            Employee::create([
                'tenant_id' => $tenant->id,
                'user_id' => $user->id,
                'department_id' => $department->id,
                'branch_id' => $branch->id,
                'name' => $user->name,
                'email' => $user->email,
                'position' => 'HR Admin',
                'status' => 'active',
                'workload' => 'green',
                'workload_label' => 'Healthy',
                'initials' => self::initials($user->name),
                'avatar_color' => config('amanahku.brand_color'),
                'joined_at' => now()->toDateString(),
            ]);

            // No active tenant in either caller's context, so the BelongsToTenant
            // auto-fill is a no-op — stamp the new tenant explicitly. A self-signup has
            // nobody logged in yet, so the new admin is the actor.
            AuditLog::create([
                'tenant_id' => $tenant->id,
                'user_id' => auth()->id() ?? $user->id,
                'actor_name' => auth()->check() ? auth()->user()->name : $user->name,
                'action' => $auditAction,
                'target' => $tenant->name.' · admin '.$user->email,
            ]);

            return $tenant;
        });
    }

    /**
     * @param  array{name: string, email: string, password: string, password_change_required: bool, email_verified_at?: ?\DateTimeInterface}  $fields
     */
    private function createAdmin(array $fields): User
    {
        $user = User::create([
            'name' => $fields['name'],
            'email' => $fields['email'],
            'password' => Hash::make($fields['password']),
        ]);

        // Neither column is in $fillable, so set them explicitly (I-008 for the flag).
        $user->forceFill([
            'password_change_required' => $fields['password_change_required'],
            'email_verified_at' => $fields['email_verified_at'] ?? null,
        ])->save();

        return $user;
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

    /** Up-to-two-letter monogram: first letter of the first and last word. */
    public static function initials(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $first = mb_substr($parts[0] ?? '', 0, 1);
        $last = count($parts) > 1 ? mb_substr((string) end($parts), 0, 1) : '';

        return mb_strtoupper($first.$last) ?: 'NA';
    }
}
