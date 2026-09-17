# Invite-link company signup — implementation plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A superadmin generates a one-use, 7-day signup link. The person who opens it creates their own company and HR admin login in one form, lands in Launch Center, and never needs a developer. Superadmin's existing "New company" form keeps working exactly as before, now through the same provisioning code.

**Architecture:**
- `company_invites` table + `App\Models\CompanyInvite` (token, note, category, expiry, used-by).
- `App\Services\CompanyProvisioner::provision()` owns the whole "tenant + seeds + branch + department + HR user + employee + audit row" transaction. `SuperAdmin\CompanyController::store` and the new `CompanySignupController::store` both call it, so the two paths cannot drift.
- Fortify's `Features::registration()` is switched off in `config/fortify.php`; our own `/register` routes (names kept as `register` and `register.store` because `BlockRegistrationWhenDisabled` keys on those) are handled by `App\Http\Controllers\CompanySignupController`. Plain "create an account with no company" registration is gone, per spec.
- Superadmin: `App\Http\Controllers\SuperAdmin\CompanyInviteController` (store, destroy) + a "Signup links" card at the bottom of the existing companies index.

**Tech Stack:** Laravel 13, PHP 8.5, PHPUnit 12 (sqlite in-memory, `RefreshDatabase`), Fortify v1, Blade with inline styles, Alpine 3 available on every page via `resources/js/app.js`.

**Spec:** `docs/superpowers/specs/2026-09-15-self-serve-company-signup-design.md` — Change 1 only, plus the "Signup" block under "Edge cases and decisions". Mockup screens 1 and 2 in `docs/superpowers/mockups/2026-09-15-self-serve-signup/index.html`.

**Global constraints:**
- Do NOT set `tenants.work_days` or `tenants.tot_saturday` anywhere in this plan; those columns come from a separate plan and the provisioner must not reference them.
- Never edit `CLAUDE.md`, `docs/build/RULES.md`, `docs/build/contracts/*`, `tests/Acceptance/*`.
- Run `vendor/bin/pint --dirty --format agent` before every commit.
- Work on `dev`. Commit per task with the exact messages below.
- Tests run on the host: `php artisan test --compact ...`. The dev DB is migrated separately with `lerd artisan migrate` (Task 1 step).
- Blade changed in Tasks 3, 4, 5 → final task rebuilds assets (`lerd artisan view:clear && lerd artisan view:cache && bun run build`) and commits `public/build`.
- Route names `register` and `register.store` MUST survive; `app/Http/Middleware/BlockRegistrationWhenDisabled.php:20` checks `$request->routeIs('register', 'register.store')`.

Real code cited below:
- `app/Http/Controllers/SuperAdmin/CompanyController.php` — `index()` lines 41–54, `store()` lines 115–237 (transaction body 129–229), `assignMember()` 378–422 (uses `$this->initials` at 407), `uniqueSlug()` 425–436, `initials()` 438–445.
- `routes/web.php` — public routes end at line 141 (`docs.api`), superadmin group lines 183–195, import alias `SuperCompanyController` at line 93.
- `app/Providers/FortifyServiceProvider.php:42` — `Fortify::registerView(...)`; `config/fortify.php:165` — `Features::registration()`.
- `resources/views/auth/login.blade.php:292` — the "Create an account" link that must go (nothing public links to signup).
- `resources/views/superadmin/companies/index.blade.php` — table card lines 62–100, back link 102–104.
- `app/Http/Controllers/AppController.php:121-124` sets `session(['current_tenant' => ..., 'persona' => ...])`; Launch Center is `route('app.screen', 'setup')` (`routes/web.php:802`).
- `tests/Feature/SuperAdminCompanyTest.php` — conventions: `superAdmin()` helper (lines 26–32), `validPayload()` (43–56), `Tenant::create([...])` inline, no tenant factory exists. `tests/Feature/RegistrationFlowTest.php:20-45` and `tests/Feature/FeatureEnforcementTest.php:250-276` hit `/register` and change in Task 4.
- Latest migration name: `database/migrations/2026_09_29_100200_create_employee_work_site_table.php` (project dates migrations ahead of today; new one sorts after it).
- `company_categories` rows (levels 1, 2, 3) are inserted by the migration `2026_06_27_000001_create_company_categories_table.php`, so they exist in every test.

---

### Task 1: `company_invites` table, model, factory

**Files:**
- Create: `database/migrations/2026_09_30_100000_create_company_invites_table.php`
- Create: `app/Models/CompanyInvite.php`
- Create: `database/factories/CompanyInviteFactory.php`
- Test: `tests/Feature/CompanyInviteModelTest.php`

**Interfaces:**
- Produces `App\Models\CompanyInvite`:
  - `scopeForToken(Builder $query, string $token): Builder`
  - `isUsable(): bool` — not used and not expired
  - `status(): string` — `'pending' | 'used' | 'expired'`
  - `url(): string` — `route('register', ['invite' => $this->token])` (route exists from Task 4; the model test does not call it)
  - relations `category(): BelongsTo<CompanyCategory>`, `usedByTenant(): BelongsTo<Tenant>`, `creator(): BelongsTo<User>`
- Produces `Database\Factories\CompanyInviteFactory` with states `expired()`, `usedBy(Tenant $tenant)`.

**Steps:**

- [ ] Write the failing test `tests/Feature/CompanyInviteModelTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CompanyInvite;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The invite row itself: one token, one company, seven days.
 */
class CompanyInviteModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_factory_makes_a_pending_usable_invite_with_a_40_char_token(): void
    {
        $invite = CompanyInvite::factory()->create();

        $this->assertSame(40, strlen($invite->token));
        $this->assertTrue($invite->isUsable());
        $this->assertSame('pending', $invite->status());
        $this->assertSame(3, $invite->category->level);
        $this->assertNotNull($invite->creator);
    }

    public function test_expired_invite_is_not_usable(): void
    {
        $invite = CompanyInvite::factory()->expired()->create();

        $this->assertFalse($invite->isUsable());
        $this->assertSame('expired', $invite->status());
    }

    public function test_used_invite_is_not_usable_and_points_at_the_company(): void
    {
        $tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        $invite = CompanyInvite::factory()->usedBy($tenant)->create();

        $this->assertFalse($invite->isUsable());
        $this->assertSame('used', $invite->status());
        $this->assertTrue($invite->usedByTenant->is($tenant));
    }

    public function test_for_token_scope_finds_exactly_that_invite(): void
    {
        $a = CompanyInvite::factory()->create();
        CompanyInvite::factory()->create();

        $this->assertTrue(CompanyInvite::forToken($a->token)->first()->is($a));
        $this->assertNull(CompanyInvite::forToken('nope')->first());
    }
}
```

- [ ] Run it and confirm it fails (table/model missing):

```
php artisan test --compact tests/Feature/CompanyInviteModelTest.php
```

- [ ] Create the migration `database/migrations/2026_09_30_100000_create_company_invites_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_invites', function (Blueprint $table) {
            $table->id();
            $table->string('token', 40)->unique();
            $table->string('note', 160)->nullable();
            $table->foreignId('company_category_id')->constrained('company_categories')->restrictOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->foreignId('used_by_tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
            $table->foreignId('created_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_invites');
    }
};
```

- [ ] Create `app/Models/CompanyInvite.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\CompanyInviteFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A one-use, seven-day signup link a super-admin hands to the person in charge of a
 * new company. Platform-level (not tenant-scoped): it exists before the tenant does.
 * The token is the only credential — 40 random characters, never listed publicly.
 */
class CompanyInvite extends Model
{
    /** @use HasFactory<CompanyInviteFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }

    /** @param  Builder<CompanyInvite>  $query */
    public function scopeForToken(Builder $query, string $token): Builder
    {
        return $query->where('token', $token);
    }

    /** Unused and not yet expired. */
    public function isUsable(): bool
    {
        return $this->used_at === null && $this->expires_at->isFuture();
    }

    /** 'pending' | 'used' | 'expired' — what the super-admin list shows. */
    public function status(): string
    {
        if ($this->used_at !== null) {
            return 'used';
        }

        return $this->expires_at->isFuture() ? 'pending' : 'expired';
    }

    /** The public signup URL for this invite. */
    public function url(): string
    {
        return route('register', ['invite' => $this->token]);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(CompanyCategory::class, 'company_category_id');
    }

    public function usedByTenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'used_by_tenant_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
```

- [ ] Create `database/factories/CompanyInviteFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\CompanyCategory;
use App\Models\CompanyInvite;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<CompanyInvite>
 */
class CompanyInviteFactory extends Factory
{
    protected $model = CompanyInvite::class;

    /**
     * Pending, Stage 3 (the spec default), seven days out, created by a fresh user.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'token' => Str::random(40),
            'note' => fake()->company(),
            'company_category_id' => CompanyCategory::where('level', 3)->value('id'),
            'expires_at' => now()->addDays(7),
            'used_at' => null,
            'used_by_tenant_id' => null,
            'created_by_user_id' => User::factory(),
        ];
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'expires_at' => now()->subDay(),
        ]);
    }

    public function usedBy(Tenant $tenant): static
    {
        return $this->state(fn (array $attributes) => [
            'used_at' => now()->subHour(),
            'used_by_tenant_id' => $tenant->id,
        ]);
    }
}
```

