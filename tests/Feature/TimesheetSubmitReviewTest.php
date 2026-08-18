<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Tenant;
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
}
