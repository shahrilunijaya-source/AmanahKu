<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Leave supporting documents (Employment Act 1955): sick/medical, hospitalisation and
 * maternity leave legally require a certificate, so the form demands a file for those
 * types and stores it on the private disk. The download is auth-gated — only the
 * requester, their immediate superior and management/HR may view it.
 */
class LeaveAttachmentTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private LeaveType $medical;

    private LeaveType $annual;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        $this->medical = LeaveType::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Medical', 'entitlement' => 14, 'requires_attachment' => true,
        ]);
        $this->annual = LeaveType::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Annual', 'entitlement' => 16, 'requires_attachment' => false,
        ]);
    }

    private function member(string $role, string $name, ?int $reportsToId = null): Employee
    {
        $this->seq++;
        $user = User::create(['name' => $name, 'email' => "user{$this->seq}@example.com", 'password' => Hash::make('password')]);
        $user->tenants()->attach($this->tenant->id, ['role' => $role]);

        return $this->makeEligible(Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $user->id,
            'name' => $name, 'status' => 'active', 'workload' => 'green',
            'reports_to_id' => $reportsToId,
        ]));
    }

    /**
     * Every leave type this tenant has, opened at a generous balance. HR ticks eligibility
     * per person on Leave Setup and that tick IS the balance row, so a fixture employee
     * with no rows cannot apply for anything.
     */
    private function makeEligible(Employee $e): Employee
    {
        foreach (LeaveType::all() as $t) {
            LeaveBalance::firstOrCreate(
                ['employee_id' => $e->id, 'leave_type_id' => $t->id],
                ['balance' => 30],
            );
        }

        return $e;
    }

    private function actingAsEmployee(Employee $e): self
    {
        $this->actingAs($e->user)->withSession(['current_tenant' => $this->tenant->id]);

        return $this;
    }

    public function test_attachment_required_type_is_rejected_without_a_file(): void
    {
        $report = $this->member('employee', 'Reportee');

        $this->actingAsEmployee($report)->post('/app/leave', [
            'reason' => 'Family matters.',
            'leave_type_id' => $this->medical->id, 'date_from' => '2026-07-10', 'date_to' => '2026-07-11',
        ])->assertSessionHasErrors('attachment');

        $this->assertDatabaseCount('leave_requests', 0);
    }

    public function test_attachment_required_type_is_accepted_with_a_file(): void
    {
        $report = $this->member('employee', 'Reportee');

        $this->actingAsEmployee($report)->post('/app/leave', [
            'reason' => 'Family matters.',
            'leave_type_id' => $this->medical->id, 'date_from' => '2026-07-10', 'date_to' => '2026-07-11',
            'attachment' => UploadedFile::fake()->create('mc.pdf', 120, 'application/pdf'),
        ])->assertRedirect()->assertSessionHas('ok');

        $req = LeaveRequest::first();
        $this->assertNotNull($req->attachment_path);
        $this->assertSame('mc.pdf', $req->attachment_name);
        Storage::disk('local')->assertExists($req->attachment_path);
    }

    public function test_optional_type_submits_without_a_file(): void
    {
        $report = $this->member('employee', 'Reportee');

        $this->actingAsEmployee($report)->post('/app/leave', [
            'reason' => 'Family matters.',
            'leave_type_id' => $this->annual->id, 'date_from' => '2026-07-10', 'date_to' => '2026-07-11',
        ])->assertRedirect()->assertSessionHas('ok');

        $this->assertNull(LeaveRequest::first()->attachment_path);
    }

    public function test_owner_can_download_their_attachment(): void
    {
        $report = $this->member('employee', 'Reportee');
        $this->actingAsEmployee($report)->post('/app/leave', [
            'reason' => 'Family matters.',
            'leave_type_id' => $this->medical->id, 'date_from' => '2026-07-10', 'date_to' => '2026-07-11',
            'attachment' => UploadedFile::fake()->create('mc.pdf', 120, 'application/pdf'),
        ]);
        $req = LeaveRequest::first();

        $this->actingAsEmployee($report)->get("/app/leave/{$req->id}/attachment")->assertOk();
    }

    public function test_an_unrelated_employee_cannot_download_the_attachment(): void
    {
        $report = $this->member('employee', 'Reportee');
        $this->actingAsEmployee($report)->post('/app/leave', [
            'reason' => 'Family matters.',
            'leave_type_id' => $this->medical->id, 'date_from' => '2026-07-10', 'date_to' => '2026-07-11',
            'attachment' => UploadedFile::fake()->create('mc.pdf', 120, 'application/pdf'),
        ]);
        $req = LeaveRequest::first();

        $stranger = $this->member('employee', 'Stranger');
        $this->actingAsEmployee($stranger)->get("/app/leave/{$req->id}/attachment")->assertForbidden();
    }

    public function test_management_can_download_the_attachment(): void
    {
        $report = $this->member('employee', 'Reportee');
        $this->actingAsEmployee($report)->post('/app/leave', [
            'reason' => 'Family matters.',
            'leave_type_id' => $this->medical->id, 'date_from' => '2026-07-10', 'date_to' => '2026-07-11',
            'attachment' => UploadedFile::fake()->create('mc.pdf', 120, 'application/pdf'),
        ]);
        $req = LeaveRequest::first();

        $mgmt = $this->member('management', 'Director');
        $this->actingAsEmployee($mgmt)->get("/app/leave/{$req->id}/attachment")->assertOk();
    }

    public function test_download_filename_is_slugged_and_carries_no_apostrophe(): void
    {
        // Regression guard: an apostrophe in Content-Disposition is exactly what
        // Hostinger's ModSecurity WAF blocks on staging (AK's on-disk name stays hashed).
        $report = $this->member('employee', "Nur'ain Binti Abdullah");
        $this->actingAsEmployee($report)->post('/app/leave', [
            'reason' => 'Family matters.',
            'leave_type_id' => $this->medical->id, 'date_from' => '2026-07-10', 'date_to' => '2026-07-11',
            'attachment' => UploadedFile::fake()->create('mc.pdf', 120, 'application/pdf'),
        ]);
        $req = LeaveRequest::first();

        $response = $this->actingAsEmployee($report)->get("/app/leave/{$req->id}/attachment")->assertOk();

        $disposition = $response->headers->get('Content-Disposition');
        $this->assertStringNotContainsString("'", $disposition);
        $this->assertStringContainsString(
            "nurain-binti-abdullah-leave-{$req->id}-2026-07-10.pdf",
            $disposition,
        );
    }

    public function test_attachment_is_served_inline_for_preview(): void
    {
        // A verifier reads the MC before deciding, so the browser must render it in a
        // tab rather than save it — an 'attachment' disposition is the old behaviour.
        $report = $this->member('employee', 'Reportee');
        $this->actingAsEmployee($report)->post('/app/leave', [
            'reason' => 'Family matters.',
            'leave_type_id' => $this->medical->id, 'date_from' => '2026-07-10', 'date_to' => '2026-07-11',
            'attachment' => UploadedFile::fake()->create('mc.pdf', 120, 'application/pdf'),
        ]);
        $req = LeaveRequest::first();

        $disposition = $this->actingAsEmployee($report)
            ->get("/app/leave/{$req->id}/attachment")->assertOk()
            ->headers->get('Content-Disposition');

        $this->assertStringStartsWith('inline', $disposition);
    }
}
