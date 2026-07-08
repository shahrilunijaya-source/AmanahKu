<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Tenant;
use App\Models\Timesheet;
use App\Models\TimesheetCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Feature coverage for the Timesheets module.
 * Harness (setUp / actingInTenant / hrActor) copied from ExpenseTest.
 */
class TimesheetTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Tenant $tenant;

    private Employee $employee;

    private TimesheetCategory $category;

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
        // A standalone category (no project required) keeps allocation payloads simple.
        $this->category = TimesheetCategory::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Others', 'requires_project' => false,
        ]);
    }

    private function actingInTenant(): self
    {
        $this->actingAs($this->user)->withSession(['current_tenant' => $this->tenant->id]);

        return $this;
    }

    public function test_employee_creates_a_timesheet_with_entries_and_total_is_computed(): void
    {
        // Act — two full days at 100% each. Hours derive from percentage (100% = 8h).
        $response = $this->actingInTenant()->post('/app/timesheets', [
            'week_start' => '2026-06-15',
            'week_label' => 'Week 25 · 15–21 Jun',
            'entries' => [
                ['entry_date' => '2026-06-15', 'category_id' => $this->category->id, 'percentage' => 100, 'description' => 'Endpoints'],
                ['entry_date' => '2026-06-16', 'category_id' => $this->category->id, 'percentage' => 100],
            ],
        ]);

        // Assert
        $response->assertRedirect();
        $timesheet = Timesheet::where('employee_id', $this->employee->id)->first();
        $this->assertNotNull($timesheet);
        $this->assertSame('draft', $timesheet->status);
        $this->assertSame(2, $timesheet->entries()->count());
        $this->assertSame('16.00', (string) $timesheet->total_hours);
    }

    public function test_validation_rejects_a_timesheet_with_no_entries(): void
    {
        $this->actingInTenant()->post('/app/timesheets', [
            'week_start' => '2026-06-15',
            'entries' => [],
        ])->assertSessionHasErrors('entries');
    }

    public function test_submit_transitions_draft_to_submitted(): void
    {
        // Arrange
        $timesheet = Timesheet::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'week_start' => '2026-06-15', 'status' => 'draft', 'total_hours' => 0,
        ]);
        $timesheet->entries()->create([
            'tenant_id' => $this->tenant->id, 'entry_date' => '2026-06-15',
            'category_id' => $this->category->id, 'percentage' => 100, 'hours' => 8.00,
        ]);

        // Act — the single day totals 100%, so submission is allowed.
        $response = $this->actingInTenant()->post("/app/timesheets/{$timesheet->id}/submit");

        // Assert
        $response->assertRedirect();
        $fresh = $timesheet->fresh();
        $this->assertSame('submitted', $fresh->status);
        $this->assertNotNull($fresh->submitted_at);
    }

    public function test_store_does_not_reopen_or_double_count_an_already_submitted_week(): void
    {
        // Arrange — a submitted timesheet already exists for this week.
        $timesheet = Timesheet::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'week_start' => '2026-06-15', 'status' => 'submitted', 'total_hours' => 8,
        ]);
        $timesheet->entries()->create([
            'tenant_id' => $this->tenant->id, 'entry_date' => '2026-06-15',
            'category_id' => $this->category->id, 'percentage' => 100, 'hours' => 8.00,
        ]);

        // Act — replay store() for the same week (double-click / back-button re-post).
        $response = $this->actingInTenant()->post('/app/timesheets', [
            'week_start' => '2026-06-15',
            'entries' => [
                ['entry_date' => '2026-06-16', 'category_id' => $this->category->id, 'percentage' => 100],
            ],
        ]);

        // Assert — rejected; status preserved, no duplicate entries, no double count.
        $response->assertStatus(422);
        $fresh = $timesheet->fresh();
        $this->assertSame('submitted', $fresh->status);
        $this->assertSame(1, $fresh->entries()->count());
        $this->assertSame('8.00', (string) $fresh->total_hours);
    }

    public function test_submit_is_blocked_when_a_day_does_not_total_100_percent(): void
    {
        // Arrange — a draft whose only day sums to 50%.
        $timesheet = Timesheet::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'week_start' => '2026-06-15', 'status' => 'draft', 'total_hours' => 0,
        ]);
        $timesheet->entries()->create([
            'tenant_id' => $this->tenant->id, 'entry_date' => '2026-06-15',
            'category_id' => $this->category->id, 'percentage' => 50, 'hours' => 4.00,
        ]);

        // Act + Assert — submission rejected; the day is not yet 100%.
        $this->actingInTenant()->post("/app/timesheets/{$timesheet->id}/submit")
            ->assertSessionHasErrors('submit');
        $this->assertSame('draft', $timesheet->fresh()->status);
    }

    public function test_store_with_submit_now_rejects_an_incomplete_day(): void
    {
        $this->actingInTenant()->post('/app/timesheets', [
            'week_start' => '2026-06-15',
            'submit_now' => 1,
            'entries' => [
                ['entry_date' => '2026-06-15', 'category_id' => $this->category->id, 'percentage' => 60],
            ],
        ])->assertSessionHasErrors('submit');

        // The week must not have been submitted.
        $this->assertNull(Timesheet::where('employee_id', $this->employee->id)->where('status', 'submitted')->first());
    }

    public function test_store_with_submit_now_submits_when_every_day_is_100(): void
    {
        $this->actingInTenant()->post('/app/timesheets', [
            'week_start' => '2026-06-15',
            'submit_now' => 1,
            'entries' => [
                ['entry_date' => '2026-06-15', 'category_id' => $this->category->id, 'percentage' => 100],
            ],
        ])->assertRedirect();

        $this->assertSame('submitted', Timesheet::where('employee_id', $this->employee->id)->first()->status);
    }

    public function test_category_that_requires_a_project_rejects_a_missing_project(): void
    {
        $needsProject = TimesheetCategory::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Development', 'requires_project' => true,
        ]);

        $this->actingInTenant()->post('/app/timesheets', [
            'week_start' => '2026-06-15',
            'entries' => [
                ['entry_date' => '2026-06-15', 'category_id' => $needsProject->id, 'percentage' => 100],
            ],
        ])->assertSessionHasErrors('entries.0.project_id');

        $this->assertNull(Timesheet::where('employee_id', $this->employee->id)->first());
    }
}
