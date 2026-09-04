<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\PublicHoliday;
use App\Models\Tenant;
use App\Models\Timesheet;
use App\Models\TimesheetCategory;
use App\Models\TimesheetEntry;
use App\Models\User;
use App\Timesheet\WeekReconciler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * A staffer may now work through a public holiday: their typed rows are kept, and the
 * generated Public Holiday row shrinks to whatever capacity is left over instead of
 * being dropped in favour of a full-day locked row. See LockedDays::keepsTypedRows()
 * and LockedDays::entryRows() for where this decision is made.
 */
class PublicHolidayTimesheetTest extends TestCase
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
        $this->category = TimesheetCategory::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Others', 'requires_project' => false,
        ]);
        TimesheetCategory::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Public Holiday', 'requires_project' => false,
        ]);

        // Week of Mon 2026-06-15; Wed 2026-06-17 is the holiday used throughout.
        Carbon::setTestNow('2026-06-19 12:00:00');
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

    private function dayEntries(string $date)
    {
        return TimesheetEntry::whereDate('entry_date', $date)->get();
    }

    public function test_a_partial_day_on_a_holiday_keeps_the_typed_row_and_shrinks_the_holiday_row(): void
    {
        PublicHoliday::create(['tenant_id' => $this->tenant->id, 'name' => 'Awal Muharram', 'date' => '2026-06-17']);

        $this->actingInTenant()->post('/app/timesheets', [
            'week_start' => '2026-06-15',
            'entries' => [
                ['entry_date' => '2026-06-17', 'category_id' => $this->category->id, 'percentage' => 60],
            ],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $rows = $this->dayEntries('2026-06-17');
        $this->assertCount(2, $rows);
        $this->assertEqualsWithDelta(60.0, (float) $rows->firstWhere('source', null)->percentage, 0.001);
        $this->assertEqualsWithDelta(40.0, (float) $rows->firstWhere('source', 'holiday')->percentage, 0.001);
    }

    public function test_a_full_day_on_a_holiday_omits_the_holiday_row_entirely(): void
    {
        PublicHoliday::create(['tenant_id' => $this->tenant->id, 'name' => 'Awal Muharram', 'date' => '2026-06-17']);

        $this->actingInTenant()->post('/app/timesheets', [
            'week_start' => '2026-06-15',
            'entries' => [
                ['entry_date' => '2026-06-17', 'category_id' => $this->category->id, 'percentage' => 100],
            ],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $rows = $this->dayEntries('2026-06-17');
        $this->assertCount(1, $rows);
        $this->assertNull($rows->first()->source);
        $this->assertEqualsWithDelta(100.0, (float) $rows->first()->percentage, 0.001);
    }

    public function test_typed_rows_over_capacity_on_a_holiday_are_still_refused_at_submit(): void
    {
        PublicHoliday::create(['tenant_id' => $this->tenant->id, 'name' => 'Awal Muharram', 'date' => '2026-06-17']);
        $otherCategory = TimesheetCategory::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Study & Research', 'requires_project' => false,
        ]);

        // Two rows totalling 120% — the field-level max:100 rule allows each row on its
        // own, so this is what actually exercises the day-total capacity check.
        $this->actingInTenant()->post('/app/timesheets', [
            'week_start' => '2026-06-15',
            'submit_now' => true,
            'entries' => [
                ['entry_date' => '2026-06-17', 'category_id' => $this->category->id, 'percentage' => 70],
                ['entry_date' => '2026-06-17', 'category_id' => $otherCategory->id, 'percentage' => 50],
            ],
        ])->assertSessionHasErrors('submit');

        // Nothing was persisted — save() only writes once the submit checks pass.
        $this->assertSame(0, TimesheetEntry::count());
    }

    /**
     * A public holiday gazetted AFTER a week was already saved with rows on that date must
     * push its Public Holiday row into the stored week (WeekReconciler::reconcileForHolidayDate)
     * without touching the staffer's own rows.
     */
    public function test_a_holiday_gazetted_after_the_week_was_saved_keeps_the_typed_rows_and_adds_the_remainder(): void
    {
        $this->actingInTenant()->post('/app/timesheets', [
            'week_start' => '2026-06-15',
            'entries' => [
                ['entry_date' => '2026-06-17', 'category_id' => $this->category->id, 'percentage' => 70],
            ],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertCount(1, $this->dayEntries('2026-06-17'));

        PublicHoliday::create(['tenant_id' => $this->tenant->id, 'name' => 'Awal Muharram', 'date' => '2026-06-17']);
        $sheet = Timesheet::firstWhere('employee_id', $this->employee->id);
        app(WeekReconciler::class)->reconcile($sheet->fresh());

        $rows = $this->dayEntries('2026-06-17');
        $this->assertCount(2, $rows);
        $this->assertEqualsWithDelta(70.0, (float) $rows->firstWhere('source', null)->percentage, 0.001);
        $this->assertEqualsWithDelta(30.0, (float) $rows->firstWhere('source', 'holiday')->percentage, 0.001);
    }

    /** TOT first Saturday of the month: capacity 50, typed 30 → the holiday row is 20. */
    public function test_a_holiday_on_the_tot_saturday_shrinks_against_the_half_day_capacity(): void
    {
        // 2026-08-01 is the first Saturday of August — its week starts Mon 2026-07-27.
        // "now" must sit on/after the Saturday itself, or the entry date is refused as
        // not having happened yet.
        Carbon::setTestNow('2026-08-01 12:00:00');
        PublicHoliday::create(['tenant_id' => $this->tenant->id, 'name' => 'Cuti Peristiwa', 'date' => '2026-08-01']);

        $this->actingInTenant()->post('/app/timesheets', [
            'week_start' => '2026-07-27',
            'entries' => [
                ['entry_date' => '2026-08-01', 'category_id' => $this->category->id, 'percentage' => 30],
            ],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $rows = $this->dayEntries('2026-08-01');
        $this->assertCount(2, $rows);
        $this->assertEqualsWithDelta(30.0, (float) $rows->firstWhere('source', null)->percentage, 0.001);
        $this->assertEqualsWithDelta(20.0, (float) $rows->firstWhere('source', 'holiday')->percentage, 0.001);
    }
}
