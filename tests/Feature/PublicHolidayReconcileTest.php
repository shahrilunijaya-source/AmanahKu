<?php

namespace Tests\Feature;

use App\Models\Employee;
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

/**
 * A gazetted holiday ("cuti peristiwa") lands at short notice, often after staff have
 * already filled the week. Adding it on the Leave Setup screen must push the locked
 * Public Holiday row into weeks already stored, and removing it must take that row back
 * out — neither should wait for each staffer to re-save.
 */
class PublicHolidayReconcileTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private TimesheetCategory $work;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        // Friday of the week starting Mon 2026-07-20, so Wed is in the backfill window.
        Carbon::setTestNow('2026-07-24 12:00:00');

        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        // LockedDays files its generated rows under these fixed category names.
        TimesheetCategory::create(['tenant_id' => $this->tenant->id, 'name' => 'Public Holiday', 'requires_project' => false]);
        $this->work = TimesheetCategory::create(['tenant_id' => $this->tenant->id, 'name' => 'Others', 'requires_project' => false]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function member(string $role, string $name): Employee
    {
        $this->seq++;
        $user = User::create(['name' => $name, 'email' => "user{$this->seq}@example.com", 'password' => Hash::make('password')]);
        $user->tenants()->attach($this->tenant->id, ['role' => $role]);

        return Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $user->id,
            'name' => $name, 'status' => 'active', 'workload' => 'green',
        ]);
    }

    private function actingAsEmployee(Employee $e): self
    {
        $this->actingAs($e->user)->withSession(['current_tenant' => $this->tenant->id]);

        return $this;
    }

    /** Save a full 100% day of ordinary work on 2026-07-22 for this employee. */
    private function saveWorkDay(Employee $e): Timesheet
    {
        $this->actingAsEmployee($e)->post('/app/timesheets', [
            'week_start' => '2026-07-20',
            'entries' => [
                ['entry_date' => '2026-07-22', 'category_id' => $this->work->id, 'percentage' => 100],
            ],
        ])->assertRedirect();

        return Timesheet::firstWhere('employee_id', $e->id);
    }

    private function dayEntries(Timesheet $sheet)
    {
        return TimesheetEntry::where('timesheet_id', $sheet->id)->whereDate('entry_date', '2026-07-22')->get();
    }

    public function test_adding_a_holiday_backfills_every_stored_week_and_removing_it_undoes_that(): void
    {
        $hr = $this->member('hr', 'Boss');
        $one = $this->member('employee', 'Staffer One');
        $two = $this->member('employee', 'Staffer Two');

        $sheetOne = $this->saveWorkDay($one);
        $sheetTwo = $this->saveWorkDay($two);
        $this->assertNull($this->dayEntries($sheetOne)->first()->source);

        // HR gazettes the day after both weeks were already saved.
        $this->actingAsEmployee($hr)
            ->post(route('holiday.store'), ['name' => 'Cuti Peristiwa', 'date' => '2026-07-22', 'state' => 'Selangor'])
            ->assertRedirect();

        foreach ([$sheetOne, $sheetTwo] as $sheet) {
            $rows = $this->dayEntries($sheet);
            // The typed work row already fills the day, so it is kept as-is and the
            // generated Public Holiday row has no remainder left to cover — it is omitted
            // entirely rather than persisted at 0%.
            $this->assertCount(1, $rows, 'the typed work row survives the holiday, and no 0% holiday row is added');
            $this->assertNull($rows->first()->source);
            $this->assertEqualsWithDelta(100.0, (float) $rows->first()->percentage, 0.001);
        }

        // Entered by mistake: removing it must not change anything (nothing was locked).
        $holiday = PublicHoliday::firstWhere('name', 'Cuti Peristiwa');
        $this->actingAsEmployee($hr)->post(route('holiday.delete', $holiday))->assertRedirect();

        foreach ([$sheetOne, $sheetTwo] as $sheet) {
            $rows = $this->dayEntries($sheet);
            $this->assertCount(1, $rows, 'the typed work row is untouched');
            $this->assertNull($rows->first()->source);
        }
    }

    /** An approved week is a decided figure: the back-fill must leave it alone. */
    public function test_an_approved_week_is_left_untouched(): void
    {
        $hr = $this->member('hr', 'Boss');
        $staff = $this->member('employee', 'Staffer');

        $sheet = $this->saveWorkDay($staff);
        $sheet->update(['status' => 'approved']);

        $this->actingAsEmployee($hr)
            ->post(route('holiday.store'), ['name' => 'Cuti Peristiwa', 'date' => '2026-07-22'])
            ->assertRedirect();

        $rows = $this->dayEntries($sheet);
        $this->assertCount(1, $rows);
        $this->assertNull($rows->first()->source);
    }
}