- [ ] Run the test, expect green:

```
php artisan test --compact tests/Feature/CompanyInviteModelTest.php
```

- [ ] Migrate the dev DB so the app does not 500 later:

```
lerd artisan migrate
```

- [ ] Format and commit:

```
vendor/bin/pint --dirty --format agent
git add database/migrations/2026_09_30_100000_create_company_invites_table.php app/Models/CompanyInvite.php database/factories/CompanyInviteFactory.php tests/Feature/CompanyInviteModelTest.php
git commit -m "feat(signup): company_invites table, model and factory for invite-link signup"
```

---

### Task 2: extract `CompanyProvisioner`, refactor superadmin `store` onto it

**Files:**
- Create: `app/Services/CompanyProvisioner.php`
- Modify: `app/Http/Controllers/SuperAdmin/CompanyController.php` — replace `store()` body (lines 115–237), replace `$this->initials(...)` at line 407 with `CompanyProvisioner::initials(...)`, delete `uniqueSlug()` and `initials()` (lines 424–445), drop now-unused imports.
- Test: `tests/Feature/SuperAdminCompanyTest.php` (add one audit assertion; the rest must stay green unchanged)

**Interfaces:**
- Produces `App\Services\CompanyProvisioner`:
  ```php
  /**
   * @param  array<string, mixed>  $company   Tenant columns; 'name' required, everything else optional
   * @param  array{branch_name: string, branch_state?: ?string, department_name: string}  $structure
   * @param  User|array{name: string, email: string, password: string, password_change_required: bool, email_verified_at?: ?\DateTimeInterface}  $admin
   */
  public function provision(array $company, CompanyCategory $category, array $structure, User|array $admin, string $auditAction): Tenant;
  public static function initials(string $name): string;
  ```
  Behaviour: one `DB::transaction`; creates the tenant (unique slug, initials, brand colour default, `plan` defaults to `'Starter'`, `status` active, `onboarding_enforced` true, `meta` `'1 branch · 1 employee'`), applies the category feature package, seeds `PayrollItem`, `TimesheetCategory`, `GreetingBank`, `EasterEggBank`, creates the branch and department, creates the user (plain password is hashed here) or attaches the existing one as `hr`, creates the active Employee with position `HR Admin`, writes the audit row with `$auditAction`. Does **not** send any notification and does **not** touch `work_days` / `tot_saturday`.
- Consumes: `FeatureManager::applyCategoryPackage(Tenant, int)`, `PayrollItem::seedFor(Tenant)`, `TimesheetCategory::seedFor(Tenant)`, `GreetingBank::seed(int)`, `EasterEggBank::seed(int)`.

**Steps:**

- [ ] Add a failing test to `tests/Feature/SuperAdminCompanyTest.php` (append after `test_slug_collision_gets_a_unique_suffix`, before the closing brace of the class). It pins the audit row and the "no data_scope override" behaviour the refactor must preserve:

```php
    public function test_provisioning_writes_the_audit_row_against_the_new_tenant(): void
    {
        $this->actingAs($this->superAdmin())->post('/admin/companies', $this->validPayload());

        $tenant = Tenant::where('name', 'Beta Industries')->firstOrFail();

        $this->assertDatabaseHas('audit_logs', [
            'tenant_id' => $tenant->id,
            'action' => 'Provisioned company',
            'target' => 'Beta Industries · admin siti@beta.com',
        ]);
        $this->assertDatabaseHas('branches', ['tenant_id' => $tenant->id, 'state' => 'Selangor']);
        $this->assertDatabaseHas('employees', ['tenant_id' => $tenant->id, 'position' => 'HR Admin', 'status' => 'active']);
        $this->assertTrue($tenant->onboarding_enforced);
    }
```

- [ ] Run the file; the new test passes already against the current controller (that is the point: it is a regression pin). Confirm the whole file is green before refactoring:

```
php artisan test --compact tests/Feature/SuperAdminCompanyTest.php
```

- [ ] Create `app/Services/CompanyProvisioner.php`:

```php
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
                'actor_name' => auth()->user()?->name ?? $user->name,
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
```

- [ ] In `app/Http/Controllers/SuperAdmin/CompanyController.php`, replace the whole `store()` method (lines 114–237, docblock included) with:

```php
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
```

- [ ] In the same file, change line 407 inside `assignMember()` from `'initials' => $this->initials($user->name),` to `'initials' => CompanyProvisioner::initials($user->name),`, then delete the private `uniqueSlug()` and `initials()` methods (lines 424–445 in the original file, the last two methods before the closing brace).

- [ ] Replace the import block at the top of `CompanyController.php` (lines 7–28) with the one below. Gone: `Branch`, `Department`, `PayrollItem`, `TimesheetCategory`, `EasterEggBank`, `GreetingBank`, `Hash` (only `store()` used them). Added: `CompanyProvisioner`, `Arr`. Kept: `AuditLog`, `Employee`, `FeatureManager`, `Permissions`, `DB`, `Str`, `Rule` (still used by `update()`, `updateCategory()`, `setStatus()`, `assignMember()`, the job-health helpers).

```php
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\CompanyCategory;
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
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View as ViewContract;
```

  (`DB` stays: `stuckJobCount()` and `failedJobSummary()` use it. `Str` stays: `Str::password`, `Str::slug` in `update()`.)

- [ ] Run the superadmin file plus the banner test (both render the index) and expect all green:

```
php artisan test --compact tests/Feature/SuperAdminCompanyTest.php tests/Feature/SuperAdminFailedJobsBannerTest.php
```

- [ ] Run phpstan on the touched files to catch a stale import:

```
vendor/bin/phpstan analyse app/Services/CompanyProvisioner.php app/Http/Controllers/SuperAdmin/CompanyController.php --no-progress
```

- [ ] Format and commit:

```
vendor/bin/pint --dirty --format agent
git add app/Services/CompanyProvisioner.php app/Http/Controllers/SuperAdmin/CompanyController.php tests/Feature/SuperAdminCompanyTest.php
git commit -m "refactor(superadmin): move company provisioning into CompanyProvisioner so signup can share it"
```

---

### Task 3: superadmin generates, lists, revokes signup links

**Files:**
- Create: `app/Http/Controllers/SuperAdmin/CompanyInviteController.php`
- Modify: `routes/web.php` — add import after line 93, add two routes inside the superadmin group (after line 195, the `companies.features.update` line)
- Modify: `app/Http/Controllers/SuperAdmin/CompanyController.php` `index()` (lines 41–54) — pass `invites` and `categories`
- Modify: `resources/views/superadmin/companies/index.blade.php` — insert the "Signup links" card between the table card (ends line 100) and the back link (line 102)
- Test: `tests/Feature/SuperAdminCompanyInviteTest.php`

**Interfaces:**
- Produces routes `POST /admin/invites` → `superadmin.invites.store` (body: `note` nullable string max 160, `company_category_id` required exists) and `POST /admin/invites/{invite}/delete` → `superadmin.invites.destroy` (route-model bound `CompanyInvite`).
- `store` flashes `inviteUrl` (string) and `inviteNote` (?string) to the index; `destroy` flashes `ok`. `destroy` on a used row → 403 and the row stays.
- Consumes `CompanyInvite::url()`, `CompanyInvite::status()` from Task 1. `url()` needs the `register` route, which still belongs to Fortify until Task 4 — it already resolves to `/register?invite=...`, so this task works on its own.

**Steps:**

