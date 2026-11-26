<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\PublicHoliday;
use App\Models\Tenant;
use App\Models\Timesheet;
use App\Models\TimesheetCategory;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use App\Timesheet\TimesheetCompliance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Helpdesk bugs (18 and 19 Sep 2026): with a public holiday mid-week, a staffer who
 * submits every other day still sees the overdue banner, and "Submit week" fails with
 * "Nothing left to submit this week."
 */
class HolidayWeekSubmitTest extends TestCase
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
        app(CurrentTenant::class)->set($this->tenant);
        $this->user->tenants()->attach($this->tenant->id, ['role' => 'employee']);
        $this->employee = Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $this->user->id,
            'name' => 'Demo', 'status' => 'active', 'workload' => 'green',
        ]);
        $this->category = TimesheetCategory::create(['tenant_id' => $this->tenant->id, 'name' => 'Others', 'requires_project' => false]);
        PublicHoliday::create(['tenant_id' => $this->tenant->id, 'name' => 'Awal Muharram', 'date' => '2026-06-17']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array<int, array<string, mixed>> */
    private function grid(): array
    {
        return array_map(
            fn (string $d) => ['entry_date' => $d, 'category_id' => $this->category->id, 'percentage' => 100],
            ['2026-06-15', '2026-06-16', '2026-06-18', '2026-06-19'],
        );
    }

    private function submitDay(string $iso): void
    {
        Carbon::setTestNow($iso.' 18:00:00');
        $this->actingAs($this->user)->withSession(['current_tenant' => $this->tenant->id])
            ->post('/app/timesheets', [
                'week_start' => '2026-06-15',
                'submit_day' => $iso,
                'entries' => array_values(array_filter($this->grid(), fn ($r) => $r['entry_date'] <= $iso)),
            ])->assertSessionHasNoErrors();
    }

    public function test_submitting_every_non_holiday_day_finishes_the_week(): void
    {
        foreach (['2026-06-15', '2026-06-16', '2026-06-18', '2026-06-19'] as $iso) {
            $this->submitDay($iso);
        }

        $sheet = Timesheet::firstWhere('employee_id', $this->employee->id);
        $this->assertSame('submitted', $sheet->status);

        Carbon::setTestNow('2026-06-19 19:30:00');
        $compliance = app(TimesheetCompliance::class);
        $this->assertFalse($compliance->isLate($this->employee, Carbon::parse('2026-06-15')));
        $this->assertTrue($compliance->isComplete($this->employee, Carbon::parse('2026-06-15')));

        // The roster (reports screen, reminders, dashboard) agrees, and counts 4 of 4 days, not 4 of 5 or 5 of 4.
        $row = $compliance->roster($this->tenant, Carbon::parse('2026-06-15'))->firstWhere('employee.id', $this->employee->id);
        $this->assertSame('done', $row['status']);
        $this->assertSame(4, $row['filledDays']);
        $this->assertSame(4, $row['expectedDays']);
    }

    public function test_a_holiday_week_with_an_unfilled_working_day_is_still_late(): void
    {
        foreach (['2026-06-15', '2026-06-16', '2026-06-18'] as $iso) {
            $this->submitDay($iso);
        }

        Carbon::setTestNow('2026-06-19 19:30:00');
        $this->assertTrue(app(TimesheetCompliance::class)->isLate($this->employee, Carbon::parse('2026-06-15')));
    }
}
