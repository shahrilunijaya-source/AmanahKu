<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Coverage for the token-authed REST API v1 (bucket C4).
 *
 * Exercises the full bearer-token stack (auth:sanctum + api.tenant) rather than
 * actingAs, so tenant isolation and role enforcement are tested end to end. Two
 * tenants are built so a token for tenant A is proven blind to tenant B.
 */
class ApiTokenTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenantA;

    private Tenant $tenantB;

    private User $hrA;        // privileged on tenant A

    private User $staffA;     // plain employee on tenant A

    private Employee $staffEmpA;

    private User $hrB;        // privileged on tenant B

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantA = Tenant::create(['slug' => 'alpha', 'name' => 'Alpha', 'initials' => 'AL']);
        $this->tenantB = Tenant::create(['slug' => 'beta', 'name' => 'Beta', 'initials' => 'BE']);

        // --- Tenant A: an HR user, a plain employee, leave + payslip data ---
        app(CurrentTenant::class)->set($this->tenantA);

        $this->hrA = User::create(['name' => 'HR Ann', 'email' => 'hr.a@example.com', 'password' => Hash::make('password')]);
        $this->hrA->tenants()->attach($this->tenantA->id, ['role' => 'hr']);
        Employee::create(['tenant_id' => $this->tenantA->id, 'user_id' => $this->hrA->id, 'name' => 'HR Ann', 'status' => 'active', 'workload' => 'green']);

        $this->staffA = User::create(['name' => 'Staff Sam', 'email' => 'staff.a@example.com', 'password' => Hash::make('password')]);
        $this->staffA->tenants()->attach($this->tenantA->id, ['role' => 'employee']);
        $this->staffEmpA = Employee::create(['tenant_id' => $this->tenantA->id, 'user_id' => $this->staffA->id, 'name' => 'Staff Sam', 'status' => 'active', 'workload' => 'green']);

        $otherEmpA = Employee::create(['tenant_id' => $this->tenantA->id, 'name' => 'Other Omar', 'status' => 'active', 'workload' => 'green']);

        $leaveType = LeaveType::create(['tenant_id' => $this->tenantA->id, 'name' => 'Annual', 'entitlement' => 18]);

        // One leave request for staff Sam, one for someone else.
        LeaveRequest::create([
            'tenant_id' => $this->tenantA->id, 'employee_id' => $this->staffEmpA->id, 'leave_type_id' => $leaveType->id,
            'date_from' => '2026-07-01', 'date_to' => '2026-07-02', 'days' => 2, 'status' => 'submitted',
        ]);
        LeaveRequest::create([
            'tenant_id' => $this->tenantA->id, 'employee_id' => $otherEmpA->id, 'leave_type_id' => $leaveType->id,
            'date_from' => '2026-07-05', 'date_to' => '2026-07-06', 'days' => 2, 'status' => 'submitted',
        ]);

        // A finalized run + payslip for Sam (visible to him), and a draft run + payslip
        // for the other employee (only privileged can see it).
        $finalRun = PayrollRun::create(['tenant_id' => $this->tenantA->id, 'period' => '2026-05']);
        $finalRun->forceFill(['status' => 'finalized'])->save();
        $this->makePayslip($finalRun, $this->staffEmpA);

        $draftRun = PayrollRun::create(['tenant_id' => $this->tenantA->id, 'period' => '2026-06']);
        $draftRun->forceFill(['status' => 'draft'])->save();
        $this->makePayslip($draftRun, $otherEmpA);

        // --- Tenant B: an HR user with its own employee (should never leak into A) ---
        app(CurrentTenant::class)->set($this->tenantB);

        $this->hrB = User::create(['name' => 'HR Bea', 'email' => 'hr.b@example.com', 'password' => Hash::make('password')]);
        $this->hrB->tenants()->attach($this->tenantB->id, ['role' => 'hr']);
        Employee::create(['tenant_id' => $this->tenantB->id, 'user_id' => $this->hrB->id, 'name' => 'HR Bea', 'status' => 'active', 'workload' => 'green']);
        Employee::create(['tenant_id' => $this->tenantB->id, 'name' => 'Beta Person', 'status' => 'active', 'workload' => 'green']);

        app(CurrentTenant::class)->set(null);
    }

    protected function tearDown(): void
    {
        app(CurrentTenant::class)->set(null);
        parent::tearDown();
    }

    /** Create a payslip the way the controller does: payroll_run_id is not mass-assignable. */
    private function makePayslip(PayrollRun $run, Employee $employee): Payslip
    {
        $payslip = new Payslip(['employee_id' => $employee->id]);
        $payslip->forceFill(['tenant_id' => $run->tenant_id, 'payroll_run_id' => $run->id])->save();

        return $payslip;
    }

    /** Mint a tenant-bound token and return the Bearer auth header array. */
    private function bearer(User $user, Tenant $tenant): array
    {
        $token = $user->mintApiToken($tenant, 'test');

        return ['Authorization' => 'Bearer '.$token->plainTextToken];
    }

    public function test_missing_token_is_unauthorized(): void
    {
        $this->getJson('/api/v1/employees')->assertUnauthorized();
    }

    public function test_invalid_token_is_unauthorized(): void
    {
        $this->getJson('/api/v1/employees', ['Authorization' => 'Bearer not-a-real-token'])
            ->assertUnauthorized();
    }

    public function test_privileged_token_lists_tenant_employees(): void
    {
        $response = $this->getJson('/api/v1/employees', $this->bearer($this->hrA, $this->tenantA));

        $response->assertOk()->assertJsonPath('error', null);
        // Tenant A has 3 employees (HR Ann, Staff Sam, Other Omar); none from tenant B.
        $this->assertCount(3, $response->json('data'));
        $names = collect($response->json('data'))->pluck('name')->all();
        $this->assertContains('Staff Sam', $names);
        $this->assertNotContains('Beta Person', $names);
    }

    public function test_non_privileged_cannot_list_employees(): void
    {
        $this->getJson('/api/v1/employees', $this->bearer($this->staffA, $this->tenantA))
            ->assertForbidden()
            ->assertJsonPath('data', null);
    }

    public function test_token_for_tenant_a_cannot_read_tenant_b_data(): void
    {
        // HR Bea is privileged on B; her token must only ever return B's employees.
        $response = $this->getJson('/api/v1/employees', $this->bearer($this->hrB, $this->tenantB));

        $response->assertOk();
        $names = collect($response->json('data'))->pluck('name')->all();
        $this->assertContains('Beta Person', $names);
        $this->assertNotContains('Staff Sam', $names);
        $this->assertNotContains('HR Ann', $names);
    }

    public function test_non_privileged_sees_only_own_leave_requests(): void
    {
        $response = $this->getJson('/api/v1/leave-requests', $this->bearer($this->staffA, $this->tenantA));

        $response->assertOk();
        // Sam has exactly one of the two tenant-A leave requests.
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('Staff Sam', $response->json('data.0.employee'));
    }

    public function test_privileged_sees_all_leave_requests(): void
    {
        $response = $this->getJson('/api/v1/leave-requests', $this->bearer($this->hrA, $this->tenantA));

        $response->assertOk();
        $this->assertCount(2, $response->json('data'));
    }

    public function test_non_privileged_sees_only_own_finalized_payslips(): void
    {
        $response = $this->getJson('/api/v1/payslips', $this->bearer($this->staffA, $this->tenantA));

        $response->assertOk();
        // Sam's one payslip is from a finalized run; the other employee's draft payslip is hidden.
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('Staff Sam', $response->json('data.0.employee'));
        $this->assertSame('finalized', $response->json('data.0.run_status'));
    }

    public function test_privileged_payslips_are_finalized_only_never_drafts(): void
    {
        // Security: the read API only ever exposes FINALIZED payslips. Even an HR
        // token must not pull draft / pre-approval payslip amounts through the API.
        $response = $this->getJson('/api/v1/payslips', $this->bearer($this->hrA, $this->tenantA));

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('finalized', $response->json('data.0.run_status'));
    }

    public function test_archived_employee_is_hidden_from_directory_but_still_named_on_history(): void
    {
        app(CurrentTenant::class)->set($this->tenantA);
        Employee::where('name', 'Other Omar')->update(['archived_at' => now()]);
        app(CurrentTenant::class)->set(null);

        // Directory listing (active-intent) drops the archived employee.
        $list = $this->getJson('/api/v1/employees', $this->bearer($this->hrA, $this->tenantA));
        $list->assertOk();
        $names = collect($list->json('data'))->pluck('name')->all();
        $this->assertNotContains('Other Omar', $names);
        $this->assertCount(2, $list->json('data'));

        // History (their leave request) still resolves the archived owner's name (fail-safe).
        $leave = $this->getJson('/api/v1/leave-requests', $this->bearer($this->hrA, $this->tenantA));
        $leave->assertOk();
        $this->assertContains('Other Omar', collect($leave->json('data'))->pluck('employee')->all());
    }

    public function test_command_mints_a_working_tenant_bound_token(): void
    {
        $this->artisan('api:token', ['user_email' => 'hr.a@example.com', 'tenant_slug' => 'alpha'])
            ->assertSuccessful();

        // A token row was created, bound to tenant A.
        $token = \App\Models\PersonalAccessToken::where('tokenable_id', $this->hrA->id)->latest('id')->first();
        $this->assertNotNull($token);
        $this->assertSame($this->tenantA->id, $token->tenant_id);
    }

    public function test_command_refuses_a_tenant_the_user_does_not_belong_to(): void
    {
        // staffA belongs to tenant A only — minting for tenant B must fail.
        $this->artisan('api:token', ['user_email' => 'staff.a@example.com', 'tenant_slug' => 'beta'])
            ->assertFailed();

        $this->assertSame(0, \App\Models\PersonalAccessToken::where('tokenable_id', $this->staffA->id)->count());
    }
}