- [ ] Write the failing test `tests/Feature/SuperAdminCompanyInviteTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CompanyCategory;
use App\Models\CompanyInvite;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Super-admin side of invite-link signup: generate, list, revoke. Non-super-admins
 * never see any of it.
 */
class SuperAdminCompanyInviteTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): User
    {
        $u = User::create(['name' => 'Platform', 'email' => 'super@example.com', 'password' => Hash::make('<redacted>')]);
        $u->forceFill(['is_super_admin' => true])->save();

        return $u;
    }

    private function ordinaryUser(): User
    {
        $u = User::create(['name' => 'Joe', 'email' => 'joe@example.com', 'password' => Hash::make('<redacted>')]);
        $tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        $u->tenants()->attach($tenant->id, ['role' => 'hr']);

        return $u;
    }

    public function test_super_admin_generates_a_link_and_sees_it_flashed(): void
    {
        $admin = $this->superAdmin();
        $stage2 = CompanyCategory::where('level', 2)->firstOrFail();

        $response = $this->actingAs($admin)->post('/admin/invites', [
            'note' => 'Encik Faizal, Maju Bina Sdn Bhd',
            'company_category_id' => $stage2->id,
        ]);

        $response->assertRedirect(route('superadmin.companies.index'));

        $invite = CompanyInvite::firstOrFail();
        $this->assertSame(40, strlen($invite->token));
        $this->assertSame('Encik Faizal, Maju Bina Sdn Bhd', $invite->note);
        $this->assertSame($stage2->id, $invite->company_category_id);
        $this->assertSame($admin->id, $invite->created_by_user_id);
        $this->assertNull($invite->used_at);
        $this->assertTrue($invite->expires_at->between(now()->addDays(7)->subMinute(), now()->addDays(7)->addMinute()));

        $response->assertSessionHas('inviteUrl', $invite->url());
        $this->assertStringContainsString('/register?invite='.$invite->token, $invite->url());
    }

    public function test_generate_requires_a_real_category(): void
    {
        $this->actingAs($this->superAdmin())
            ->post('/admin/invites', ['note' => 'x', 'company_category_id' => 999])
            ->assertSessionHasErrors('company_category_id');

        $this->assertSame(0, CompanyInvite::count());
    }

    public function test_index_lists_pending_used_and_expired_invites(): void
    {
        $tenant = Tenant::create(['slug' => 'zr-catering', 'name' => 'ZR Catering', 'initials' => 'ZC']);
        $pending = CompanyInvite::factory()->create(['note' => 'Pending person']);
        CompanyInvite::factory()->usedBy($tenant)->create(['note' => 'Used person']);
        CompanyInvite::factory()->expired()->create(['note' => 'Expired person']);

        $response = $this->actingAs($this->superAdmin())->get('/admin/companies');

        $response->assertOk()
            ->assertSee('Signup links')
            ->assertSee('Pending person')
            ->assertSee('Used person')
            ->assertSee('Expired person')
            ->assertSee('Used · ZR Catering')
            ->assertSee('Expired')
            ->assertSee(route('superadmin.companies.show', $tenant))
            ->assertSee($pending->token);
    }

    public function test_super_admin_revokes_a_pending_invite(): void
    {
        $invite = CompanyInvite::factory()->create();

        $this->actingAs($this->superAdmin())
            ->post("/admin/invites/{$invite->id}/delete")
            ->assertRedirect(route('superadmin.companies.index'));

        $this->assertDatabaseMissing('company_invites', ['id' => $invite->id]);
    }

    public function test_super_admin_can_remove_an_expired_invite(): void
    {
        $invite = CompanyInvite::factory()->expired()->create();

        $this->actingAs($this->superAdmin())->post("/admin/invites/{$invite->id}/delete");

        $this->assertDatabaseMissing('company_invites', ['id' => $invite->id]);
    }

    public function test_used_invite_cannot_be_revoked(): void
    {
        $tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        $invite = CompanyInvite::factory()->usedBy($tenant)->create();

        $this->actingAs($this->superAdmin())
            ->post("/admin/invites/{$invite->id}/delete")
            ->assertForbidden();

        $this->assertDatabaseHas('company_invites', ['id' => $invite->id]);
    }

    public function test_ordinary_user_gets_403_on_every_invite_route(): void
    {
        $user = $this->ordinaryUser();
        $invite = CompanyInvite::factory()->create();

        $this->actingAs($user)->post('/admin/invites', [
            'company_category_id' => CompanyCategory::where('level', 3)->value('id'),
        ])->assertForbidden();
        $this->actingAs($user)->post("/admin/invites/{$invite->id}/delete")->assertForbidden();

        $this->assertSame(1, CompanyInvite::count());
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->post('/admin/invites', ['company_category_id' => 1])->assertRedirect('/login');
    }
}
```

- [ ] Run it and confirm it fails (404 on the routes):

```
php artisan test --compact tests/Feature/SuperAdminCompanyInviteTest.php
```

- [ ] Create `app/Http/Controllers/SuperAdmin/CompanyInviteController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\CompanyInvite;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Signup links for new companies. A super-admin mints one, sends it however they like,
 * and the person in charge creates the company themselves (CompanySignupController).
 * Reachable only behind the super.admin guard.
 */
class CompanyInviteController extends Controller
{
    /** Mint a one-use, seven-day link and flash it so it can be copied once. */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'note' => ['nullable', 'string', 'max:160'],
            'company_category_id' => ['required', 'exists:company_categories,id'],
        ]);

        $invite = CompanyInvite::create([
            'token' => Str::random(40),
            'note' => $data['note'] ?? null,
            'company_category_id' => $data['company_category_id'],
            'expires_at' => now()->addDays(7),
            'created_by_user_id' => $request->user()->id,
        ]);

        return redirect()->route('superadmin.companies.index')
            ->with('inviteUrl', $invite->url())
            ->with('inviteNote', $invite->note);
    }

    /** Revoke a link that has not been used. Used rows stay: they point at the company. */
    public function destroy(CompanyInvite $invite): RedirectResponse
    {
        abort_if($invite->used_at !== null, 403, 'A used link cannot be revoked.');

        $invite->delete();

        return redirect()->route('superadmin.companies.index')->with('ok', 'Signup link removed.');
    }
}
```

- [ ] In `routes/web.php`, add the import right after line 93 (`use App\Http\Controllers\SuperAdmin\CompanyController as SuperCompanyController;`):

```php
use App\Http\Controllers\SuperAdmin\CompanyInviteController as SuperCompanyInviteController;
```

  and inside the superadmin group, directly after the `companies.features.update` line (line 195):

```php
        // Signup links: one-use, seven-day invites that let the person in charge of a
        // new company provision it themselves (see CompanySignupController).
        Route::post('/invites', [SuperCompanyInviteController::class, 'store'])->name('invites.store');
        Route::post('/invites/{invite}/delete', [SuperCompanyInviteController::class, 'destroy'])->name('invites.destroy');
```

- [ ] In `CompanyController::index()` (lines 41–54), pass the list and the category picker. Replace the method with:

```php
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
            // Stage 3 first: it is the spec default for self-serve companies.
            'categories' => CompanyCategory::orderByDesc('level')->get(),
        ]);
    }
```

  and add `use App\Models\CompanyInvite;` to the imports (alphabetically after `CompanyCategory`).

- [ ] In `resources/views/superadmin/companies/index.blade.php`, insert this block between the closing `</div>` of the table card (line 100) and the `<div style="margin-top:24px;">` back link (line 102):

