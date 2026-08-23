<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Tenant;
use App\Models\TimesheetCategory;
use App\Models\TimesheetEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * How far back a staffer may still edit: the current week plus six earlier ones. A week
 * that falls outside cannot be recovered — nothing reopens it — so the refusal must say
 * that plainly rather than sending the staffer to HR for a button that does not exist.
 */
class TimesheetBackfillWindowTest extends TestCase
{
    use RefreshDatabase;

    private TimesheetCategory $work;

    protected function setUp(): void
    {
        parent::setUp();

        // A Wednesday, so "this week" is unambiguous and every test date is in the past.
        Carbon::setTestNow('2026-08-19 09:00:00');

        $tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        $this->work = TimesheetCategory::create(['tenant_id' => $tenant->id, 'name' => 'Others', 'requires_project' => false]);

        $user = User::create(['name' => 'Staffer', 'email' => 'staffer@example.com', 'password' => Hash::make('password')]);
        $user->tenants()->attach($tenant->id, ['role' => 'employee']);
        Employee::create([
            'tenant_id' => $tenant->id, 'user_id' => $user->id,
            'name' => 'Staffer', 'status' => 'active', 'workload' => 'green',
        ]);
        $this->actingAs($user)->withSession(['current_tenant' => $tenant->id]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function saveDay(string $weekStart, string $day): TestResponse
    {
        return $this->post('/app/timesheets', [
            'week_start' => $weekStart,
            'entries' => [
                ['entry_date' => $day, 'category_id' => $this->work->id, 'percentage' => 100],
            ],
        ]);
    }

    /** This week is Mon 2026-08-17, so six weeks back reaches Mon 2026-07-06. */
    public function test_six_weeks_back_is_still_editable(): void
    {
        $this->saveDay('2026-07-06', '2026-07-06')->assertSessionHasNoErrors();

        // Not assertDatabaseHas on entry_date: sqlite keeps the cast's " 00:00:00" suffix,
        // which never matches a bare Y-m-d string.
        $this->assertSame(1, TimesheetEntry::whereDate('entry_date', '2026-07-06')->count());
    }

    public function test_seven_weeks_back_is_refused_without_promising_a_reopen(): void
    {
        $response = $this->saveDay('2026-06-29', '2026-06-29');

        $response->assertSessionHasErrors('entries.0.entry_date');
        $message = session('errors')->first('entries.0.entry_date');

        $this->assertStringContainsString('6 weeks back', $message);
        $this->assertStringNotContainsString('HR', $message);
    }
}
