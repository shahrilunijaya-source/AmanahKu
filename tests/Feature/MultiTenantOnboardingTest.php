<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\CompanyCategory;
use App\Models\CompanySetupProgress;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\StaffLevel;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserPermission;
use App\Notifications\MemberInvited;
use App\Services\FeatureManager;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Covers the multi-tenant onboarding spine: company categories → feature
 * entitlement, company status enforcement, the branded login portal, the
 * super-admin-only field boundary, org lookups and the setup wizard.
 */
class MultiTenantOnboardingTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): User
    {
        $u = User::create(['name' => 'Platform', 'email' => 'super@example.com', 'password' => Hash::make('password')]);
        $u->forceFill(['is_super_admin' => true])->save();

        return $u;
    }

    /** @return array{0:Tenant,1:User} a tenant at the given stage + its HR admin. */
    private function company(int $level = 1, array $attrs = []): array
    {
        $category = CompanyCategory::where('level', $level)->first();
        $tenant = Tenant::create(array_merge([
            'slug' => 'acme'.$level,
            'name' => 'Acme '.$level,
            'initials' => 'A'.$level,
            'company_category_id' => $category->id,
        ], $attrs));

        $hr = User::create(['name' => 'HR '.$level, 'email' => 'hr'.$level.'@example.com', 'password' => Hash::make('password')]);
        $hr->tenants()->attach($tenant->id, ['role' => 'hr']);
        Employee::create(['tenant_id' => $tenant->id, 'user_id' => $hr->id, 'name' => 'HR '.$level, 'status' => 'active', 'workload' => 'green', 'workload_label' => 'Healthy', 'initials' => 'HR', 'avatar_color' => '#000', 'joined_at' => now()->toDateString()]);

        return [$tenant, $hr];
    }

    public function test_category_package_enables_only_in_scope_modules(): void
    {
        [$tenant] = $this->company(1);
        app(FeatureManager::class)->applyCategoryPackage($tenant, 1);

        $this->assertTrue($tenant->featureEnabled('module.leave'));     // stage 1
        $this->assertFalse($tenant->featureEnabled('module.payroll'));  // stage 2
        $this->assertFalse($tenant->featureEnabled('module.ai'));       // stage 3
    }

    public function test_changing_category_reapplies_the_package(): void
    {
        [$tenant] = $this->company(1);
        app(FeatureManager::class)->applyCategoryPackage($tenant, 1);
        $this->assertFalse($tenant->featureEnabled('module.payroll'));

        $stage2 = CompanyCategory::where('level', 2)->first();
        $this->actingAs($this->superAdmin())
            ->post("/admin/companies/{$tenant->slug}/category", ['company_category_id' => $stage2->id])
            ->assertRedirect();

        $this->assertSame($stage2->id, $tenant->fresh()->company_category_id);
        $this->assertTrue($tenant->fresh()->featureEnabled('module.payroll')); // now in scope
        $this->assertFalse($tenant->fresh()->featureEnabled('module.ai'));     // still stage 3
    }

    public function test_disabled_module_screen_404s_for_a_stage1_company(): void
    {
        [$tenant, $hr] = $this->company(1);
        app(FeatureManager::class)->applyCategoryPackage($tenant, 1);

        $this->actingAs($hr)->withSession(['current_tenant' => $tenant->id])
            ->get('/app/payroll')->assertNotFound(); // module.payroll is Stage 2
    }

    public function test_super_admin_can_suspend_and_reactivate_a_company(): void
    {
        [$tenant] = $this->company(1);
        $admin = $this->superAdmin();

        $this->actingAs($admin)->post("/admin/companies/{$tenant->slug}/status", ['status' => 'suspended'])->assertRedirect();
        $this->assertFalse($tenant->fresh()->isActive());

        $this->actingAs($admin)->post("/admin/companies/{$tenant->slug}/status", ['status' => 'active'])->assertRedirect();
        $this->assertTrue($tenant->fresh()->isActive());
    }

    public function test_suspended_company_blocks_every_tenant_route(): void
    {
        [$tenant, $hr] = $this->company(1, ['status' => 'suspended']);

        $this->actingAs($hr)->withSession(['current_tenant' => $tenant->id])
            ->get('/app/dash')->assertForbidden();
    }

    public function test_branded_login_renders_company_branding(): void
    {
        [$tenant] = $this->company(1, ['slug' => 'beta', 'name' => 'Beta Corp', 'welcome_message' => 'Welcome aboard Beta.']);

        $this->get('/login/beta')
            ->assertOk()
            ->assertSee('Beta Corp')
            ->assertSee('Welcome aboard Beta.');
    }

    public function test_unknown_branded_slug_404s(): void
    {
        $this->get('/login/no-such-company')->assertNotFound();
    }

    public function test_branded_login_auto_enters_a_member_after_authentication(): void
    {
        [$tenant, $hr] = $this->company(1, ['slug' => 'gamma', 'name' => 'Gamma']);

        // Simulates the post-login landing: intent set by /login/{slug}, then Fortify
        // redirects to /tenant. A genuine member is dropped straight into the company.
        $this->actingAs($hr)->withSession(['intended_tenant' => 'gamma'])
            ->get('/tenant')
            ->assertRedirect(route('tenant.enter', $tenant));
    }

    public function test_non_member_branded_intent_is_ignored(): void
    {
        [$tenant] = $this->company(1, ['slug' => 'delta', 'name' => 'Delta']);
        $outsider = User::create(['name' => 'Out', 'email' => 'out@example.com', 'password' => Hash::make('password')]);

        // A non-member's intent never enters the company — they see the picker instead.
        $this->actingAs($outsider)->withSession(['intended_tenant' => 'delta'])
            ->get('/tenant')->assertOk();
    }

    public function test_company_admin_cannot_change_category_plan_or_status_via_settings(): void
    {
        [$tenant, $hr] = $this->company(1, ['plan' => 'Business']);
        $originalCategory = $tenant->company_category_id;

        $this->actingAs($hr)->withSession(['current_tenant' => $tenant->id])
            ->post('/app/admin/settings', [
                'name' => 'Renamed Co',
                'plan' => 'Enterprise',          // not accepted from a company admin
                'status' => 'suspended',         // not accepted
                'company_category_id' => 9,      // not accepted
                'slug' => 'hacked',              // not accepted
            ])->assertRedirect();

        $fresh = $tenant->fresh();
        $this->assertSame('Renamed Co', $fresh->name);     // editable field changed
        $this->assertSame('Business', $fresh->plan);        // unchanged
        $this->assertTrue($fresh->isActive());              // unchanged
        $this->assertSame($originalCategory, $fresh->company_category_id); // unchanged
        $this->assertSame($tenant->slug, $fresh->slug);     // unchanged
    }

    public function test_staff_levels_are_tenant_isolated(): void
    {
        [$tenantA, $hrA] = $this->company(1);
        [$tenantB, $hrB] = $this->company(2);

        $this->actingAs($hrA)->withSession(['current_tenant' => $tenantA->id])
            ->post('/app/admin/staff-levels', ['name' => 'L1'])->assertRedirect();

        $level = StaffLevel::withoutGlobalScopes()->where('tenant_id', $tenantA->id)->firstOrFail();
        $this->assertSame($tenantA->id, $level->tenant_id);

        // Tenant B cannot delete tenant A's level — the controller's tenant guard rejects it.
        $this->actingAs($hrB)->withSession(['current_tenant' => $tenantB->id])
            ->post("/app/admin/staff-levels/{$level->id}/delete")->assertForbidden();
    }

    public function test_setup_finish_requires_every_step(): void
    {
        [$tenant, $hr] = $this->company(1);

        // Fresh company: not every step is satisfied, so finishing is rejected.
        $this->actingAs($hr)->withSession(['current_tenant' => $tenant->id])
            ->post('/app/setup/finish')->assertRedirect();

        $this->assertNull(CompanySetupProgress::withoutGlobalScopes()->where('tenant_id', $tenant->id)->first()?->completed_at);
    }

    // ── Phase F: ACL data scope ────────────────────────────────────────────────

    public function test_role_permission_map(): void
    {
        $this->assertTrue(Permissions::roleHas('manager', 'leave.approve'));
        $this->assertTrue(Permissions::roleHas('hr', 'staff.create'));
        $this->assertFalse(Permissions::roleHas('employee', 'staff.create'));
    }

    public function test_data_scope_narrows_directory_to_own_department(): void
    {
        [$tenant] = $this->company(1);
        $finance = Department::create(['tenant_id' => $tenant->id, 'name' => 'Finance']);
        $it = Department::create(['tenant_id' => $tenant->id, 'name' => 'IT']);

        $mgr = User::create(['name' => 'Mgr', 'email' => 'mgr@example.com', 'password' => Hash::make('password')]);
        $mgr->tenants()->attach($tenant->id, ['role' => 'manager', 'data_scope' => 'department']);
        Employee::create(['tenant_id' => $tenant->id, 'user_id' => $mgr->id, 'name' => 'Manager Person', 'department_id' => $finance->id, 'status' => 'active', 'workload' => 'green', 'workload_label' => 'Healthy', 'initials' => 'MP', 'avatar_color' => '#000', 'joined_at' => now()->toDateString()]);
        Employee::create(['tenant_id' => $tenant->id, 'name' => 'Finance Colleague', 'department_id' => $finance->id, 'status' => 'active', 'workload' => 'green', 'workload_label' => 'Healthy', 'initials' => 'FC', 'avatar_color' => '#000', 'joined_at' => now()->toDateString()]);
        Employee::create(['tenant_id' => $tenant->id, 'name' => 'IT Colleague', 'department_id' => $it->id, 'status' => 'active', 'workload' => 'green', 'workload_label' => 'Healthy', 'initials' => 'IC', 'avatar_color' => '#000', 'joined_at' => now()->toDateString()]);

        $this->actingAs($mgr)->withSession(['current_tenant' => $tenant->id])
            ->get('/app/directory')
            ->assertOk()
            ->assertSee('Finance Colleague')
            ->assertDontSee('IT Colleague');
    }

    public function test_default_company_scope_sees_all_staff(): void
    {
        [$tenant] = $this->company(1);
        $it = Department::create(['tenant_id' => $tenant->id, 'name' => 'IT']);

        $mgr = User::create(['name' => 'Mgr2', 'email' => 'mgr2@example.com', 'password' => Hash::make('password')]);
        $mgr->tenants()->attach($tenant->id, ['role' => 'manager']); // default scope = company
        Employee::create(['tenant_id' => $tenant->id, 'user_id' => $mgr->id, 'name' => 'Manager Two', 'status' => 'active', 'workload' => 'green', 'workload_label' => 'Healthy', 'initials' => 'M2', 'avatar_color' => '#000', 'joined_at' => now()->toDateString()]);
        Employee::create(['tenant_id' => $tenant->id, 'name' => 'IT Colleague', 'department_id' => $it->id, 'status' => 'active', 'workload' => 'green', 'workload_label' => 'Healthy', 'initials' => 'IC', 'avatar_color' => '#000', 'joined_at' => now()->toDateString()]);

        $this->actingAs($mgr)->withSession(['current_tenant' => $tenant->id])
            ->get('/app/directory')->assertOk()->assertSee('IT Colleague');
    }

    public function test_admin_can_set_a_member_data_scope(): void
    {
        [$tenant, $hr] = $this->company(1);
        $member = User::create(['name' => 'Member', 'email' => 'member@example.com', 'password' => Hash::make('password')]);
        $member->tenants()->attach($tenant->id, ['role' => 'manager']);

        $this->actingAs($hr)->withSession(['current_tenant' => $tenant->id])
            ->post("/app/admin/scope/{$member->id}", ['data_scope' => 'branch'])->assertRedirect();

        $this->assertSame('branch', $member->fresh()->dataScopeIn($tenant->fresh()));
    }

    // ── Phase G: bulk CSV import ───────────────────────────────────────────────

    public function test_csv_import_creates_staff_and_skips_invalid_rows(): void
    {
        [$tenant, $hr] = $this->company(1);
        $finance = Department::create(['tenant_id' => $tenant->id, 'name' => 'Finance']);
        // The position band is the source of truth: matching it by title derives the
        // department / job title, mirroring the New employee form.
        Position::create(['tenant_id' => $tenant->id, 'department_id' => $finance->id, 'title' => 'Accountant', 'max_salary' => 5000]);

        $csv = "name,email,staff_id,joined,date_of_birth,position_band,salary,status\n"
            ."New Hire,new@example.com,UR-0007,2022-03-14,1990-05-12,Accountant,4200,active\n"
            .",,,,,,,\n"                                            // blank line — skipped silently
            ."Bad Row,not-an-email,,,,Accountant,,active\n";        // invalid email — skipped + reported

        $file = UploadedFile::fake()->createWithContent('staff.csv', $csv);

        $this->actingAs($hr)->withSession(['current_tenant' => $tenant->id])
            ->post('/app/employees/import', ['file' => $file])->assertRedirect();

        // Department + job title flow from the matched band; staff_id / salary stored per person.
        $this->assertDatabaseHas('employees', [
            'tenant_id' => $tenant->id, 'name' => 'New Hire', 'staff_id' => 'UR-0007',
            'department_id' => $finance->id, 'position' => 'Accountant', 'salary' => '4200.00',
        ]);
        $this->assertSame('2022-03-14', Employee::where('name', 'New Hire')->first()->joined_at->toDateString());
        $this->assertDatabaseMissing('employees', ['tenant_id' => $tenant->id, 'name' => 'Bad Row']);
    }

    public function test_csv_import_matches_names_case_insensitively_and_rejects_non_iso_dates(): void
    {
        [$tenant, $hr] = $this->company(1);
        $branch = Branch::create(['tenant_id' => $tenant->id, 'name' => 'Head Office']);

        $csv = "name,branch,date_of_birth,status\n"
            ."Case Match,head office,1990-01-01,active\n"     // lower-case branch still resolves the FK
            ."Bad Date,Head Office,03/04/2022,active\n";      // ambiguous DD/MM date — rejected, not mis-stored

        $file = UploadedFile::fake()->createWithContent('staff.csv', $csv);

        $this->actingAs($hr)->withSession(['current_tenant' => $tenant->id])
            ->post('/app/employees/import', ['file' => $file])->assertRedirect();

        $this->assertDatabaseHas('employees', ['tenant_id' => $tenant->id, 'name' => 'Case Match', 'branch_id' => $branch->id]);
        $this->assertDatabaseMissing('employees', ['tenant_id' => $tenant->id, 'name' => 'Bad Date']);
    }

    public function test_reuploading_the_same_csv_does_not_duplicate_staff(): void
    {
        [$tenant, $hr] = $this->company(1);

        $csv = "name,email,staff_id,status\n"
            ."Dup Check,dup@example.com,UR-0042,active\n"
            ."No Contact Row,,,active\n";

        $upload = fn () => $this->actingAs($hr)->withSession(['current_tenant' => $tenant->id])
            ->post('/app/employees/import', ['file' => UploadedFile::fake()->createWithContent('staff.csv', $csv)]);

        $upload()->assertRedirect();
        $upload()->assertRedirect();   // same file again — every row must be skipped

        $this->assertSame(1, Employee::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('email', 'dup@example.com')->count());
        $this->assertSame(1, Employee::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('name', 'No Contact Row')->count());
    }

    public function test_import_template_downloads(): void
    {
        [$tenant, $hr] = $this->company(1);

        $this->actingAs($hr)->withSession(['current_tenant' => $tenant->id])
            ->get('/app/employees/import-template')
            ->assertOk()
            ->assertSee('name,email,staff_id,joined,date_of_birth,position_band');
    }

    public function test_employee_cannot_import_staff(): void
    {
        [$tenant] = $this->company(1);
        $emp = User::create(['name' => 'Emp', 'email' => 'emp@example.com', 'password' => Hash::make('password')]);
        $emp->tenants()->attach($tenant->id, ['role' => 'employee']);

        $file = UploadedFile::fake()->createWithContent('staff.csv', "name\nX\n");
        $this->actingAs($emp)->withSession(['current_tenant' => $tenant->id])
            ->post('/app/employees/import', ['file' => $file])->assertForbidden();
    }

    // ── Phase H: provisioning logins for imported/directory staff ──────────────

    public function test_bulk_provision_creates_logins_for_emailed_staff_only(): void
    {
        Notification::fake();

        [$tenant, $hr] = $this->company(1);
        $withEmail = Employee::create(['tenant_id' => $tenant->id, 'name' => 'Has Email', 'email' => 'has@example.com', 'status' => 'active', 'workload' => 'green']);
        $noEmail = Employee::create(['tenant_id' => $tenant->id, 'name' => 'No Email', 'status' => 'active', 'workload' => 'green']);

        $before = Employee::count();

        $this->actingAs($hr)->withSession(['current_tenant' => $tenant->id])
            ->post('/app/members/provision')->assertRedirect();

        // The emailed record gets a login linked to the SAME row (no duplicate Employee).
        $user = User::where('email', 'has@example.com')->first();
        $this->assertNotNull($user);
        $this->assertSame($user->id, $withEmail->fresh()->user_id);
        $this->assertFalse(Hash::check('password', $user->password));  // per-user random, never the old shared default
        $this->assertTrue((bool) $user->fresh()->password_change_required); // must reset on first sign-in
        $this->assertSame('employee', $user->tenants()->where('tenant_id', $tenant->id)->first()->pivot->role);
        $this->assertSame($before, Employee::count());                 // no rows added

        // The one-time credential + activation link is emailed, never displayed.
        Notification::assertSentTo($user, MemberInvited::class);

        // The record with no email is left without a login.
        $this->assertNull($noEmail->fresh()->user_id);
    }

    public function test_provision_skips_an_email_that_already_has_an_account(): void
    {
        [$tenant, $hr] = $this->company(1);
        // A directory record whose email already belongs to a (different) login.
        User::create(['name' => 'Existing', 'email' => 'clash@example.com', 'password' => Hash::make('x')]);
        $emp = Employee::create(['tenant_id' => $tenant->id, 'name' => 'Clash', 'email' => 'clash@example.com', 'status' => 'active', 'workload' => 'green']);

        $this->actingAs($hr)->withSession(['current_tenant' => $tenant->id])
            ->post('/app/members/provision')->assertRedirect();

        // Not linked — the existing account is never hijacked.
        $this->assertNull($emp->fresh()->user_id);
        $this->assertSame(1, User::where('email', 'clash@example.com')->count());
    }

    public function test_per_row_create_login_links_the_existing_record(): void
    {
        Notification::fake();

        [$tenant, $hr] = $this->company(1);
        $emp = Employee::create(['tenant_id' => $tenant->id, 'name' => 'Row Login', 'email' => 'row@example.com', 'status' => 'active', 'workload' => 'green']);
        $before = Employee::count();

        $this->actingAs($hr)->withSession(['current_tenant' => $tenant->id])
            ->post("/app/members/{$emp->id}/login")->assertRedirect();

        $user = $emp->fresh()->user;
        $this->assertNotNull($emp->fresh()->user_id);
        $this->assertSame($before, Employee::count());
        $this->assertFalse(Hash::check('password', $user->password));  // per-user random, never a shared default
        $this->assertTrue((bool) $user->password_change_required);
        Notification::assertSentTo($user, MemberInvited::class);
    }

    public function test_plain_employee_cannot_provision_logins(): void
    {
        [$tenant] = $this->company(1);
        $emp = User::create(['name' => 'Plain', 'email' => 'plain@example.com', 'password' => Hash::make('password')]);
        $emp->tenants()->attach($tenant->id, ['role' => 'employee']);

        $this->actingAs($emp)->withSession(['current_tenant' => $tenant->id])
            ->post('/app/members/provision')->assertForbidden();
    }

    // ── Permission overrides (user_permissions) ────────────────────────────────

    public function test_permission_override_grants_a_manager_staff_create(): void
    {
        [$tenant] = $this->company(1);
        $mgr = User::create(['name' => 'Mgr', 'email' => 'm@example.com', 'password' => Hash::make('password')]);
        $mgr->tenants()->attach($tenant->id, ['role' => 'manager']);

        // By role a manager cannot create staff.
        $this->actingAs($mgr)->withSession(['current_tenant' => $tenant->id])
            ->post('/app/employees', ['name' => 'A', 'status' => 'active'])->assertForbidden();

        // Granting staff.create via an override lets this one manager through.
        UserPermission::create(['tenant_id' => $tenant->id, 'user_id' => $mgr->id, 'permission' => 'staff.create', 'granted' => true]);

        $this->actingAs($mgr)->withSession(['current_tenant' => $tenant->id])
            ->post('/app/employees', ['name' => 'Created By Manager', 'status' => 'active'])->assertRedirect();
        $this->assertDatabaseHas('employees', ['tenant_id' => $tenant->id, 'name' => 'Created By Manager']);
    }

    public function test_permission_override_denies_an_hr_admin(): void
    {
        [$tenant, $hr] = $this->company(1);
        UserPermission::create(['tenant_id' => $tenant->id, 'user_id' => $hr->id, 'permission' => 'staff.create', 'granted' => false]);

        $this->actingAs($hr)->withSession(['current_tenant' => $tenant->id])
            ->post('/app/employees', ['name' => 'Blocked', 'status' => 'active'])->assertForbidden();
    }

    public function test_admin_can_set_permission_overrides(): void
    {
        [$tenant, $hr] = $this->company(1);
        $member = User::create(['name' => 'Mem', 'email' => 'mem2@example.com', 'password' => Hash::make('password')]);
        $member->tenants()->attach($tenant->id, ['role' => 'employee']);

        $this->actingAs($hr)->withSession(['current_tenant' => $tenant->id])
            ->post("/app/admin/permissions/{$member->id}", ['perm' => ['staff.create' => 'grant']])
            ->assertRedirect();

        $this->assertDatabaseHas('user_permissions', ['tenant_id' => $tenant->id, 'user_id' => $member->id, 'permission' => 'staff.create', 'granted' => true]);
        $this->assertTrue($member->fresh()->canInTenant($tenant->fresh(), 'staff.create'));
    }

    public function test_redundant_override_is_not_stored(): void
    {
        [$tenant, $hr] = $this->company(1);
        $member = User::create(['name' => 'Mem3', 'email' => 'mem3@example.com', 'password' => Hash::make('password')]);
        $member->tenants()->attach($tenant->id, ['role' => 'hr']); // hr already has staff.create

        $this->actingAs($hr)->withSession(['current_tenant' => $tenant->id])
            ->post("/app/admin/permissions/{$member->id}", ['perm' => ['staff.create' => 'grant']])
            ->assertRedirect();

        // Granting what the role already grants stores no override row.
        $this->assertDatabaseMissing('user_permissions', ['user_id' => $member->id, 'permission' => 'staff.create']);
    }

    // ── Activation link ────────────────────────────────────────────────────────

    public function test_activation_link_sets_password_and_activates(): void
    {
        $user = User::create(['name' => 'Invitee', 'email' => 'invitee@example.com', 'password' => Hash::make('temp')]);
        $user->forceFill(['password_change_required' => true])->save();

        $url = URL::temporarySignedRoute('activation.show', now()->addDay(), ['user' => $user->id]);
        $this->get($url)->assertOk()->assertSee('Activate');

        $this->post($url, ['password' => 'NewPass123!', 'password_confirmation' => 'NewPass123!'])
            ->assertRedirect(route('tenant.select'));

        $this->assertFalse($user->fresh()->password_change_required);
        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->assertAuthenticatedAs($user->fresh());
    }

    public function test_used_activation_link_is_rejected(): void
    {
        $user = User::create(['name' => 'Done', 'email' => 'done@example.com', 'password' => Hash::make('set')]);
        // password_change_required is false → already activated.

        $url = URL::temporarySignedRoute('activation.show', now()->addDay(), ['user' => $user->id]);
        $this->post($url, ['password' => 'NewPass123!', 'password_confirmation' => 'NewPass123!'])
            ->assertStatus(410);
    }

    public function test_unsigned_activation_link_is_forbidden(): void
    {
        $user = User::create(['name' => 'NoSig', 'email' => 'nosig@example.com', 'password' => Hash::make('x')]);

        $this->get("/activate/{$user->id}")->assertForbidden();
    }
}