```blade
        {{-- Signup links: a super-admin mints one, sends it by WhatsApp or email, and the
             person in charge provisions the company themselves. One link, one company,
             seven days. The fresh link is shown once, right after generating. --}}
        <div style="background:var(--surface,#fff);border:1px solid var(--hairline,#e6e6ec);border-radius:14px;padding:24px;margin-top:24px;">
            <h2 style="font-weight:500;font-size:18px;letter-spacing:-0.3px;color:var(--ink);margin:0 0 4px;">Signup links</h2>
            <p style="font-size:13px;color:var(--muted);margin:0 0 18px;line-height:1.55;">Send a link to the person in charge of a new company. They create the company and their own HR admin login themselves. One link, one company, valid 7 days.</p>

            <form method="POST" action="{{ route('superadmin.invites.store') }}" style="display:grid;grid-template-columns:1fr 220px auto;gap:12px;align-items:end;padding:16px;background:var(--canvas);border-radius:10px;margin-bottom:20px;">
                @csrf
                <div>
                    <label style="display:block;font-size:12px;font-weight:600;color:var(--ink);margin-bottom:6px;">For (note to self)</label>
                    <input name="note" value="{{ old('note') }}" maxlength="160" placeholder="e.g. Encik Faizal, Maju Bina Sdn Bhd" style="width:100%;height:44px;padding:0 13px;border:1px solid var(--hairline,#e6e6ec);border-radius:10px;font-size:14px;background:#fff;color:var(--ink);font-family:inherit;">
                    @error('note')<div style="color:var(--red);font-size:12.5px;margin-top:5px;">{{ $message }}</div>@enderror
                </div>
                <div>
                    <label style="display:block;font-size:12px;font-weight:600;color:var(--ink);margin-bottom:6px;">Starting package</label>
                    <select name="company_category_id" required style="width:100%;height:44px;padding:0 13px;border:1px solid var(--hairline,#e6e6ec);border-radius:10px;font-size:14px;background:#fff;color:var(--ink);font-family:inherit;">
                        @foreach ($categories as $cat)
                            <option value="{{ $cat->id }}" @selected((int) old('company_category_id', $categories->first()?->id) === $cat->id)>{{ $cat->name }}</option>
                        @endforeach
                    </select>
                    @error('company_category_id')<div style="color:var(--red);font-size:12.5px;margin-top:5px;">{{ $message }}</div>@enderror
                </div>
                <button type="submit" class="uj-btn" style="height:44px;padding:0 18px;border:none;border-radius:10px;font-size:14px;font-weight:600;background:var(--red);color:#fff;cursor:pointer;">Generate link</button>
            </form>

            @if (session('inviteUrl'))
                <div style="background:#eaf6f1;border:1px solid #bfe3d3;color:#0f5132;border-radius:10px;padding:12px 14px;margin-bottom:18px;font-size:13px;display:flex;align-items:center;gap:12px;">
                    <span style="flex:1;min-width:0;">Link ready@if (session('inviteNote')) for <strong>{{ session('inviteNote') }}</strong>@endif:<br><span style="font-family:var(--font-mono,monospace);color:var(--ink);word-break:break-all;">{{ session('inviteUrl') }}</span></span>
                    <button type="button" onclick='navigator.clipboard.writeText(@js(session('inviteUrl')));this.textContent="Copied"'  style="flex-shrink:0;font-size:12.5px;font-weight:600;color:var(--ink);background:#fff;border:1px solid var(--hairline);cursor:pointer;padding:7px 12px;border-radius:8px;">Copy link</button>
                </div>
            @endif

            <table style="width:100%;border-collapse:collapse;font-size:13.5px;">
                <thead>
                    <tr style="background:var(--hairline-soft);">
                        <th style="text-align:left;padding:10px 12px;font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);font-weight:600;">For</th>
                        <th style="text-align:left;padding:10px 12px;font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);font-weight:600;">Package</th>
                        <th style="text-align:left;padding:10px 12px;font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);font-weight:600;">Created</th>
                        <th style="text-align:left;padding:10px 12px;font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);font-weight:600;">Expires</th>
                        <th style="text-align:left;padding:10px 12px;font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);font-weight:600;">Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($invites as $invite)
                        @php $status = $invite->status(); @endphp
                        <tr style="border-top:1px solid var(--hairline,#e6e6ec);">
                            <td style="padding:12px;color:var(--ink);">{{ $invite->note ?: '—' }}</td>
                            <td style="padding:12px;color:var(--muted);">Stage {{ $invite->category->level }}</td>
                            <td style="padding:12px;color:var(--muted);">{{ $invite->created_at->format('j M Y') }}</td>
                            <td style="padding:12px;color:var(--muted);">{{ $invite->expires_at->format('j M Y') }}</td>
                            <td style="padding:12px;">
                                @if ($status === 'pending')
                                    <span style="font-size:10.5px;font-weight:600;text-transform:uppercase;letter-spacing:.4px;color:#7a4f10;background:#fdf3e3;border:1px solid #f0d9a8;padding:2px 8px;border-radius:9999px;">Pending</span>
                                @elseif ($status === 'used')
                                    <span style="font-size:10.5px;font-weight:600;text-transform:uppercase;letter-spacing:.4px;color:#0f5132;background:#eaf6f1;border:1px solid #bfe3d3;padding:2px 8px;border-radius:9999px;">Used · {{ $invite->usedByTenant?->name ?? 'deleted company' }}</span>
                                @else
                                    <span style="font-size:10.5px;font-weight:600;text-transform:uppercase;letter-spacing:.4px;color:var(--muted);background:var(--hairline-soft);border:1px solid var(--hairline);padding:2px 8px;border-radius:9999px;">Expired</span>
                                @endif
                            </td>
                            <td style="padding:12px;text-align:right;white-space:nowrap;">
                                @if ($status === 'pending')
                                    <button type="button" onclick='navigator.clipboard.writeText(@js($invite->url()));this.textContent="Copied"'  style="font-size:12.5px;font-weight:600;color:var(--ink);background:#fff;border:1px solid var(--hairline);cursor:pointer;padding:6px 10px;border-radius:8px;">Copy</button>
                                    <form method="POST" action="{{ route('superadmin.invites.destroy', $invite) }}" style="display:inline;" onsubmit="return confirm('Revoke this link? Anyone holding it will get a 404.')">
                                        @csrf
                                        <button type="submit" style="font-size:12.5px;font-weight:600;color:var(--red);background:#fff;border:1px solid var(--hairline);cursor:pointer;padding:6px 10px;border-radius:8px;">Revoke</button>
                                    </form>
                                @elseif ($status === 'used' && $invite->usedByTenant)
                                    <a href="{{ route('superadmin.companies.show', $invite->usedByTenant) }}" style="font-size:12.5px;color:var(--red);font-weight:500;text-decoration:none;">Open company</a>
                                @elseif ($status === 'expired')
                                    <form method="POST" action="{{ route('superadmin.invites.destroy', $invite) }}" style="display:inline;">
                                        @csrf
                                        <button type="submit" style="font-size:12.5px;font-weight:600;color:var(--red);background:#fff;border:1px solid var(--hairline);cursor:pointer;padding:6px 10px;border-radius:8px;">Remove</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" style="padding:22px;text-align:center;color:var(--muted);">No signup links yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
```

  `@js()` is JSON with `'`, `<`, `>`, `&` hex-escaped, so it is safe inside a single-quoted attribute (a double-quoted one would break on the JSON quotes). Note the pending-row "Copy" button embeds `$invite->url()`, which contains the token; the listing test asserts the token is on the page through that attribute.

- [ ] Run the new file plus the two other index-rendering files:

```
php artisan test --compact tests/Feature/SuperAdminCompanyInviteTest.php tests/Feature/SuperAdminCompanyTest.php tests/Feature/SuperAdminFailedJobsBannerTest.php
```

- [ ] Format and commit:

```
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/SuperAdmin/CompanyInviteController.php app/Http/Controllers/SuperAdmin/CompanyController.php routes/web.php resources/views/superadmin/companies/index.blade.php tests/Feature/SuperAdminCompanyInviteTest.php
git commit -m "feat(superadmin): generate, list and revoke company signup links"
```

---

### Task 4: `/register` becomes the invite-only signup page (GET)

**Files:**
- Modify: `config/fortify.php:165` — remove `Features::registration(),`
- Modify: `app/Providers/FortifyServiceProvider.php:42` — remove `Fortify::registerView(...)`
- Modify: `routes/web.php` — add controller import (alphabetically among the `App\Http\Controllers\...` imports, e.g. after `use App\Http\Controllers\ClaimController;` if present, otherwise anywhere in that sorted block) and two routes after line 141 (`docs.api`)
- Create: `app/Http/Controllers/CompanySignupController.php` (`show` now, `store` in Task 5)
- Rewrite: `resources/views/auth/register.blade.php`
- Modify: `resources/views/auth/login.blade.php:292` — delete the "Create an account" paragraph
- Modify: `tests/Feature/RegistrationFlowTest.php:20-45` and `tests/Feature/FeatureEnforcementTest.php:250-276`
- Test: `tests/Feature/CompanySignupTest.php` (GET cases now; POST cases added in Task 5)

