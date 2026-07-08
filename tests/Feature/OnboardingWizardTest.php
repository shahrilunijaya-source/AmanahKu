<?php

namespace Tests\Feature;

use App\Http\Controllers\SetupController;
use App\Models\Branch;
use App\Models\CompanyCategory;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveType;
use App\Models\Position;
use App\Models\SalaryStructure;
use App\Models\Tenant;
use App\Models\User;
use App\Services\FeatureManager;
use App\Support\ProfileCompletion;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Covers the staff first-login wizard, the two onboarding gates (profile-complete +
 * launch-lock), and the Launch Center's critical-domain detection.
 */
class OnboardingWizardTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0:Tenant,1:User} a tenant at the given stage + its HR admin. */
    private function company(int $level = 1): array
    {
        $category = CompanyCategory::where('level', $level)->first();
        $tenant = Tenant::create(['slug' => 'acme'.$level, 'name' => 'Acme '.$level, 'initials' => 'A'.$level, 'company_category_id' => $category->id, 'onboarding_enforced' => true]);
        app(FeatureManager::class)->applyCategoryPackage($tenant, $level);

        $hr = User::create(['name' => 'HR', 'email' => 'hr'.$level.'@example.com', 'password' => Hash::make('password')]);
        $hr->tenants()->attach($tenant->id, ['role' => 'hr']);
        Employee::create(['tenant_id' => $tenant->id, 'user_id' => $hr->id, 'name' => 'HR', 'status' => 'active', 'workload' => 'green', 'initials' => 'HR', 'avatar_color' => '#000', 'joined_at' => now()->toDateString()]);

        return [$tenant, $hr];
    }

    /** @return array{0:User,1:Employee} a plain staff user + their employee record. */
    private function staff(Tenant $tenant, array $emp = []): array
    {
        $u = User::create(['name' => 'Staff', 'email' => 'staff'.Employee::count().'@example.com', 'password' => Hash::make('password')]);
        $u->tenants()->attach($tenant->id, ['role' => 'employee']);
        $e = Employee::create(array_merge([
            'tenant_id' => $tenant->id, 'user_id' => $u->id, 'name' => 'Staff', 'status' => 'active',
            'workload' => 'green', 'initials' => 'ST', 'avatar_color' => '#000', 'joined_at' => now()->toDateString(),
        ], $emp));

        return [$u, $e];
    }

    private function essentialAttrs(): array
    {
        return [
            'nric' => '900101015555', 'date_of_birth' => '1990-01-01', 'phone' => '0123456789',
            'address' => '1 Jalan Test', 'emergency_contact_name' => 'Next of Kin', 'emergency_contact_phone' => '0198887777',
        ];
    }

    /** Satisfy every launch-critical domain so the launch lock lifts. */
    private function launch(Tenant $tenant): void
    {
        $dept = Department::create(['tenant_id' => $tenant->id, 'name' => 'IT']);
        $branch = Branch::create(['tenant_id' => $tenant->id, 'name' => 'HQ']);
        $branch->forceFill(['latitude' => 3.1, 'longitude' => 101.6])->save();
        Position::create(['tenant_id' => $tenant->id, 'department_id' => $dept->id, 'title' => 'Developer', 'max_salary' => 5000]);
        LeaveType::create(['tenant_id' => $tenant->id, 'name' => 'Annual', 'entitlement' => 14]);
    }

    // ── ProfileCompletion service ──────────────────────────────────────────────

    public function test_completeness_tiers_compute_from_filled_fields(): void
    {
        [$tenant] = $this->company(1); // stage 1 → payroll off → 3 groups
        app(CurrentTenant::class)->set($tenant);
        [, $emp] = $this->staff($tenant);

        $svc = app(ProfileCompletion::class);
        $this->assertFalse($svc->essentialDone($emp));
        $this->assertSame(0, $svc->percent($emp));

        // Essential tier (nric/dob/phone/address/emergency) is satisfied, but the
        // identity GROUP also wants gender + marital, so it is still incomplete — this
        // is the deliberate gap between the hard gate and the 100% target.
        $emp->update($this->essentialAttrs());
        $emp->refresh();

        $this->assertTrue($svc->essentialDone($emp));
        $this->assertSame(33, $svc->percent($emp));          // only contact of 3 groups done
        $this->assertFalse($svc->fullyComplete($emp));
        $this->assertSame(['identity', 'certs'], $svc->missing($emp));

        // Completing identity (gender + marital) closes that group.
        $emp->update(['gender' => 'male', 'marital_status' => 'single']);
        $emp->refresh();
        $this->assertSame(67, $svc->percent($emp));          // identity + contact of 3 groups
        $this->assertSame(['certs'], $svc->missing($emp));
    }

    // ── Profile-complete gate ──────────────────────────────────────────────────

    public function test_incomplete_staff_are_funnelled_to_the_wizard(): void
    {
        [$tenant] = $this->company(1);
        $this->launch($tenant);
        [$user] = $this->staff($tenant); // no essential fields

        $this->actingAs($user)->withSession(['current_tenant' => $tenant->id])
            ->get('/app/dash')->assertRedirect(route('welcome.show'));
    }

    public function test_staff_with_essentials_reach_the_app(): void
    {
        [$tenant] = $this->company(1);
        $this->launch($tenant);
        [$user] = $this->staff($tenant, $this->essentialAttrs());

        $this->actingAs($user)->withSession(['current_tenant' => $tenant->id])
            ->get('/app/dash')->assertOk();
    }

    public function test_wizard_itself_is_always_reachable_while_gated(): void
    {
        [$tenant] = $this->company(1);
        $this->launch($tenant);
        [$user] = $this->staff($tenant);

        $this->actingAs($user)->withSession(['current_tenant' => $tenant->id])
            ->get('/app/welcome')->assertOk();
    }

    // ── Launch lock ────────────────────────────────────────────────────────────

    public function test_staff_are_held_until_the_company_is_launched(): void
    {
        [$tenant] = $this->company(1); // not launched
        [$user] = $this->staff($tenant, $this->essentialAttrs());

        $this->actingAs($user)->withSession(['current_tenant' => $tenant->id])
            ->get('/app/dash')->assertStatus(423);
    }

    public function test_hr_bypasses_the_launch_lock(): void
    {
        [$tenant, $hr] = $this->company(1); // not launched

        $this->actingAs($hr)->withSession(['current_tenant' => $tenant->id])
            ->get('/app/setup')->assertOk();
    }

    public function test_staff_are_admitted_once_launched(): void
    {
        [$tenant] = $this->company(1);
        [$user] = $this->staff($tenant, $this->essentialAttrs());
        $this->launch($tenant);

        $this->actingAs($user)->withSession(['current_tenant' => $tenant->id])
            ->get('/app/dash')->assertOk();
    }

    // ── Wizard save endpoints ──────────────────────────────────────────────────

    public function test_save_personal_persists_to_the_own_record(): void
    {
        [$tenant] = $this->company(1);
        [$user, $emp] = $this->staff($tenant);

        $this->actingAs($user)->withSession(['current_tenant' => $tenant->id])
            ->post('/app/welcome/personal', array_merge($this->essentialAttrs(), ['gender' => 'male', 'marital_status' => 'single']))
            ->assertRedirect(route('welcome.show'));

        $emp->refresh();
        $this->assertSame('0123456789', $emp->phone);
        $this->assertSame('900101015555', $emp->nric); // decrypts via cast
        $this->assertNotSame('900101015555', $emp->getRawOriginal('nric')); // stored encrypted
    }

    public function test_bank_step_writes_encrypted_salary_structure_when_payroll_is_on(): void
    {
        [$tenant] = $this->company(2); // stage 2 → payroll on
        [$user, $emp] = $this->staff($tenant, $this->essentialAttrs());

        $this->actingAs($user)->withSession(['current_tenant' => $tenant->id])
            ->post('/app/welcome/bank', ['bank_name' => 'Maybank', 'bank_account_no' => '1234567890', 'epf_no' => 'E123', 'socso_no' => 'S123'])
            ->assertRedirect(route('welcome.show'));

        $s = SalaryStructure::where('employee_id', $emp->id)->firstOrFail();
        $this->assertSame('Maybank', $s->bank_name);
        $this->assertSame('900101015555', Crypt::decryptString($s->getRawOriginal('nric'))); // synced + encrypted
    }

    public function test_bank_step_404s_when_payroll_is_off(): void
    {
        [$tenant] = $this->company(1); // stage 1 → payroll off
        [$user] = $this->staff($tenant, $this->essentialAttrs());

        $this->actingAs($user)->withSession(['current_tenant' => $tenant->id])
            ->post('/app/welcome/bank', ['bank_name' => 'X', 'bank_account_no' => '1', 'epf_no' => 'E', 'socso_no' => 'S'])
            ->assertNotFound();
    }

    public function test_certificate_upload_creates_an_employee_document(): void
    {
        Storage::fake('local');
        [$tenant] = $this->company(1);
        [$user, $emp] = $this->staff($tenant);

        $this->actingAs($user)->withSession(['current_tenant' => $tenant->id])
            ->post('/app/welcome/certificate', ['title' => 'Degree', 'file' => UploadedFile::fake()->create('degree.pdf', 120, 'application/pdf')])
            ->assertRedirect(route('welcome.show'));

        $this->assertDatabaseHas('employee_documents', [
            'employee_id' => $emp->id, 'title' => 'Degree', 'category' => 'Certificate', 'uploaded_by_employee_id' => $emp->id,
        ]);
    }

    public function test_finish_requires_the_essential_tier(): void
    {
        [$tenant] = $this->company(1);
        [$user] = $this->staff($tenant); // essentials missing

        $this->actingAs($user)->withSession(['current_tenant' => $tenant->id])
            ->post('/app/welcome/finish')->assertRedirect(route('welcome.show'));
    }

    public function test_bank_step_refuses_before_personal_details_exist(): void
    {
        [$tenant] = $this->company(2); // payroll on
        [$user, $emp] = $this->staff($tenant); // no essentials → no NRIC yet

        $this->actingAs($user)->withSession(['current_tenant' => $tenant->id])
            ->post('/app/welcome/bank', ['bank_name' => 'Maybank', 'bank_account_no' => '1', 'epf_no' => 'E', 'socso_no' => 'S'])
            ->assertRedirect(route('welcome.show'));

        $this->assertDatabaseMissing('salary_structures', ['employee_id' => $emp->id]);
    }

    // ── The gates funnel screen navigation only — never write-paths ─────────────

    public function test_write_paths_are_never_gated_even_for_an_incomplete_staffer(): void
    {
        [$tenant] = $this->company(1); // enforced but NOT launched
        [$user] = $this->staff($tenant); // essentials missing

        // A daily-driver write path must not be funnelled to the wizard or held by the
        // launch lock — those gates live only on the screen catch-all.
        $resp = $this->actingAs($user)->withSession(['current_tenant' => $tenant->id])
            ->post('/app/notifications/read');

        $this->assertNotSame(423, $resp->status());                       // not the launch lock
        $this->assertNotSame(route('welcome.show'), $resp->headers->get('Location')); // not the profile gate
    }

    // ── Launch Center (setup) critical-domain detection ────────────────────────

    public function test_critical_done_flips_once_every_domain_is_configured(): void
    {
        [$tenant] = $this->company(1);
        app(CurrentTenant::class)->set($tenant);

        $this->assertFalse(app(SetupController::class)->criticalDone());

        $this->launch($tenant);
        $this->staff($tenant); // pushes active staff count > 1

        $this->assertTrue(app(SetupController::class)->criticalDone());
    }
}
