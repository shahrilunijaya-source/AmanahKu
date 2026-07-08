<?php

namespace Tests\Feature;

use App\Models\Claim;
use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use App\Services\FeatureManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Two-step claim approval routed by the org chart: immediate superior verifies, management
 * approves. Same RoutesApprovalsByReportingLine trait as leave and overtime.
 */
class ClaimApprovalRoutingTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
    }

    private function member(string $role, string $name, ?int $reportsToId = null): Employee
    {
        $this->seq++;
        $user = User::create(['name' => $name, 'email' => "user{$this->seq}@example.com", 'password' => Hash::make('password')]);
        $user->tenants()->attach($this->tenant->id, ['role' => $role]);

        return Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $user->id,
            'name' => $name, 'status' => 'active', 'workload' => 'green',
            'reports_to_id' => $reportsToId,
        ]);
    }

    private function actingAsEmployee(Employee $e): self
    {
        $this->actingAs($e->user)->withSession(['current_tenant' => $this->tenant->id]);

        return $this;
    }

    private function claim(Employee $employee, string $status = 'submitted', ?int $verifiedById = null, string $title = 'Mileage'): Claim
    {
        return Claim::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $employee->id,
            'type' => 'expense', 'title' => $title, 'amount' => 120.50, 'date' => '2026-06-20',
            'status' => $status, 'verified_by_id' => $verifiedById,
        ]);
    }

    public function test_immediate_superior_can_verify(): void
    {
        $manager = $this->member('manager', 'Manager');
        $report = $this->member('employee', 'Reportee', $manager->id);
        $claim = $this->claim($report);

        $this->actingAsEmployee($manager)->post("/app/claims/{$claim->id}/verify")->assertRedirect();

        $fresh = $claim->fresh();
        $this->assertSame('verified', $fresh->status);
        $this->assertSame($manager->id, $fresh->verified_by_id);
    }

    public function test_a_non_superior_cannot_verify(): void
    {
        $manager = $this->member('manager', 'Manager');
        $report = $this->member('employee', 'Reportee', $manager->id);
        $other = $this->member('manager', 'Other Manager');
        $claim = $this->claim($report);

        $this->actingAsEmployee($other)->post("/app/claims/{$claim->id}/verify")->assertForbidden();
        $this->assertSame('submitted', $claim->fresh()->status);
    }

    public function test_management_approves_a_verified_claim(): void
    {
        $manager = $this->member('manager', 'Manager');
        $mgmt = $this->member('management', 'Director');
        $report = $this->member('employee', 'Reportee', $manager->id);
        $claim = $this->claim($report, 'verified', $manager->id);

        $this->actingAsEmployee($mgmt)->post("/app/claims/{$claim->id}/approve")->assertRedirect();
        $this->assertSame('approved', $claim->fresh()->status);
    }

    public function test_management_cannot_approve_an_unverified_claim(): void
    {
        $mgmt = $this->member('management', 'Director');
        $report = $this->member('employee', 'Reportee');
        $claim = $this->claim($report); // submitted

        $this->actingAsEmployee($mgmt)->post("/app/claims/{$claim->id}/approve")->assertStatus(422);
        $this->assertSame('submitted', $claim->fresh()->status);
    }

    public function test_a_manager_cannot_give_final_approval(): void
    {
        $manager = $this->member('manager', 'Manager');
        $report = $this->member('employee', 'Reportee', $manager->id);
        $claim = $this->claim($report, 'verified', $manager->id);

        $this->actingAsEmployee($manager)->post("/app/claims/{$claim->id}/approve")->assertForbidden();
        $this->assertSame('verified', $claim->fresh()->status);
    }

    public function test_verify_queue_and_approve_queue_are_routed(): void
    {
        $manager = $this->member('manager', 'Manager');
        $mgmt = $this->member('management', 'Director');
        $toVerify = $this->member('employee', 'Verify Me', $manager->id);
        $verified = $this->member('employee', 'Approve Me', $manager->id);
        $this->claim($toVerify, 'submitted', null, 'Verify Claim');
        $this->claim($verified, 'verified', $manager->id, 'Approve Claim');

        // The superior sees the submitted one to verify.
        $this->actingAsEmployee($manager)->get('/app/claims')->assertOk()
            ->assertSee('To verify')->assertSee('Verify Me');

        // Management sees the verified one to approve.
        $this->actingAsEmployee($mgmt)->get('/app/claims')->assertOk()
            ->assertSee('To approve')->assertSee('Approve Me');
    }

    public function test_submitting_a_claim_notifies_the_superior(): void
    {
        Storage::fake('local');
        $manager = $this->member('manager', 'Manager');
        $report = $this->member('employee', 'Reportee', $manager->id);

        $this->actingAsEmployee($report)->post('/app/claims', [
            'type' => 'expense', 'title' => 'Taxi', 'amount' => 30, 'date' => '2026-06-21',
            'receipt' => UploadedFile::fake()->create('receipt.pdf', 40, 'application/pdf'),
        ])->assertRedirect();

        $this->assertDatabaseHas('app_notifications', [
            'user_id' => $manager->user_id, 'title' => 'Claim awaiting your verification',
        ]);
        $claim = Claim::where('title', 'Taxi')->firstOrFail();
        $this->assertNotNull($claim->receipt_path);
        Storage::disk('local')->assertExists($claim->receipt_path);
    }

    public function test_expense_claim_requires_a_receipt(): void
    {
        $manager = $this->member('manager', 'Manager');
        $report = $this->member('employee', 'Reportee', $manager->id);

        $this->actingAsEmployee($report)->post('/app/claims', [
            'type' => 'expense', 'title' => 'No proof', 'amount' => 30, 'date' => '2026-06-21',
        ])->assertSessionHasErrors('receipt');

        $this->assertDatabaseMissing('claims', ['title' => 'No proof']);
    }

    public function test_mileage_claim_submits_without_a_receipt(): void
    {
        $manager = $this->member('manager', 'Manager');
        $report = $this->member('employee', 'Reportee', $manager->id);

        $this->actingAsEmployee($report)->post('/app/claims', [
            'type' => 'mileage', 'title' => 'Client run', 'amount' => 30, 'date' => '2026-06-21',
        ])->assertRedirect();

        $this->assertDatabaseHas('claims', ['title' => 'Client run', 'receipt_path' => null]);
    }

    public function test_medical_claims_are_capped_at_the_annual_limit(): void
    {
        Storage::fake('local');
        app(FeatureManager::class)->setTenant($this->tenant, 'claims.medical_cap', 500);
        $manager = $this->member('manager', 'Manager');
        $report = $this->member('employee', 'Reportee', $manager->id);
        $receipt = fn () => UploadedFile::fake()->create('r.pdf', 20, 'application/pdf');

        // RM400 medical in 2026 — under the cap, accepted.
        $this->actingAsEmployee($report)->post('/app/claims', [
            'type' => 'medical', 'title' => 'GP', 'amount' => 400, 'date' => '2026-03-01', 'receipt' => $receipt(),
        ])->assertRedirect()->assertSessionHasNoErrors();

        // A further RM150 the same year would total RM550 > RM500 — rejected, not stored.
        $this->actingAsEmployee($report)->post('/app/claims', [
            'type' => 'medical', 'title' => 'Specialist', 'amount' => 150, 'date' => '2026-09-01', 'receipt' => $receipt(),
        ])->assertSessionHasErrors('amount');
        $this->assertDatabaseMissing('claims', ['title' => 'Specialist']);

        // The cap is per year: the same RM150 in 2027 is fine.
        $this->actingAsEmployee($report)->post('/app/claims', [
            'type' => 'medical', 'title' => 'Next year', 'amount' => 150, 'date' => '2027-01-05', 'receipt' => $receipt(),
        ])->assertRedirect()->assertSessionHasNoErrors();

        // A rejected claim frees its allowance back up.
        $rejected = $this->claim($report, 'rejected', null, 'Voided');
        $rejected->update(['type' => 'medical', 'amount' => 500, 'date' => '2026-06-01']);
        $this->actingAsEmployee($report)->post('/app/claims', [
            'type' => 'medical', 'title' => 'After void', 'amount' => 100, 'date' => '2026-07-01', 'receipt' => $receipt(),
        ])->assertRedirect()->assertSessionHasNoErrors();
    }

    public function test_medical_cap_is_configurable_per_tenant(): void
    {
        Storage::fake('local');
        $manager = $this->member('manager', 'Manager');
        $report = $this->member('employee', 'Reportee', $manager->id);
        $receipt = fn () => UploadedFile::fake()->create('r.pdf', 20, 'application/pdf');

        // Tighten the cap for this company to RM200.
        app(FeatureManager::class)->setTenant($this->tenant, 'claims.medical_cap', 200);

        // RM250 now exceeds the lowered cap — rejected.
        $this->actingAsEmployee($report)->post('/app/claims', [
            'type' => 'medical', 'title' => 'Over lowered cap', 'amount' => 250, 'date' => '2026-04-01', 'receipt' => $receipt(),
        ])->assertSessionHasErrors('amount');
        $this->assertDatabaseMissing('claims', ['title' => 'Over lowered cap']);

        // Raise it to RM1000 — the same RM250 now fits.
        app(FeatureManager::class)->setTenant($this->tenant, 'claims.medical_cap', 1000);
        $this->actingAsEmployee($report)->post('/app/claims', [
            'type' => 'medical', 'title' => 'Under raised cap', 'amount' => 250, 'date' => '2026-04-01', 'receipt' => $receipt(),
        ])->assertRedirect()->assertSessionHasNoErrors();
    }

    public function test_receipt_download_is_gated_to_claimant_superior_and_management(): void
    {
        Storage::fake('local');
        $manager = $this->member('manager', 'Manager');
        $mgmt = $this->member('management', 'Director');
        $report = $this->member('employee', 'Reportee', $manager->id);
        $outsider = $this->member('employee', 'Nosy');

        $this->actingAsEmployee($report)->post('/app/claims', [
            'type' => 'expense', 'title' => 'Lunch', 'amount' => 30, 'date' => '2026-06-21',
            'receipt' => UploadedFile::fake()->create('receipt.pdf', 40, 'application/pdf'),
        ])->assertRedirect();
        $claim = Claim::where('title', 'Lunch')->firstOrFail();

        $this->actingAsEmployee($report)->get("/app/claims/{$claim->id}/receipt")->assertOk();
        $this->actingAsEmployee($manager)->get("/app/claims/{$claim->id}/receipt")->assertOk();
        $this->actingAsEmployee($mgmt)->get("/app/claims/{$claim->id}/receipt")->assertOk();
        $this->actingAsEmployee($outsider)->get("/app/claims/{$claim->id}/receipt")->assertForbidden();
    }

    // --- Reject (either stage) -----------------------------------------------

    public function test_superior_rejects_a_submitted_claim_and_the_requester_is_notified(): void
    {
        $manager = $this->member('manager', 'Manager');
        $report = $this->member('employee', 'Reportee', $manager->id);
        $claim = $this->claim($report);

        $this->actingAsEmployee($manager)->post("/app/claims/{$claim->id}/reject")
            ->assertRedirect()->assertSessionHas('ok');

        $this->assertSame('rejected', $claim->fresh()->status);
        $this->assertDatabaseHas('app_notifications', [
            'user_id' => $report->user_id, 'title' => 'Claim declined',
        ]);
    }

    public function test_management_rejects_a_verified_claim(): void
    {
        $manager = $this->member('manager', 'Manager');
        $mgmt = $this->member('management', 'Director');
        $report = $this->member('employee', 'Reportee', $manager->id);
        $claim = $this->claim($report, 'verified', $manager->id);

        $this->actingAsEmployee($mgmt)->post("/app/claims/{$claim->id}/reject")->assertRedirect();
        $this->assertSame('rejected', $claim->fresh()->status);
    }

    public function test_a_non_superior_manager_cannot_reject_a_submitted_claim(): void
    {
        $manager = $this->member('manager', 'Manager');
        $other = $this->member('manager', 'Other Manager');
        $report = $this->member('employee', 'Reportee', $manager->id);
        $claim = $this->claim($report);

        $this->actingAsEmployee($other)->post("/app/claims/{$claim->id}/reject")->assertForbidden();
        $this->assertSame('submitted', $claim->fresh()->status);
    }

    public function test_an_approved_claim_cannot_be_rejected(): void
    {
        $manager = $this->member('manager', 'Manager');
        $mgmt = $this->member('management', 'Director');
        $report = $this->member('employee', 'Reportee', $manager->id);
        $claim = $this->claim($report, 'approved', $manager->id);

        $this->actingAsEmployee($mgmt)->post("/app/claims/{$claim->id}/reject")->assertStatus(422);
        $this->assertSame('approved', $claim->fresh()->status);
    }
}