**Interfaces:**
- Produces `GET /register?invite=TOKEN` → `register` (middleware `throttle:20,1,signup`):
  - missing / unknown token → 404 (plain abort)
  - used / expired token → the register view rendered with `$dead` message, HTTP 404
  - logged-in super-admin → the register view rendered with `$superAdmin = true`, HTTP 403, copy "You already see every company"
  - logged-in normal user → form with only company name + hidden token
  - guest → full form. `session('url.intended')` is set to the invite URL so the "Sign in" link brings them back here after Fortify login (`config/fortify.php:76` home is `/tenant`; Fortify's `LoginResponse` uses `redirect()->intended(...)`).
- Produces `POST /register` → `register.store` (middleware `throttle:5,1,signup-post`) wired to `store`, which in this task only exists as a stub that aborts 501 so the route resolves; Task 5 fills it.
- `BlockRegistrationWhenDisabled` (global web middleware) still keys on both names, so `platform.registration` off keeps redirecting to `/login` with the existing flash.

**Steps:**

- [ ] Write the failing test `tests/Feature/CompanySignupTest.php` (GET cases only for now):

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CompanyInvite;
use App\Models\Tenant;
use App\Models\User;
use App\Services\FeatureManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Invite-link company signup: /register?invite=TOKEN. The token is the only way in.
 */
class CompanySignupTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): User
    {
        $u = User::create(['name' => 'Platform', 'email' => 'super@example.com', 'password' => Hash::make('<redacted>')]);
        $u->forceFill(['is_super_admin' => true])->save();

        return $u;
    }

    private function memberOfAcme(): User
    {
        $u = User::create(['name' => 'Mei Ling', 'email' => 'mei@example.com', 'password' => Hash::make('<redacted>')]);
        $tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        $u->tenants()->attach($tenant->id, ['role' => 'employee']);

        return $u;
    }

    // ── GET /register ────────────────────────────────────────────

    public function test_valid_token_shows_the_signup_form(): void
    {
        $invite = CompanyInvite::factory()->create();

        $this->get('/register?invite='.$invite->token)
            ->assertOk()
            ->assertSee('Set up your company')
            ->assertSee('name="company_name"', false)
            ->assertSee('name="name"', false)
            ->assertSee('name="email"', false)
            ->assertSee('name="password"', false)
            ->assertSee('name="invite" value="'.$invite->token.'"', false)
            ->assertSessionHas('url.intended', url('/register?invite='.$invite->token));
    }

    public function test_missing_and_unknown_tokens_404(): void
    {
        $this->get('/register')->assertNotFound();
        $this->get('/register?invite=')->assertNotFound();
        $this->get('/register?invite='.str_repeat('x', 40))->assertNotFound();
    }

    public function test_used_token_404s_with_an_explanation(): void
    {
        $tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        $invite = CompanyInvite::factory()->usedBy($tenant)->create();

        $this->get('/register?invite='.$invite->token)
            ->assertNotFound()
            ->assertSee('This link has already been used');
    }

    public function test_expired_token_404s_with_an_explanation(): void
    {
        $invite = CompanyInvite::factory()->expired()->create();

        $this->get('/register?invite='.$invite->token)
            ->assertNotFound()
            ->assertSee('This link has expired');
    }

    public function test_registration_switch_off_blocks_the_page_but_keeps_the_invite(): void
    {
        app(FeatureManager::class)->setPlatform('platform.registration', false, false);
        $invite = CompanyInvite::factory()->create();

        $this->get('/register?invite='.$invite->token)->assertRedirect('/login');

        $this->assertDatabaseHas('company_invites', ['id' => $invite->id, 'used_at' => null]);
    }

    public function test_super_admin_is_refused(): void
    {
        $invite = CompanyInvite::factory()->create();

        $this->actingAs($this->superAdmin())
            ->get('/register?invite='.$invite->token)
            ->assertForbidden()
            ->assertSee('You already see every company');
    }

    public function test_signed_in_user_sees_only_the_company_name_field(): void
    {
        $invite = CompanyInvite::factory()->create();

        $this->actingAs($this->memberOfAcme())
            ->get('/register?invite='.$invite->token)
            ->assertOk()
            ->assertSee('name="company_name"', false)
            ->assertSee('Mei Ling')
            ->assertDontSee('name="password"', false)
            ->assertDontSee('name="email"', false);
    }
}
```

- [ ] Run it and confirm it fails (Fortify still owns `/register`, so the valid-token case renders "Create your account"):

```
php artisan test --compact tests/Feature/CompanySignupTest.php
```

- [ ] In `config/fortify.php`, delete line 165 `        Features::registration(),`. Fortify then registers no `/register` routes at all (`vendor/laravel/fortify/routes/routes.php:74`).

- [ ] In `app/Providers/FortifyServiceProvider.php`, delete line 42 `        Fortify::registerView(fn () => view('auth.register'));`. Leave `Fortify::createUsersUsing(CreateNewUser::class)` and the `register` rate limiter alone; they are harmless and `CreateNewUser` is still referenced by the provider.

- [ ] Create `app/Http/Controllers/CompanySignupController.php` with `show()` and a stub `store()`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\CompanyInvite;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View as ViewContract;

/**
 * Invite-link company signup. Replaces Fortify's registration: there is no way to
 * create an account here without a live CompanyInvite token, and the person who
 * signs up becomes the HR admin of the company they just named.
 */
class CompanySignupController extends Controller
{
    /** The signup form, or the reason the link no longer works. */
    public function show(Request $request): ViewContract|Response
    {
        $invite = $this->inviteOr404((string) $request->query('invite', ''));

        if ($request->user()?->isSuperAdmin()) {
            return response()->view('auth.register', ['invite' => $invite, 'state' => 'superadmin'], 403);
        }

        if (! $invite->isUsable()) {
            return response()->view('auth.register', ['invite' => $invite, 'state' => $invite->status()], 404);
        }

        // Someone whose email already has an account is told to sign in; bring them
        // straight back to this link afterwards (Fortify's LoginResponse honours intended).
        $request->session()->put('url.intended', $request->fullUrl());

        return view('auth.register', ['invite' => $invite, 'state' => 'form']);
    }

    /** Filled in by the next task. */
    public function store(Request $request): RedirectResponse
    {
        abort(501);
    }

    private function inviteOr404(string $token): CompanyInvite
    {
        abort_if($token === '', 404);

        $invite = CompanyInvite::forToken($token)->with('category')->first();
        abort_if($invite === null, 404);

        return $invite;
    }
}
```

- [ ] In `routes/web.php`, add the import in the sorted `App\Http\Controllers\...` block:

```php
use App\Http\Controllers\CompanySignupController;
```

  and, directly after line 141 (`Route::get('/docs/api', ...)->name('docs.api');`), add:

```php
// Invite-link company signup. Fortify's registration feature is off (config/fortify.php),
// so these two routes own /register. The names stay `register` / `register.store`
// because BlockRegistrationWhenDisabled keys on them. Not guest-only: a signed-in
// member may use a link to start a second company. GET is throttled per IP so a
// token cannot be scanned for; POST keeps Fortify's old 5/min.
Route::get('/register', [CompanySignupController::class, 'show'])
    ->middleware('throttle:20,1,signup')->name('register');
Route::post('/register', [CompanySignupController::class, 'store'])
    ->middleware('throttle:5,1,signup-post')->name('register.store');
```

- [ ] Rewrite `resources/views/auth/register.blade.php` entirely (same two-column layout as before, copy from mockup screen 2):

```blade
@php
    /** @var \App\Models\CompanyInvite $invite */
    /** @var string $state  form | superadmin | used | expired */
    $me = auth()->user();
    $field = 'width:100%;height:44px;padding:0 14px;border:1px solid var(--hairline);border-radius:8px;font-size:14px;color:var(--ink);background:#fff;margin-bottom:18px;outline:none;font-family:inherit;';
    $label = 'display:block;font-size:13px;font-weight:500;color:var(--ink);margin-bottom:6px;';
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Set up your company · Amanahku</title>
    {{-- Self-hosted Poppins + JetBrains Mono. Vite emits the @font-face rules as a
         non-entry chunk, so @vite never links them: without this line every page
         silently falls back to the system UI font. See the `fonts` block in
         vite.config.js and public/build/fonts-manifest.json. --}}
    {{ Vite::fonts() }}
    @vite(['resources/css/app.css', 'resources/js/app.js'])
@include('partials.pwa-head')
</head>
<body>
<div style="min-height:100vh;display:flex;background:var(--canvas);">
    <div style="flex:1;display:flex;align-items:center;justify-content:center;padding:48px;">
        <div style="width:100%;max-width:380px;">
            <div style="display:flex;align-items:center;gap:10px;margin-bottom:36px;">
                <div style="width:30px;height:30px;border-radius:7px;background:var(--red);display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:16px;">A</div>
                <span style="font-weight:600;font-size:18px;color:var(--ink);letter-spacing:-0.2px;">Amanah<span style="color:var(--red);">ku</span></span>
            </div>

            @if ($state === 'superadmin')
                <h1 style="font-weight:400;font-size:30px;letter-spacing:-0.6px;color:var(--ink);margin:0 0 8px;">You already see every company</h1>
                <p style="font-size:14px;color:var(--muted);margin:0 0 24px;line-height:1.6;">Signup links are for the person who will run the new company. As a super admin you create companies from the Companies page, or send this link on to them.</p>
                <a href="{{ route('superadmin.companies.index') }}" class="uj-btn-primary" style="display:inline-flex;align-items:center;height:44px;padding:0 18px;font-size:14px;text-decoration:none;">Go to Companies</a>
            @elseif ($state === 'used')
                <h1 style="font-weight:400;font-size:30px;letter-spacing:-0.6px;color:var(--ink);margin:0 0 8px;">This link has already been used</h1>
                <p style="font-size:14px;color:var(--muted);margin:0 0 24px;line-height:1.6;">Each signup link creates one company. If that company is yours, sign in. If you need a new company, ask for a fresh link.</p>
                <a href="{{ route('login') }}" class="uj-btn-primary" style="display:inline-flex;align-items:center;height:44px;padding:0 18px;font-size:14px;text-decoration:none;">Sign in</a>
            @elseif ($state === 'expired')
                <h1 style="font-weight:400;font-size:30px;letter-spacing:-0.6px;color:var(--ink);margin:0 0 8px;">This link has expired</h1>
                <p style="font-size:14px;color:var(--muted);margin:0 0 24px;line-height:1.6;">Signup links last 7 days. Ask the person who sent it for a new one.</p>
            @else
                <form action="{{ route('register.store') }}" method="post">
                    @csrf
                    {{-- The token rides in the form so a validation failure re-renders with it. --}}
                    <input type="hidden" name="invite" value="{{ $invite->token }}">

                    <span style="display:inline-block;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.4px;color:#7a4f10;background:#fdf3e3;border:1px solid #f0d9a8;padding:3px 9px;border-radius:9999px;margin-bottom:14px;">Invited · link valid until {{ $invite->expires_at->format('j M') }}</span>
                    <h1 style="font-weight:400;font-size:30px;letter-spacing:-0.6px;color:var(--ink);margin:0 0 8px;">Set up your company</h1>
                    <p style="font-size:14px;color:var(--muted);margin:0 0 28px;">You will be the HR admin. You can add branches, staff and everything else after this.</p>

                    @if ($errors->any())
                        <div style="background:var(--red-tint);border:1px solid var(--red);color:var(--red);font-size:13px;border-radius:8px;padding:10px 14px;margin-bottom:18px;line-height:1.5;">
                            {{ $errors->first() }}
                            @if ($errors->has('email'))
                                <a href="{{ route('login') }}" style="color:var(--red);font-weight:600;">Sign in</a>, then open this link again.
                            @endif
                        </div>
                    @endif

                    <label style="{{ $label }}">Company name</label>
                    <input name="company_name" type="text" value="{{ old('company_name') }}" autocomplete="organization" required autofocus style="{{ $field }}" />

                    @if ($me)
                        <p style="font-size:13px;color:var(--muted);margin:0 0 24px;line-height:1.55;">You are signed in as <strong style="color:var(--ink);">{{ $me->name }}</strong> ({{ $me->email }}). This account becomes the HR admin of the new company.</p>
                    @else
                        <label style="{{ $label }}">Your full name</label>
                        <input name="name" type="text" value="{{ old('name') }}" autocomplete="name" required style="{{ $field }}" />

                        <label style="{{ $label }}">Your email</label>
                        <input name="email" type="email" value="{{ old('email') }}" autocomplete="username" required style="{{ $field }}" />

                        <label style="{{ $label }}">Password</label>
                        <input name="password" type="password" autocomplete="new-password" required style="{{ $field }}" />

                        <label style="{{ $label }}">Confirm password</label>
                        <input name="password_confirmation" type="password" autocomplete="new-password" required style="{{ $field }}margin-bottom:24px;" />
                    @endif

                    <button type="submit" class="uj-btn-primary" style="width:100%;height:46px;font-size:14px;">Create company</button>

                    @unless ($me)
                        <p style="font-size:13px;color:var(--muted);margin-top:22px;text-align:center;">Already have an account? <a href="{{ route('login') }}" style="color:var(--red);text-decoration:none;font-weight:500;">Sign in</a></p>
                    @endunless
                </form>
            @endif
        </div>
    </div>

    <div style="flex:1;background:var(--sidebar);color:#fff;padding:64px;display:flex;flex-direction:column;justify-content:center;">
        <div style="max-width:420px;">
            <div style="font-size:11px;font-weight:600;letter-spacing:0.88px;text-transform:uppercase;color:var(--red);margin-bottom:20px;">What happens next</div>
            <h2 style="font-weight:400;font-size:30px;line-height:1.25;letter-spacing:-0.6px;color:#fff;margin:0 0 24px;">Your company, your setup.</h2>
            <ol style="font-size:15px;line-height:1.7;color:#b8b6ad;margin:0;padding-left:20px;">
                <li>Create your company and admin login (this page).</li>
                <li>Land in <strong style="color:#fff;font-weight:500;">Launch Center</strong>: pick the modules you need, set your work week, add branches and staff.</li>
                <li>Launch. Your staff sign in and start using it.</li>
            </ol>
        </div>
    </div>
</div>
</body>
</html>
```

