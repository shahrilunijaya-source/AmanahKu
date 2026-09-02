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

        // The superior sees the submitted one in their "verify" queue.
        $this->actingAsEmployee($manager)->get('/app/claim-approvals')->assertOk()
            ->assertSee('Yours to verify')->assertSee('Verify Me');

        // Management sees the verified one in their "final approval" queue.
        $this->actingAsEmployee($mgmt)->get('/app/claim-approvals')->assertOk()
            ->assertSee('Waiting for final approval')->assertSee('Approve Me');
    }

    public function test_reviewer_with_both_steps_sees_both_review_queues(): void
    {
        // The revamped screen splits review into two Leave-style queues (verify / final
        // approval) toggled by a chip bar, replacing the old single merged queue. A person
        // who is both a direct superior and management therefore holds items in both, and
        // both queues — and the toggle between them — must render.
        $mgmt = $this->member('management', 'Director');
        $report = $this->member('employee', 'Reportee', $mgmt->id); // mgmt is also report's direct superior
        $approveOwner = $this->member('employee', 'Approve Owner');
        $this->claim($report, 'submitted', null, 'Verify Row');
        $this->claim($approveOwner, 'verified', $mgmt->id, 'Approve Row');

        $this->actingAsEmployee($mgmt)->get('/app/claim-approvals')->assertOk()
            ->assertSee('Yours to verify')->assertSee('Verify Row')
            ->assertSee('Final approval')->assertSee('Approve Row');
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

    // --- Unified claims screen: role-aware tabs (My claims / Approvals / All claims) -----

    public function test_plain_employee_claims_screen_has_no_queues_or_company_data(): void
    {
        $employee = $this->member('employee', 'Plain Employee');
        $this->claim($employee); // their own submitted claim shows under My claims, nothing else

        $this->actingAsEmployee($employee)->get('/app/claims')->assertOk()
            ->assertViewHas('isApprover', false)
            ->assertViewHas('privileged', false)
            ->assertViewMissing('claimTotals')
            ->assertViewMissing('allClaims')
            ->assertViewMissing('claimsToVerify')
            ->assertViewMissing('claimsToApprove')
            ->assertDontSee('Company claims')
            ->assertDontSee('All claims');
    }

    public function test_manager_claims_screen_has_the_approvals_queue_but_no_company_ledger(): void
    {
        $manager = $this->member('manager', 'Manager');
        $report = $this->member('employee', 'Reportee', $manager->id);
        $this->claim($report); // submitted — sits in the manager's verify queue

        $this->actingAsEmployee($manager)->get('/app/claims')->assertOk()
            ->assertViewHas('isApprover', true)
            ->assertViewHas('privileged', false)
            ->assertViewHas('claimsToVerify')
            ->assertViewMissing('claimTotals')
            ->assertViewMissing('allClaims')
            ->assertSee('Yours to verify')
            ->assertDontSee('Company claims')
            ->assertDontSee('All claims');
    }

    public function test_the_tab_is_named_for_what_the_viewer_can_actually_do(): void
    {
        // A plain manager only recommends — scopeToApprove() closes for them — so calling
        // their tab "Approvals" would promise a power they do not have.
        $manager = $this->member('manager', 'Manager');
        $report = $this->member('employee', 'Reportee', $manager->id);
        $this->claim($report);

        $this->actingAsEmployee($manager)->get('/app/claims')->assertOk()
            ->assertViewHas('givesFinalApproval', false)
            ->assertSee('To verify');

        // A director signs off, so theirs keeps the stronger word.
        $mgmt = $this->member('management', 'Director');

        $this->actingAsEmployee($mgmt)->get('/app/claims')->assertOk()
            ->assertViewHas('givesFinalApproval', true)
            ->assertSee('Approvals');
    }

    public function test_management_claims_screen_exposes_the_company_ledger_tab(): void
    {
        $mgmt = $this->member('management', 'Director');
        $someone = $this->member('employee', 'Someone');
        $this->claim($someone);

        $this->actingAsEmployee($mgmt)->get('/app/claims')->assertOk()
            ->assertViewHas('isApprover', true)
            ->assertViewHas('privileged', true)
            ->assertSee('All claims')
            ->assertSee('Company claims');
    }

    public function test_management_sees_the_company_wide_claims_section(): void
    {
        $mgmt = $this->member('management', 'Director');
        $someone = $this->member('employee', 'Someone');
        $this->claim($someone);

        $this->actingAsEmployee($mgmt)->get('/app/claim-approvals')->assertOk()
            ->assertViewHas('privileged', true)
            ->assertSee('All claims')
            ->assertSee('Company claims');
    }

    public function test_hr_sees_the_company_wide_claims_section(): void
    {
        $hr = $this->member('hr', 'HR Person');
        $someone = $this->member('employee', 'Someone');
        $this->claim($someone);

        $this->actingAsEmployee($hr)->get('/app/claim-approvals')->assertOk()
            ->assertViewHas('privileged', true)
            ->assertSee('All claims')
            ->assertSee('Company claims');
    }

    public function test_employee_and_manager_do_not_see_the_company_wide_claims_section(): void
    {
        $manager = $this->member('manager', 'Manager');
        $employee = $this->member('employee', 'Plain Employee', $manager->id);
        $this->claim($employee);

        $this->actingAsEmployee($manager)->get('/app/claim-approvals')->assertOk()
            ->assertViewHas('privileged', false)
            ->assertDontSee('All claims');

        $this->actingAsEmployee($employee)->get('/app/claim-approvals')->assertOk()
            ->assertViewHas('privileged', false)
            ->assertDontSee('All claims');
    }

    public function test_plain_employee_sees_no_queue_and_no_ledger_on_claim_approvals(): void
    {
        $employee = $this->member('employee', 'Plain Employee');

        // The legacy claim-approvals slug still resolves (unified claims screen). A plain
        // employee is not an approver, so they get neither the Approvals tab nor the ledger.
        $this->actingAsEmployee($employee)->get('/app/claim-approvals')->assertOk()
            ->assertViewHas('isApprover', false)
            ->assertViewHas('privileged', false)
            ->assertDontSee('All claims')
            ->assertDontSee('Company claims');
    }

    public function test_personal_tiles_show_only_the_viewers_own_claims_not_company_total(): void
    {
        $mgmt = $this->member('management', 'Director');
        $other = $this->member('employee', 'Other Person');

        $this->claim($mgmt, 'submitted', null, 'Mine');
        $this->claim($other, 'submitted', null, 'Not mine');

        $response = $this->actingAsEmployee($mgmt)->get('/app/claims')->assertOk();

        // "My claims" is the director's own claim only (1), not the company total (2).
        $this->assertCount(1, $response->viewData('myClaims'));
    }

    public function test_claims_screen_renders_the_compact_approval_chain_without_the_tall_heading(): void
    {
        $manager = $this->member('manager', 'Manager');
        $mgmt = $this->member('management', 'Director');
        $report = $this->member('employee', 'Reportee', $manager->id);

        $this->actingAsEmployee($report)->get('/app/claims')->assertOk()
            ->assertSee('Manager')
            ->assertSee('Director')
            ->assertDontSee('Who signs off your request');
    }

    // --- HR skips the verify step (reports straight to the directors) --------

    public function test_an_hr_claim_opens_pre_verified_and_goes_to_the_directors(): void
    {
        $director = $this->member('director', 'Shahril');
        $hr = $this->member('hr', 'HR Officer');

        $this->actingAsEmployee($hr)->post('/app/claims', [
            'type' => 'mileage', 'title' => 'HR Run', 'amount' => 40, 'date' => '2026-06-21',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $claim = Claim::where('title', 'HR Run')->firstOrFail();
        $this->assertSame('verified', $claim->status);
        $this->assertNull($claim->verified_by_id, 'No verifier is recorded when the step is skipped.');
        $this->assertNotNull($claim->verified_at);

        // The directors are asked to approve; the HR requester is not pinged about their own.
        $this->assertDatabaseHas('app_notifications', [
            'user_id' => $director->user_id, 'title' => 'Claim awaiting final approval',
        ]);
        $this->assertDatabaseMissing('app_notifications', ['user_id' => $hr->user_id]);
    }

    public function test_hr_gives_final_approval_on_someone_elses_verified_claim(): void
    {
        $manager = $this->member('manager', 'Manager');
        $hr = $this->member('hr', 'HR Officer');
        $report = $this->member('employee', 'Reportee', $manager->id);
        $claim = $this->claim($report, 'verified', $manager->id);

        $this->actingAsEmployee($hr)->post("/app/claims/{$claim->id}/approve")->assertRedirect();
        $this->assertSame('approved', $claim->fresh()->status);
    }

    public function test_hr_cannot_approve_their_own_pre_verified_claim(): void
    {
        $hr = $this->member('hr', 'HR Officer');
        $claim = $this->claim($hr, 'verified');

        $this->actingAsEmployee($hr)->post("/app/claims/{$claim->id}/approve")->assertForbidden();
        $this->assertSame('verified', $claim->fresh()->status);
    }

    // --- Cancel (the claimant withdraws) ------------------------------------

    public function test_claimant_cancels_their_own_pending_claim(): void
    {
        $manager = $this->member('manager', 'Manager');
        $report = $this->member('employee', 'Reportee', $manager->id);
        $claim = $this->claim($report);

        $this->actingAsEmployee($report)->post("/app/claims/{$claim->id}/cancel")
            ->assertRedirect()->assertSessionHas('ok');

        $this->assertSame('cancelled', $claim->fresh()->status);
    }

    public function test_claimant_cancels_their_own_approved_claim(): void
    {
        $manager = $this->member('manager', 'Manager');
        $report = $this->member('employee', 'Reportee', $manager->id);
        $claim = $this->claim($report, 'approved', $manager->id);

        $this->actingAsEmployee($report)->post("/app/claims/{$claim->id}/cancel")
            ->assertRedirect()->assertSessionHas('ok');

        $this->assertSame('cancelled', $claim->fresh()->status);
    }

    public function test_a_paid_claim_cannot_be_cancelled(): void
    {
        $manager = $this->member('manager', 'Manager');
        $report = $this->member('employee', 'Reportee', $manager->id);
        $claim = $this->claim($report, 'paid', $manager->id);
        $claim->update(['paid_at' => now()]);

        $this->actingAsEmployee($report)->post("/app/claims/{$claim->id}/cancel")->assertStatus(422);
        $this->assertSame('paid', $claim->fresh()->status);
    }

    public function test_claims_screen_shows_cancel_for_approved_but_not_paid_claims(): void
    {
        $manager = $this->member('manager', 'Manager');
        $report = $this->member('employee', 'Reportee', $manager->id);
        $this->claim($report, 'approved', $manager->id, 'Cancellable');
        $this->claim($report, 'paid', $manager->id, 'Already paid')->update(['paid_at' => now()]);

        $html = $this->actingAsEmployee($report)->get('/app/claims')->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, '/cancel"'));
    }

    public function test_nobody_else_can_cancel_someone_elses_claim(): void
    {
        $manager = $this->member('manager', 'Manager');
        $report = $this->member('employee', 'Reportee', $manager->id);
        $claim = $this->claim($report);

        $this->actingAsEmployee($manager)->post("/app/claims/{$claim->id}/cancel")->assertForbidden();
        $this->assertSame('submitted', $claim->fresh()->status);
    }

    public function test_a_cancelled_medical_claim_frees_its_annual_allowance(): void
    {
        Storage::fake('local');
        app(FeatureManager::class)->setTenant($this->tenant, 'claims.medical_cap', 500);
        $manager = $this->member('manager', 'Manager');
        $report = $this->member('employee', 'Reportee', $manager->id);

        $spent = $this->claim($report, 'submitted', null, 'GP');
        $spent->update(['type' => 'medical', 'amount' => 500, 'date' => '2026-03-01']);

        $this->actingAsEmployee($report)->post("/app/claims/{$spent->id}/cancel")->assertRedirect();

        $this->actingAsEmployee($report)->post('/app/claims', [
            'type' => 'medical', 'title' => 'After cancel', 'amount' => 400, 'date' => '2026-07-01',
            'receipt' => UploadedFile::fake()->create('r.pdf', 20, 'application/pdf'),
        ])->assertRedirect()->assertSessionHasNoErrors();
    }

    /**
     * The decision trail added 2026_09_02. Without it the approver's own history has
     * nothing to match on and every count reads zero forever.
     */
    public function test_approving_a_claim_records_who_approved_it_and_when(): void
    {
        $mgmt = $this->member('management', 'Director');
        $manager = $this->member('manager', 'Manager');
        $claim = $this->claim($manager, 'verified', $manager->id);

        $this->actingAsEmployee($mgmt)->post("/app/claims/{$claim->id}/approve")->assertRedirect();

        $fresh = $claim->fresh();
        $this->assertSame('approved', $fresh->status);
        $this->assertSame($mgmt->id, $fresh->approved_by_id);
        $this->assertNotNull($fresh->approved_at);
    }

    public function test_rejecting_a_claim_records_who_rejected_it_and_when(): void
    {
        $mgmt = $this->member('management', 'Director');
        $manager = $this->member('manager', 'Manager');
        $claim = $this->claim($manager, 'verified', $manager->id);

        $this->actingAsEmployee($mgmt)->post("/app/claims/{$claim->id}/reject")->assertRedirect();

        $fresh = $claim->fresh();
        $this->assertSame('rejected', $fresh->status);
        $this->assertSame($mgmt->id, $fresh->rejected_by_id);
        $this->assertNotNull($fresh->rejected_at);
    }

    public function test_the_approvals_tab_counts_what_this_person_decided_this_year(): void
    {
        $mgmt = $this->member('management', 'Director');
        $manager = $this->member('manager', 'Manager');

        foreach (['approved', 'approved', 'rejected'] as $i => $status) {
            $this->claim($manager, 'verified', $manager->id, "Claim {$i}")->update([
                'status' => $status,
                'approved_by_id' => $status === 'approved' ? $mgmt->id : null,
                'approved_at' => $status === 'approved' ? now() : null,
                'rejected_by_id' => $status === 'rejected' ? $mgmt->id : null,
                'rejected_at' => $status === 'rejected' ? now() : null,
            ]);
        }

        $this->actingAsEmployee($mgmt)->get('/app/claims')->assertOk()
            ->assertViewHas('claimsApprovedByMe', fn ($c) => $c->count() === 2)
            ->assertViewHas('claimsRejectedByMe', fn ($c) => $c->count() === 1);
    }

    /**
     * Payroll flips an approved claim to 'paid' when it reimburses it. Being paid is the
     * approval reaching its end, so it must not drop out of the approver's history.
     */
    public function test_a_paid_claim_stays_in_the_approvers_approved_history(): void
    {
        $mgmt = $this->member('management', 'Director');
        $manager = $this->member('manager', 'Manager');

        $this->claim($manager, 'verified', $manager->id)->update([
            'status' => 'paid', 'approved_by_id' => $mgmt->id, 'approved_at' => now(),
        ]);

        $this->actingAsEmployee($mgmt)->get('/app/claims')->assertOk()
            ->assertViewHas('claimsApprovedByMe', fn ($c) => $c->count() === 1)
            ->assertSee('paid', false);
    }

    /**
     * The tab used to vanish when the queue emptied, taking the history with it. An
     * approver keeps it whether or not anything is pending.
     */
    public function test_an_approver_with_an_empty_queue_still_gets_the_approvals_tab(): void
    {
        $mgmt = $this->member('management', 'Director');

        $this->actingAsEmployee($mgmt)->get('/app/claims')->assertOk()
            ->assertSee('Nothing is waiting on you.', false)
            ->assertSee('You have not approved anything this year.', false);
    }

    /** A verifier did not decide it; somebody else did. It is not their history. */
    public function test_a_verifier_does_not_own_the_decision_someone_else_made(): void
    {
        $mgmt = $this->member('management', 'Director');
        $manager = $this->member('manager', 'Manager');
        $report = $this->member('employee', 'Reportee', $manager->id);

        $this->claim($report, 'verified', $manager->id)->update([
            'status' => 'rejected', 'rejected_by_id' => $mgmt->id, 'rejected_at' => now(),
        ]);

        $this->actingAsEmployee($manager)->get('/app/claims')->assertOk()
            ->assertSee('You have not rejected anything this year.', false);
    }

    // --- HR files on someone's behalf ------------------------------------------

    public function test_hr_can_file_a_claim_for_an_employee_and_it_routes_to_that_persons_manager(): void
    {
        $manager = $this->member('manager', 'Manager');
        $report = $this->member('employee', 'Reportee', $manager->id);
        $hr = $this->member('hr', 'HR Officer');

        $this->actingAsEmployee($hr)->post('/app/claims', [
            'employee_id' => $report->id, 'type' => 'other', 'title' => 'Parking', 'amount' => 12, 'date' => '2026-09-01',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $claim = Claim::where('employee_id', $report->id)->firstOrFail();
        $this->assertSame('submitted', $claim->status);
        $this->assertSame($hr->id, $claim->filed_by_id);
        $this->assertDatabaseHas('app_notifications', [
            'user_id' => $manager->user_id, 'title' => 'Claim awaiting your verification',
        ]);
    }

    public function test_hr_filing_for_a_director_opens_pre_verified_and_the_other_director_approves(): void
    {
        $shahril = $this->member('director', 'Shahril');
        $suandy = $this->member('director', 'Suandy');
        $hr = $this->member('hr', 'HR Officer');

        $this->actingAsEmployee($hr)->post('/app/claims', [
            'employee_id' => $shahril->id, 'type' => 'other', 'title' => 'Client dinner', 'amount' => 300, 'date' => '2026-09-01',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $claim = Claim::where('employee_id', $shahril->id)->firstOrFail();
        $this->assertSame('verified', $claim->status);

        $this->actingAsEmployee($hr)->post("/app/claims/{$claim->id}/approve")->assertForbidden();
        $this->actingAsEmployee($shahril)->post("/app/claims/{$claim->id}/approve")->assertForbidden();
        $this->actingAsEmployee($suandy)->post("/app/claims/{$claim->id}/approve")->assertRedirect();
        $this->assertSame('approved', $claim->fresh()->status);
    }

    public function test_non_hr_posting_employee_id_files_for_themselves(): void
    {
        $manager = $this->member('manager', 'Manager');
        $report = $this->member('employee', 'Reportee', $manager->id);
        $other = $this->member('employee', 'Other', $manager->id);

        $this->actingAsEmployee($other)->post('/app/claims', [
            'employee_id' => $report->id, 'type' => 'other', 'title' => 'Parking', 'amount' => 12, 'date' => '2026-09-01',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('claims', ['employee_id' => $report->id]);
        $this->assertDatabaseHas('claims', ['employee_id' => $other->id, 'filed_by_id' => null]);
    }

    public function test_hr_sees_the_filing_for_picker_and_others_do_not(): void
    {
        $hr = $this->member('hr', 'HR Officer');
        $staff = $this->member('employee', 'Plain Staff');

        $this->actingAsEmployee($hr)->get('/app/claims')->assertOk()->assertSee('Filing for');
        $this->actingAsEmployee($staff)->get('/app/claims')->assertOk()->assertDontSee('Filing for');
    }
}
