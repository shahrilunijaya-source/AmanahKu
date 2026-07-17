<?php

namespace Tests\Feature;

use App\Http\Controllers\ReferralController;
use App\Models\Employee;
use App\Models\Referral;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Feature coverage for the Employee referrals module.
 * Harness (setUp / actingInTenant / hrActor) copied from LoanTest.
 */
class ReferralTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Tenant $tenant;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create(['name' => 'Demo', 'email' => 'demo@example.com', 'password' => Hash::make('password')]);
        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        $this->user->tenants()->attach($this->tenant->id, ['role' => 'employee']);
        $this->employee = Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $this->user->id,
            'name' => 'Demo', 'status' => 'active', 'workload' => 'green',
        ]);
    }

    private function actingInTenant(): self
    {
        $this->actingAs($this->user)->withSession(['current_tenant' => $this->tenant->id]);

        return $this;
    }

    private function hrActor(): User
    {
        $hr = User::create(['name' => 'Boss', 'email' => 'boss@example.com', 'password' => Hash::make('password')]);
        $hr->tenants()->attach($this->tenant->id, ['role' => 'hr']);
        Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $hr->id,
            'name' => 'Boss', 'status' => 'active', 'workload' => 'green',
        ]);

        return $hr;
    }

    public function test_employee_submits_a_referral(): void
    {
        // Act
        $response = $this->actingInTenant()->post('/app/referrals', [
            'candidate_name' => 'Jane Doe',
            'candidate_email' => 'jane@example.com',
            'note' => 'Great engineer',
        ]);

        // Assert
        $response->assertRedirect();
        $this->assertDatabaseHas('referrals', [
            'tenant_id' => $this->tenant->id,
            'referrer_employee_id' => $this->employee->id,
            'candidate_name' => 'Jane Doe',
            'status' => 'submitted',
            'bonus_status' => 'none',
        ]);
    }

    public function test_referral_is_bound_to_the_submitting_employee(): void
    {
        // Arrange — a colleague the attacker might try to impersonate.
        $colleague = Employee::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Colleague', 'status' => 'active', 'workload' => 'green',
        ]);

        // Act — plain employee submits, also passing a colleague's id (ignored server-side).
        $this->actingInTenant()->post('/app/referrals', [
            'candidate_name' => 'Sam Smith',
            'candidate_email' => 'sam@example.com',
            'referrer_employee_id' => $colleague->id,
        ])->assertRedirect();

        // Assert — referrer is the submitter, never the spoofed id.
        $this->assertDatabaseHas('referrals', [
            'referrer_employee_id' => $this->employee->id,
            'candidate_name' => 'Sam Smith',
        ]);
        $this->assertDatabaseMissing('referrals', [
            'referrer_employee_id' => $colleague->id,
        ]);
    }

    public function test_validation_rejects_a_missing_candidate_email(): void
    {
        $this->actingInTenant()->post('/app/referrals', [
            'candidate_name' => 'No Email',
        ])->assertSessionHasErrors('candidate_email');
    }

    public function test_privileged_user_advances_status_and_sets_bonus(): void
    {
        // Arrange
        $hr = $this->hrActor();
        $referral = Referral::create([
            'tenant_id' => $this->tenant->id, 'referrer_employee_id' => $this->employee->id,
            'candidate_name' => 'Jane Doe', 'candidate_email' => 'jane@example.com', 'status' => 'submitted',
            'bonus_eligible' => false, 'bonus_status' => 'none',
        ]);

        // Act
        $response = $this->actingAs($hr)->withSession(['current_tenant' => $this->tenant->id])
            ->post("/app/referrals/{$referral->id}/status", [
                'status' => 'hired',
                'bonus_eligible' => '1',
                'bonus_status' => 'pending',
            ]);

        // Assert
        $response->assertRedirect();
        $fresh = $referral->fresh();
        $this->assertSame('hired', $fresh->status);
        $this->assertTrue($fresh->bonus_eligible);
        $this->assertSame('pending', $fresh->bonus_status);
        $this->assertNotNull($fresh->decided_at);
        $this->assertNotNull($fresh->decided_by_id);
    }

    public function test_moving_back_from_a_terminal_status_preserves_the_decision_audit(): void
    {
        // Arrange — a referral already decided as hired.
        $hr = $this->hrActor();
        $referral = Referral::create([
            'tenant_id' => $this->tenant->id, 'referrer_employee_id' => $this->employee->id,
            'candidate_name' => 'Jane Doe', 'candidate_email' => 'jane@example.com', 'status' => 'hired',
            'bonus_eligible' => true, 'bonus_status' => 'pending',
            'decided_at' => now()->subDay(), 'decided_by_id' => $this->employee->id,
        ]);
        $originalDecidedAt = $referral->decided_at;

        // Act — HR moves it back to reviewing.
        $this->actingAs($hr)->withSession(['current_tenant' => $this->tenant->id])
            ->post("/app/referrals/{$referral->id}/status", [
                'status' => 'reviewing',
                'bonus_eligible' => '1',
                'bonus_status' => 'pending',
            ])->assertRedirect();

        // Assert — status changes but the original decision audit is NOT erased.
        $fresh = $referral->fresh();
        $this->assertSame('reviewing', $fresh->status);
        $this->assertNotNull($fresh->decided_at);
        $this->assertSame($originalDecidedAt->toDateTimeString(), $fresh->decided_at->toDateTimeString());
        $this->assertSame($this->employee->id, $fresh->decided_by_id);
    }

    public function test_invalid_status_is_rejected(): void
    {
        // Arrange
        $hr = $this->hrActor();
        $referral = Referral::create([
            'tenant_id' => $this->tenant->id, 'referrer_employee_id' => $this->employee->id,
            'candidate_name' => 'Jane Doe', 'candidate_email' => 'jane@example.com', 'status' => 'submitted',
            'bonus_eligible' => false, 'bonus_status' => 'none',
        ]);

        // Act + Assert
        $this->actingAs($hr)->withSession(['current_tenant' => $this->tenant->id])
            ->post("/app/referrals/{$referral->id}/status", [
                'status' => 'nonsense',
                'bonus_status' => 'none',
            ])->assertSessionHasErrors('status');
    }

    public function test_plain_employee_cannot_set_status(): void
    {
        // Arrange
        $referral = Referral::create([
            'tenant_id' => $this->tenant->id, 'referrer_employee_id' => $this->employee->id,
            'candidate_name' => 'Jane Doe', 'candidate_email' => 'jane@example.com', 'status' => 'submitted',
            'bonus_eligible' => false, 'bonus_status' => 'none',
        ]);

        // Act
        $response = $this->actingInTenant()->post("/app/referrals/{$referral->id}/status", [
            'status' => 'hired',
            'bonus_status' => 'pending',
        ]);

        // Assert
        $response->assertForbidden();
        $this->assertSame('submitted', $referral->fresh()->status);
    }

    public function test_plain_employee_sees_only_their_own_referrals(): void
    {
        // Arrange — own referral plus a colleague's referral.
        $colleague = Employee::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Colleague', 'status' => 'active', 'workload' => 'green',
        ]);
        Referral::create([
            'tenant_id' => $this->tenant->id, 'referrer_employee_id' => $this->employee->id,
            'candidate_name' => 'Mine', 'candidate_email' => 'mine@example.com', 'status' => 'submitted',
            'bonus_eligible' => false, 'bonus_status' => 'none',
        ]);
        Referral::create([
            'tenant_id' => $this->tenant->id, 'referrer_employee_id' => $colleague->id,
            'candidate_name' => 'Theirs', 'candidate_email' => 'theirs@example.com', 'status' => 'submitted',
            'bonus_eligible' => false, 'bonus_status' => 'none',
        ]);

        // Act — build screenData as a plain (non-privileged) employee within the tenant.
        $request = Request::create('/app/referrals', 'GET');
        $request->setUserResolver(fn () => $this->user);
        $request->attributes->set('tenantRole', 'employee');
        app(CurrentTenant::class)->set($this->tenant);

        $data = (new ReferralController)->screenData($request, $this->employee);

        // Assert — only the submitter's referral is visible, the privileged feed is empty.
        $this->assertCount(1, $data['myReferrals']);
        $this->assertSame('Mine', $data['myReferrals']->first()->candidate_name);
        $this->assertCount(0, $data['allReferrals']);
    }
}