- [ ] In `resources/views/auth/login.blade.php`, delete line 292:

```blade
        <p class="lg-foot">New to Amanahku? <a href="{{ route('register') }}">Create an account</a></p>
```

  The `@unless ($brandTenant)` block then contains only the local demo hint; leave the block in place.

- [ ] Update `tests/Feature/RegistrationFlowTest.php`: replace `test_register_page_loads` (lines 20–23) and delete `test_a_visitor_can_register_and_is_unverified` (lines 25–45), since open self-registration no longer exists per the spec ("Fortify's register view is replaced by a signup page that needs a valid token"). Also drop the now-unused imports `Illuminate\Auth\Events\Registered` and `Illuminate\Support\Facades\Event`, and update the class docblock. Resulting top of the file:

```php
<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * No-workspace landing for an account with no company, and the fact that /register
 * is no longer an open door (invite-link signup lives in CompanySignupTest).
 */
class RegistrationFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_page_without_an_invite_is_a_404(): void
    {
        $this->get('/register')->assertNotFound();
    }
```

  Keep `test_registered_user_with_no_company_sees_no_workspace_state` and `test_verified_user_with_a_company_does_not_see_no_workspace_state` as they are.

- [ ] Update `tests/Feature/FeatureEnforcementTest.php`: add `use App\Models\CompanyInvite;` to the imports, and replace `test_registration_enabled_by_default_loads` (lines 250–253) with:

```php
    public function test_registration_enabled_by_default_loads(): void
    {
        $invite = CompanyInvite::factory()->create();

        $this->get('/register?invite='.$invite->token)->assertOk();
    }
```

  `test_registration_disabled_blocks_get`, `test_registration_disabled_blocks_post` and `test_login_still_works_when_registration_disabled` stay unchanged (the middleware still fires on the same route names, before the controller).

- [ ] Run the four affected files and expect green:

```
php artisan test --compact tests/Feature/CompanySignupTest.php tests/Feature/RegistrationFlowTest.php tests/Feature/FeatureEnforcementTest.php tests/Feature/SuperAdminCompanyInviteTest.php
```

- [ ] Grep for anything else that assumed Fortify's register view, and confirm no hit needs changing (`welcome.blade.php` guards with `Route::has('register')` and the route still exists):

```
grep -rn "auth.register\|Create your account\|'register.store'\|route('register')" app resources tests
```

- [ ] Format and commit:

```
vendor/bin/pint --dirty --format agent
git add config/fortify.php app/Providers/FortifyServiceProvider.php app/Http/Controllers/CompanySignupController.php routes/web.php resources/views/auth/register.blade.php resources/views/auth/login.blade.php tests/Feature/CompanySignupTest.php tests/Feature/RegistrationFlowTest.php tests/Feature/FeatureEnforcementTest.php
git commit -m "feat(signup): /register needs a live invite token; Fortify registration off"
```

---

### Task 5: submitting the signup form provisions the company (POST)

**Files:**
- Modify: `app/Http/Controllers/CompanySignupController.php` — replace the `store()` stub
- Test: `tests/Feature/CompanySignupTest.php` — append POST cases

**Interfaces:**
- Produces `POST /register` body: `invite` (token), `company_name`; for guests also `name`, `email`, `password`, `password_confirmation`.
  - unknown token → 404
  - super-admin → 403
  - guest with an email that already has an account → validation error on `email`: "That email already has an account. Sign in first, then open this link again."
  - used/expired at submit time (including the race) → validation error on `invite`: "This link has already been used." (expired: "This link has expired.")
  - success → `CompanyProvisioner::provision([...], $invite->category, ['branch_name' => 'HQ', 'department_name' => 'General'], $userOrFields, 'Company self-registered')`, invite stamped `used_at` + `used_by_tenant_id` inside the same transaction with the row locked `FOR UPDATE`, `Auth::login`, session `current_tenant` + `persona = 'hr'`, redirect to `route('app.screen', 'setup')`.
- Consumes `CompanyProvisioner::provision()` (Task 2), `CompanyInvite` (Task 1). `provision()` opens its own `DB::transaction`; nested inside ours it becomes a savepoint, which is what we want.

**Steps:**

- [ ] Append these tests to `tests/Feature/CompanySignupTest.php` (before the closing brace). Add `use App\Models\CompanyCategory;`, `use App\Models\PayrollItem;`, `use App\Models\TimesheetCategory;`, `use Illuminate\Support\Facades\Notification;` to the imports. Remember every `CompanyInvite::factory()->create()` also creates the inviting user, so never assert `User::count()` against a fixed number; compare before/after or look the email up.

