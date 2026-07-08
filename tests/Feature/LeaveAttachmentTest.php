<?php

namespace Tests\Feature;

use App\Models\Employee;
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

    public function test_attachment_required_type_is_rejected_without_a_file(): void
    {
        $report = $this->member('employee', 'Reportee');

        $this->actingAsEmployee($report)->post('/app/leave', [
            'leave_type_id' => $this->medical->id, 'date_from' => '2026-07-10', 'date_to' => '2026-07-11',
        ])->assertSessionHasErrors('attachment');

        $this->assertDatabaseCount('leave_requests', 0);
    }

    public function test_attachment_required_type_is_accepted_with_a_file(): void
    {
        $report = $this->member('employee', 'Reportee');

        $this->actingAsEmployee($report)->post('/app/leave', [
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
            'leave_type_id' => $this->annual->id, 'date_from' => '2026-07-10', 'date_to' => '2026-07-11',
        ])->assertRedirect()->assertSessionHas('ok');

        $this->assertNull(LeaveRequest::first()->attachment_path);
    }

    public function test_owner_can_download_their_attachment(): void
    {
        $report = $this->member('employee', 'Reportee');
        $this->actingAsEmployee($report)->post('/app/leave', [
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
            'leave_type_id' => $this->medical->id, 'date_from' => '2026-07-10', 'date_to' => '2026-07-11',
            'attachment' => UploadedFile::fake()->create('mc.pdf', 120, 'application/pdf'),
        ]);
        $req = LeaveRequest::first();

        $mgmt = $this->member('management', 'Director');
        $this->actingAsEmployee($mgmt)->get("/app/leave/{$req->id}/attachment")->assertOk();
    }
}
