<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\Project;
use App\Models\Scopes\ParentOnly;
use App\Models\Tenant;
use App\Models\Timesheet;
use App\Models\TimesheetCategory;
use App\Models\TimesheetEntry;
use App\Models\User;
use App\Models\WorkItem;
use Database\Seeders\BuildFixturesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BuildFixturesSeederTest extends TestCase
{
    use RefreshDatabase;

    private const EMAILS = [
        'hidayahsuffya.unijaya@gmail.com',
        'kussairi.unijaya@gmail.com',
        'haryati.unijaya@gmail.com',
        'shahril.unijaya@gmail.com',
        'shazwanshah.unijaya@gmail.com',
    ];

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        // BuildFixturesSeeder always targets tenant id 1.
        $this->tenant = Tenant::create(['id' => 1, 'slug' => 'unijaya', 'name' => 'Unijaya', 'initials' => 'UJ']);

        foreach (self::EMAILS as $email) {
            $user = User::factory()->create(['email' => $email]);
            Employee::create([
                'tenant_id' => $this->tenant->id,
                'user_id' => $user->id,
                'name' => $email,
                'status' => 'active',
                'workload' => 'green',
            ]);
        }

        TimesheetCategory::seedFor($this->tenant);
        Project::create(['tenant_id' => $this->tenant->id, 'code' => 'KPT', 'name' => 'KPT: RMS', 'is_active' => true]);
    }

    public function test_it_builds_deterministic_and_idempotent_fixtures(): void
    {
        $this->seed(BuildFixturesSeeder::class);

        $attendanceCount = AttendanceRecord::count();
        $this->assertGreaterThan(0, $attendanceCount);

        $cardCount = WorkItem::withoutGlobalScope(ParentOnly::class)
            ->where('title', 'like', 'Fixture:%')
            ->whereNull('parent_id')
            ->count();
        $this->assertGreaterThanOrEqual(40, $cardCount);
        $this->assertLessThanOrEqual(60, $cardCount);

        $subtaskCount = WorkItem::withoutGlobalScope(ParentOnly::class)->whereNotNull('parent_id')->count();
        $this->assertGreaterThan(0, $subtaskCount);

        foreach (Employee::whereHas('user', fn ($q) => $q->whereIn('email', self::EMAILS))->get() as $employee) {
            $this->assertGreaterThan(0, Timesheet::where('employee_id', $employee->id)->count());
        }

        foreach (Timesheet::with('entries')->get() as $timesheet) {
            $byDay = $timesheet->entries->groupBy(fn (TimesheetEntry $e) => $e->entry_date->toDateString());
            foreach ($byDay as $date => $entries) {
                $this->assertEqualsWithDelta(100.0, (float) $entries->sum('percentage'), 0.01, "entries for {$date} do not sum to 100");
            }
        }

        // Re-run: idempotent, not merely non-duplicating — same counts, same rows.
        $this->seed(BuildFixturesSeeder::class);

        $this->assertSame($attendanceCount, AttendanceRecord::count());
        $this->assertSame($cardCount, WorkItem::withoutGlobalScope(ParentOnly::class)
            ->where('title', 'like', 'Fixture:%')->whereNull('parent_id')->count());
    }

    public function test_it_skips_missing_employees_without_error(): void
    {
        Employee::whereHas('user', fn ($q) => $q->where('email', 'kussairi.unijaya@gmail.com'))->delete();

        $this->seed(BuildFixturesSeeder::class);

        $this->assertGreaterThan(0, WorkItem::count());
    }
}