```php
    // ── POST /register ───────────────────────────────────────────

    /** @return array<string, string> */
    private function guestPayload(CompanyInvite $invite, array $overrides = []): array
    {
        return array_merge([
            'invite' => $invite->token,
            'company_name' => 'Maju Bina Sdn Bhd',
            'name' => 'Faizal bin Ahmad',
            'email' => 'faizal@majubina.com',
            'password' => '<redacted>',
            'password_confirmation' => '<redacted>',
        ], $overrides);
    }

    public function test_guest_with_a_valid_token_creates_the_company_and_lands_in_launch_center(): void
    {
        Notification::fake();
        $invite = CompanyInvite::factory()->create(['company_category_id' => CompanyCategory::where('level', 2)->value('id')]);

        $response = $this->post('/register', $this->guestPayload($invite));

        $response->assertRedirect(route('app.screen', 'setup'));

        $tenant = Tenant::where('name', 'Maju Bina Sdn Bhd')->firstOrFail();
        $this->assertSame('maju-bina-sdn-bhd', $tenant->slug);
        $this->assertSame('MB', $tenant->initials);
        $this->assertSame('active', $tenant->status);
        $this->assertTrue($tenant->onboarding_enforced);
        $this->assertSame(2, $tenant->categoryLevel());

        $this->assertDatabaseHas('branches', ['tenant_id' => $tenant->id, 'name' => 'HQ']);
        $this->assertDatabaseHas('departments', ['tenant_id' => $tenant->id, 'name' => 'General']);

        $user = User::where('email', 'faizal@majubina.com')->firstOrFail();
        $this->assertTrue(Hash::check('<redacted>', $user->password));
        $this->assertFalse($user->password_change_required);
        $this->assertNotNull($user->email_verified_at);
        $this->assertFalse($user->isSuperAdmin());
        $this->assertSame('hr', $user->roleIn($tenant));
        $this->assertDatabaseHas('employees', [
            'tenant_id' => $tenant->id, 'user_id' => $user->id, 'position' => 'HR Admin', 'status' => 'active',
        ]);

        // Same seeds as the super-admin path.
        $this->assertSame(count(PayrollItem::SYSTEM_ITEMS), PayrollItem::where('tenant_id', $tenant->id)->count());
        $this->assertSame(
            count(TimesheetCategory::DEFAULTS),
            TimesheetCategory::withoutGlobalScope('tenant')->where('tenant_id', $tenant->id)->count()
        );
        $this->assertDatabaseHas('greeting_lines', ['tenant_id' => $tenant->id]);
        $this->assertDatabaseHas('easter_eggs', ['tenant_id' => $tenant->id]);
        $this->assertTrue($tenant->featureEnabled('module.leave'));

        // Invite is spent, audit row written, nobody emailed (they chose their password).
        $invite->refresh();
        $this->assertNotNull($invite->used_at);
        $this->assertSame($tenant->id, $invite->used_by_tenant_id);
        $this->assertDatabaseHas('audit_logs', [
            'tenant_id' => $tenant->id, 'user_id' => $user->id, 'actor_name' => 'Faizal bin Ahmad',
            'action' => 'Company self-registered', 'target' => 'Maju Bina Sdn Bhd · admin faizal@majubina.com',
        ]);
        Notification::assertNothingSent();

        // Logged in, inside the new company.
        $this->assertAuthenticatedAs($user);
        $response->assertSessionHas('current_tenant', $tenant->id);
        $response->assertSessionHas('persona', 'hr');
        $response->assertSessionMissing('url.intended');
    }

    public function test_after_signup_launch_center_actually_renders(): void
    {
        $invite = CompanyInvite::factory()->create();

        $this->post('/register', $this->guestPayload($invite));

        $this->get('/app/setup')->assertOk();
    }

    public function test_company_name_collision_gets_a_numeric_suffix(): void
    {
        Tenant::create(['slug' => 'maju-bina-sdn-bhd', 'name' => 'Maju Bina Sdn Bhd', 'initials' => 'MB']);
        $invite = CompanyInvite::factory()->create();

        $this->post('/register', $this->guestPayload($invite));

        $this->assertDatabaseHas('tenants', ['slug' => 'maju-bina-sdn-bhd-2', 'name' => 'Maju Bina Sdn Bhd']);
    }

    public function test_guest_with_an_existing_email_is_told_to_sign_in_and_nothing_is_created(): void
    {
        User::create(['name' => 'Taken', 'email' => 'faizal@majubina.com', 'password' => Hash::make('<redacted>')]);
        $invite = CompanyInvite::factory()->create();

        $response = $this->from('/register?invite='.$invite->token)
            ->post('/register', $this->guestPayload($invite));

        $response->assertRedirect('/register?invite='.$invite->token)
            ->assertSessionHasErrors(['email' => 'That email already has an account. Sign in first, then open this link again.']);

        $this->assertSame(0, Tenant::count());
        $this->assertNull($invite->fresh()->used_at);
        $this->assertGuest();

        // The re-rendered form carries the token and the sign-in nudge.
        $this->get('/register?invite='.$invite->token)
            ->assertOk()
            ->assertSee('Sign in')
            ->assertSee('name="invite" value="'.$invite->token.'"', false);
    }

    public function test_signed_in_user_is_attached_as_hr_without_a_new_user_row(): void
    {
        $mei = $this->memberOfAcme();
        $invite = CompanyInvite::factory()->create();
        $usersBefore = User::count();

        $response = $this->actingAs($mei)->post('/register', [
            'invite' => $invite->token,
            'company_name' => 'Second Venture',
        ]);

        $response->assertRedirect(route('app.screen', 'setup'));

        $tenant = Tenant::where('name', 'Second Venture')->firstOrFail();
        $this->assertSame($usersBefore, User::count());
        $this->assertSame('hr', $mei->fresh()->roleIn($tenant));
        $this->assertSame('employee', $mei->fresh()->roleIn(Tenant::where('slug', 'acme')->firstOrFail()));
        $this->assertDatabaseHas('employees', ['tenant_id' => $tenant->id, 'user_id' => $mei->id, 'position' => 'HR Admin']);
        $response->assertSessionHas('current_tenant', $tenant->id);
        $this->assertSame($tenant->id, $invite->fresh()->used_by_tenant_id);
    }

    public function test_signed_in_user_submitting_name_and_password_fields_has_them_ignored(): void
    {
        $mei = $this->memberOfAcme();
        $invite = CompanyInvite::factory()->create();

        $this->actingAs($mei)->post('/register', $this->guestPayload($invite, ['company_name' => 'Second Venture']))
            ->assertRedirect(route('app.screen', 'setup'));

        $this->assertSame(0, User::where('email', 'faizal@majubina.com')->count());
        $this->assertSame('Mei Ling', $mei->fresh()->name);
    }

    public function test_super_admin_post_is_refused(): void
    {
        $invite = CompanyInvite::factory()->create();

        $this->actingAs($this->superAdmin())
            ->post('/register', ['invite' => $invite->token, 'company_name' => 'Nope'])
            ->assertForbidden();

        $this->assertSame(0, Tenant::count());
    }

    public function test_unknown_token_on_post_404s(): void
    {
        $this->post('/register', $this->guestPayload(CompanyInvite::factory()->create(), ['invite' => str_repeat('x', 40)]))->assertNotFound();
        $this->post('/register', ['company_name' => 'x'])->assertNotFound();
    }

    public function test_second_submit_with_the_same_token_fails(): void
    {
        $invite = CompanyInvite::factory()->create();
        $this->post('/register', $this->guestPayload($invite))->assertRedirect(route('app.screen', 'setup'));
        $this->post('/logout');
        $this->assertGuest();

        $this->post('/register', $this->guestPayload($invite, ['email' => 'second@majubina.com', 'company_name' => 'Copycat']))
            ->assertSessionHasErrors(['invite' => 'This link has already been used.']);

        $this->assertSame(1, Tenant::count());
        $this->assertSame(0, User::where('email', 'second@majubina.com')->count());
    }

    public function test_expired_token_on_post_is_rejected(): void
    {
        $invite = CompanyInvite::factory()->expired()->create();

        $this->post('/register', $this->guestPayload($invite))
            ->assertSessionHasErrors(['invite' => 'This link has expired.']);

        $this->assertSame(0, Tenant::count());
    }

    public function test_validation_failure_keeps_the_token_and_creates_nothing(): void
    {
        $invite = CompanyInvite::factory()->create();

        $this->from('/register?invite='.$invite->token)
            ->post('/register', $this->guestPayload($invite, ['password_confirmation' => '<redacted-mismatch>']))
            ->assertRedirect('/register?invite='.$invite->token)
            ->assertSessionHasErrors('password');

        $this->assertSame(0, Tenant::count());
        $this->assertSame(0, User::where('email', 'faizal@majubina.com')->count());
        $this->assertNull($invite->fresh()->used_at);
    }

    public function test_registration_switch_off_blocks_the_post(): void
    {
        app(FeatureManager::class)->setPlatform('platform.registration', false, false);
        $invite = CompanyInvite::factory()->create();

        $this->post('/register', $this->guestPayload($invite))->assertRedirect('/login');

        $this->assertSame(0, Tenant::count());
        $this->assertNull($invite->fresh()->used_at);
    }
```

  Note: `MemberInvited` import is only there to make the "nothing sent" intent obvious; if pint or phpstan flags it unused, drop it and keep `Notification::assertNothingSent()`.

- [ ] Run and confirm the new cases fail with 501:

```
php artisan test --compact tests/Feature/CompanySignupTest.php
```

- [ ] Replace `store()` in `app/Http/Controllers/CompanySignupController.php` and add the imports. Full file after the change:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\CompanyInvite;
use App\Models\User;
use App\Services\CompanyProvisioner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View as ViewContract;

/**
 * Invite-link company signup. Replaces Fortify's registration: there is no way to
 * create an account here without a live CompanyInvite token, and the person who
 * signs up becomes the HR admin of the company they just named.
 */
