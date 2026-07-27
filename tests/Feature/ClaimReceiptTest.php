<?php

namespace Tests\Feature;

use App\Models\Claim;
use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Claim receipts are stored under a random hashed name on disk (see LeaveAttachmentTest for
 * the same rationale on leave attachments). This covers the readable filename the browser
 * saves the download as, built from AttachmentName rather than the stored path.
 */
class ClaimReceiptTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

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

    private function claimWithReceipt(Employee $employee, string $filename = 'receipt.pdf'): Claim
    {
        $this->actingAsEmployee($employee)->post('/app/claims', [
            'type' => 'expense', 'title' => 'Lunch', 'amount' => 30, 'date' => '2026-06-21',
            'receipt' => UploadedFile::fake()->create($filename, 40, 'application/pdf'),
        ])->assertRedirect();

        return Claim::where('title', 'Lunch')->firstOrFail();
    }

    public function test_download_filename_uses_the_readable_format(): void
    {
        $manager = $this->member('manager', 'Manager');
        $report = $this->member('employee', 'Azman', $manager->id);
        $claim = $this->claimWithReceipt($report);

        $response = $this->actingAsEmployee($report)->get("/app/claims/{$claim->id}/receipt")->assertOk();

        $this->assertStringContainsString(
            "azman-claim-{$claim->id}-2026-06-21.pdf",
            $response->headers->get('Content-Disposition'),
        );
    }

    public function test_download_filename_is_slugged_and_carries_no_apostrophe(): void
    {
        // Regression guard: an apostrophe in Content-Disposition is exactly what
        // Hostinger's ModSecurity WAF blocks on staging (the on-disk name stays hashed).
        $manager = $this->member('manager', 'Manager');
        $report = $this->member('employee', "Sa'adiah Binti Yusof", $manager->id);
        $claim = $this->claimWithReceipt($report);

        $response = $this->actingAsEmployee($report)->get("/app/claims/{$claim->id}/receipt")->assertOk();

        $disposition = $response->headers->get('Content-Disposition');
        $this->assertStringNotContainsString("'", $disposition);
        $this->assertStringContainsString(
            "saadiah-binti-yusof-claim-{$claim->id}-2026-06-21.pdf",
            $disposition,
        );
    }
}
