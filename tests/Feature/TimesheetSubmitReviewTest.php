<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Project;
use App\Models\PublicHoliday;
use App\Models\Tenant;
use App\Models\Timesheet;
use App\Models\TimesheetCategory;
use App\Models\TimesheetEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class TimesheetSubmitReviewTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-06-19 12:00:00');

        $this->tenant = Tenant::create(['slug' => 'unijaya', 'name' => 'Unijaya', 'initials' => 'UJ']);
        $this->user = User::create([
            'name' => 'Aisyah Rahman', 'email' => 'aisyah@example.com', 'password' => Hash::make('password'),
        ]);
        $this->user->tenants()->attach($this->tenant->id, ['role' => 'employee']);

        $this->employee = Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $this->user->id,
            'name' => 'Aisyah Rahman', 'status' => 'active', 'workload' => 'green',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function actingInTenant(): self
    {
        $this->actingAs($this->user)->withSession(['current_tenant' => $this->tenant->id]);

        return $this;
    }

    public function test_the_record_tab_carries_a_review_pane(): void
    {
        $r = $this->actingInTenant()->get('/app/timesheets');

        $r->assertOk();
        $r->assertSee('Review before you submit', false);
        $r->assertSee('id="ts-review-title"', false);
    }

    public function test_the_submit_button_opens_the_review_instead_of_saving(): void
    {
        $r = $this->actingInTenant()->get('/app/timesheets');

        $r->assertOk();
        $r->assertSee('id="ts-submit-btn"', false);
        $r->assertSee('@click="openReview()"', false);
    }

    public function test_the_review_pane_has_its_own_confirm_button(): void
    {
        $r = $this->actingInTenant()->get('/app/timesheets');

        $r->assertOk();
        $r->assertSee('id="ts-confirm-submit-btn"', false);
        $r->assertSee('@click="save(true)"', false);
    }

    public function test_the_review_pane_closes_on_escape_and_the_back_gesture(): void
    {
        $r = $this->actingInTenant()->get('/app/timesheets');

        $r->assertOk();
        $r->assertSee('@keydown.escape.window', false);
        $r->assertSee('@popstate.window', false);
    }

    public function test_the_review_pane_has_a_category_summary(): void
    {
        $r = $this->actingInTenant()->get('/app/timesheets');

        $r->assertOk();
        $r->assertSee('id="ts-review-summary"', false);
        $r->assertSee('categoryTotals()', false);
        $r->assertSee('reviewDays()', false);
    }

    public function test_the_review_pane_renders_entries_and_locked_days_client_side(): void
    {
        $category = TimesheetCategory::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Development', 'requires_project' => true,
        ]);
        $project = Project::create(['tenant_id' => $this->tenant->id, 'name' => 'AmanahKu Platform']);

        $sheet = Timesheet::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'week_start' => '2026-06-15', 'status' => 'draft',
        ]);
        TimesheetEntry::create([
            'tenant_id' => $this->tenant->id, 'timesheet_id' => $sheet->id, 'entry_date' => '2026-06-15',
            'category_id' => $category->id, 'project_id' => $project->id, 'percentage' => 100,
            'description' => 'Weekly review mockups, tab styling',
        ]);
        PublicHoliday::create(['tenant_id' => $this->tenant->id, 'name' => 'Awal Muharram', 'date' => '2026-06-17']);

        $r = $this->actingInTenant()->get('/app/timesheets?week=2026-06-15');

        $r->assertOk();
        // The row's own data reaches the page — rowLabel()/dayLong() resolve it client-side,
        // so this proves the data is there, not that Alpine has rendered it (browser-verified).
        $r->assertSee('Weekly review mockups, tab styling', false);
        $r->assertSee('AmanahKu Platform', false);
        $r->assertSee('Awal Muharram', false);
        // The day-card template itself is present.
        $r->assertSee('x-text="rowLabel(r)"', false);
        $r->assertSee('x-text="dayLong(d)"', false);
        $r->assertSee('x-text="locked[d].label"', false);
    }
}