class CompanySignupController extends Controller
{
    /** The signup form, or the reason the link no longer works. */
    public function show(Request $request): ViewContract|Response
    {
        $invite = $this->inviteOr404((string) $request->query('invite', ''));

        if ($request->user()?->isSuperAdmin()) {
            return response()->view('auth.register', ['invite' => $invite, 'state' => 'superadmin'], 403);
        }

        if (! $invite->isUsable()) {
            return response()->view('auth.register', ['invite' => $invite, 'state' => $invite->status()], 404);
        }

        // Someone whose email already has an account is told to sign in; bring them
        // straight back to this link afterwards (Fortify's LoginResponse honours intended).
        $request->session()->put('url.intended', $request->fullUrl());

        return view('auth.register', ['invite' => $invite, 'state' => 'form']);
    }

    /**
     * Create the company, its HQ / General structure and its HR admin in one
     * transaction, spend the invite, and drop the new admin into Launch Center.
     * A signed-in member is attached as-is; a guest gets a new account with the
     * password they chose (no forced rotation, no verification mail: the link was
     * the verification).
     */
    public function store(Request $request): RedirectResponse
    {
        $invite = $this->inviteOr404((string) $request->input('invite', ''));

        abort_if($request->user()?->isSuperAdmin(), 403, 'You already see every company.');

        $member = $request->user();

        $rules = ['company_name' => ['required', 'string', 'max:120']];
        if ($member === null) {
            $rules += [
                'name' => ['required', 'string', 'max:120'],
                'email' => ['required', 'email', 'max:160', Rule::unique('users', 'email')],
                'password' => ['required', 'string', Password::default(), 'confirmed'],
            ];
        }

        $data = $request->validate($rules, [
            'email.unique' => 'That email already has an account. Sign in first, then open this link again.',
        ]);

        $tenant = DB::transaction(function () use ($invite, $member, $data) {
            // Two people (or one double-click) racing on the same link: the row lock
            // serialises them and the second one sees used_at set.
            $locked = CompanyInvite::whereKey($invite->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isUsable()) {
                throw ValidationException::withMessages([
                    'invite' => $locked->status() === 'expired' ? 'This link has expired.' : 'This link has already been used.',
                ]);
            }

            $tenant = app(CompanyProvisioner::class)->provision(
                company: ['name' => $data['company_name']],
                category: $locked->category,
                structure: ['branch_name' => 'HQ', 'department_name' => 'General'],
                admin: $member ?? [
                    'name' => $data['name'],
                    'email' => $data['email'],
                    'password' => $data['password'],
                    'password_change_required' => false,
                    'email_verified_at' => now(),
                ],
                auditAction: 'Company self-registered',
            );

            $locked->forceFill(['used_at' => now(), 'used_by_tenant_id' => $tenant->id])->save();

            return $tenant;
        });

        $member ??= User::where('email', $data['email'])->firstOrFail();

        Auth::login($member);
        $request->session()->regenerate();
        $request->session()->forget('url.intended');
        // Same two keys AppController::enterTenant sets, so the shell opens on this company.
        $request->session()->put(['current_tenant' => $tenant->id, 'persona' => 'hr']);

        return redirect()->route('app.screen', 'setup');
    }

    private function inviteOr404(string $token): CompanyInvite
    {
        abort_if($token === '', 404);

        $invite = CompanyInvite::forToken($token)->with('category')->first();
        abort_if($invite === null, 404);

        return $invite;
    }
}
```

- [ ] Run the signup file and expect green:

```
php artisan test --compact tests/Feature/CompanySignupTest.php
```

  If `test_after_signup_launch_center_actually_renders` fails on a middleware gate (`ForcePasswordChange`, `EnforceTwoFactor`, `EnsureSystemLaunched`), the fix is on the signup side, not the gate: `password_change_required` must be false and `hr` must be allowed through the launch lock (HR already is; only plain staff are held). Do not touch the middleware.

- [ ] Run phpstan on the controller and provisioner:

```
vendor/bin/phpstan analyse app/Http/Controllers/CompanySignupController.php app/Services/CompanyProvisioner.php --no-progress
```

- [ ] Format and commit:

```
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/CompanySignupController.php tests/Feature/CompanySignupTest.php
git commit -m "feat(signup): invite-link POST provisions the company and lands the new HR admin in Launch Center"
```

---

### Task 6: full suite, assets, browser check, final commit

**Files:**
- Modify: `public/build/**` (rebuilt assets; Blade changed in Tasks 3, 4)
- No source changes expected.

**Steps:**

- [ ] Run the whole suite; everything must be green (the staging pipeline runs it with an 80% coverage floor):

```
php artisan test --compact
```

- [ ] Run phpstan over the app the way CI does:

```
vendor/bin/phpstan analyse --no-progress
```

- [ ] Rebuild assets from the clean tree (view:clear first so stale compiled views do not leak classes into the CSS):

```
lerd artisan view:clear && lerd artisan view:cache && bun run build
```

- [ ] Browser check on `http://localhost:9100` (integrated browser MCP; if it is not connected, real headless Chromium from `~/.cache/ms-playwright`, not Obscura, because clicking links is needed):
  1. Quick-login as `superadmin@amanahku.com`, open `/admin/companies`, generate a link with note "Browser check" and Stage 3, confirm the green "Link ready" banner shows the URL and the row shows Pending with Copy / Revoke.
  2. Copy the URL, sign out, open it: the "Set up your company" form with the "Invited · link valid until …" pill. Submit with a new email and a password; confirm you land on `/app/setup` (Launch Center) inside the new company.
  3. Open the same URL again in the same browser: "This link has already been used" page.
  4. Back in superadmin: the row now reads "Used · <company>" with "Open company".
  5. `/register` with no token: 404. `/login` no longer shows "Create an account".

- [ ] Commit the built assets:

```
git add public/build
git commit -m "build: assets for invite-link signup and superadmin signup-links card"
```

- [ ] Report: list the six commits, note that open self-registration (`POST /register` with no invite) is gone by design and that `tests/Feature/RegistrationFlowTest::test_a_visitor_can_register_and_is_unverified` was removed for that reason, and that `plan` defaults to `Starter` for self-signed-up companies (spec said "default" without naming one; superadmin can change it on the company edit page).

---

## Self-review

**Spec coverage (Change 1 + Signup edge cases + Tests):**
- Table columns, 40-char token, 7-day expiry, Stage 3 default: Task 1 (migration, factory default level 3, `CompanyInviteController::store` `addDays(7)`, index picker Stage 3 first).
- Superadmin generate / list (note, category, created, expires, status pending/used by X/expired) / copy / revoke unused: Task 3. Used rows link to the company: Task 3 view `Open company`. Non-superadmin 403: Task 3 test.
- `/register?invite=` replaces Fortify's view; missing/unknown/used/expired 404: Task 4 (`Features::registration()` removed, routes own `/register`, `state` view branches with 404 status).
- Form fields, `HQ` / `General` / `HR Admin`, chosen password with no forced rotation, `hr` role, active Employee, seeds, invite marked used, audit "Company self-registered", login + `current_tenant` + Launch Center redirect: Task 5 controller + first test.
- Provisioner shared by both paths, superadmin behaviour unchanged (temp password, `password_change_required`, `MemberInvited`, "Provisioned company" audit): Task 2, pinned by the added regression test and the existing `SuperAdminCompanyTest`.
- Rate limits: GET `throttle:20,1,signup`, POST `throttle:5,1,signup-post` (separate prefix so the two buckets do not share a counter): Task 4 routes.
- Master switch: `BlockRegistrationWhenDisabled` untouched, route names preserved, pending invites survive: Task 4 and Task 5 tests.
- Edge cases: existing email → sign in nudge with login link and `url.intended` round-trip (Task 4 show, Task 5 test); signed-in user attached as hr, name/password ignored (Task 5); slug suffix (Task 5 test); double submit / race → `lockForUpdate` + "This link has already been used." (Task 5); token in hidden field on re-render (Task 4 view, Task 5 test); superadmin refused with "You already see every company" on GET (403 page) and POST (403) (Tasks 4, 5); revoke only unused rows (Task 3, 403 on used); "signup done, tab closed" needs nothing new (normal login → workspace picker).
- `work_days` / `tot_saturday`: never referenced. Confirmed by grep before Task 6 commit if in doubt: `grep -rn "work_days\|tot_saturday" app/Services/CompanyProvisioner.php app/Http/Controllers/CompanySignupController.php` must be empty.

**Name/type consistency:** `CompanyProvisioner::provision(array $company, CompanyCategory $category, array $structure, User|array $admin, string $auditAction): Tenant` is used identically in Task 2 (controller) and Task 5 (signup). `CompanyInvite::forToken()`, `isUsable()`, `status()`, `url()`, `category`, `usedByTenant` are defined in Task 1 and consumed by Tasks 3, 4, 5. Route names `superadmin.invites.store` / `superadmin.invites.destroy` (Task 3) and `register` / `register.store` (Task 4) match every `route()` call in views and tests. View variable `$state` takes exactly `form | superadmin | used | expired`, matching `CompanyInvite::status()` values for the two dead cases.

**Placeholders:** none; every step carries the full code it applies.
