<?php

namespace Tests\Feature;

use App\Models\Claim;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Only the requester can cancel a claim or leave request, so "You cancelled this" is
 * true for them alone. Anyone else reading the timeline is told who cancelled it.
 */
class CancelledByLabelTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Employee $faris;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        $this->faris = Employee::create(['tenant_id' => $this->tenant->id, 'name' => "Faris Ma'arof", 'status' => 'active', 'workload' => 'green']);
    }

    private function viewingAs(?Employee $viewer): void
    {
        request()->attributes->set('employee', $viewer);
    }

    private function claimTimeline(): string
    {
        $claim = Claim::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->faris->id, 'type' => 'expense',
            'title' => 'Lunch', 'amount' => 10, 'date' => '2026-09-01', 'status' => 'cancelled',
        ]);

        return view('partials.claims-timeline', ['c' => $claim->load('employee')])->render();
    }

    private function leaveTimeline(): string
    {
        $type = LeaveType::create(['tenant_id' => $this->tenant->id, 'name' => 'Annual', 'entitlement' => 18]);
        $leave = LeaveRequest::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->faris->id, 'leave_type_id' => $type->id,
            'date_from' => '2026-09-01', 'date_to' => '2026-09-01', 'days' => 1, 'status' => 'cancelled',
        ]);

        return view('partials.leave-timeline', ['r' => $leave->load('employee')])->render();
    }

    public function test_the_claimant_reads_you_cancelled_this(): void
    {
        $this->viewingAs($this->faris);

        $html = $this->claimTimeline();
        $this->assertStringContainsString('You cancelled this.', $html);
        $this->assertStringNotContainsString('Cancelled by', $html);
    }

    public function test_anyone_else_reads_who_cancelled_the_claim(): void
    {
        $this->viewingAs(null); // a super-admin has no employee record

        $html = $this->claimTimeline();
        $this->assertStringContainsString('Cancelled by Faris Ma', $html);
        $this->assertStringNotContainsString('You cancelled this.', $html);
    }

    public function test_the_applicant_reads_you_cancelled_this_on_leave(): void
    {
        $this->viewingAs($this->faris);

        $this->assertStringContainsString('You cancelled this.', $this->leaveTimeline());
    }

    public function test_a_manager_reads_who_cancelled_the_leave(): void
    {
        $this->viewingAs(Employee::create(['tenant_id' => $this->tenant->id, 'name' => 'Manager', 'status' => 'active', 'workload' => 'green']));

        $html = $this->leaveTimeline();
        $this->assertStringContainsString('Cancelled by Faris Ma', $html);
        $this->assertStringNotContainsString('You cancelled this.', $html);
    }
}
