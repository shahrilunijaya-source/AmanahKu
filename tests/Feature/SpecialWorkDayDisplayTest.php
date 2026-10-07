<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkDayRule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** Special work days reach the sidebar dock line and the timesheet capture config. */
class SpecialWorkDayDisplayTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create(['name' => 'Demo', 'email' => 'demo@example.com', 'password' => Hash::make('password')]);
        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        $this->user->tenants()->attach($this->tenant->id, ['role' => 'employee']);
        Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $this->user->id,
            'name' => 'Demo', 'status' => 'active', 'workload' => 'green',
        ]);
        WorkDayRule::factory()->create(['tenant_id' => $this->tenant->id]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function open(string $path)
    {
        return $this->actingAs($this->user)->withSession(['current_tenant' => $this->tenant->id])->get($path);
    }

    public function test_sidebar_dock_shows_the_rule_hours_on_a_rule_day(): void
    {
        Carbon::setTestNow('2026-10-03 09:30:00');

        $this->open('/app/dash')->assertOk()
            ->assertSee('09:00–13:00', false)
            ->assertSee('1st Saturday', false);
    }

    public function test_sidebar_dock_hides_the_line_on_a_normal_saturday(): void
    {
        Carbon::setTestNow('2026-10-10 09:30:00');

        $this->open('/app/dash')->assertOk()->assertDontSee('09:00–13:00', false);
    }

    public function test_sidebar_title_joins_several_weeks_in_both_languages(): void
    {
        Carbon::setTestNow('2026-10-10 09:30:00');
        WorkDayRule::query()->update(['weeks' => [2, 4]]);

        $this->open('/app/dash')->assertOk()
            ->assertSee('2nd and 4th Saturday', false)
            ->assertSee('Sabtu kedua dan keempat', false);
    }

    public function test_timesheet_screen_passes_the_ruled_saturday_at_half_capacity(): void
    {
        Carbon::setTestNow('2026-10-03 09:30:00');

        $this->open('/app/timesheets?week=2026-09-28')->assertOk()
            ->assertViewHas('tsSpecialDays', ['2026-10-03' => 50]);
    }

    public function test_timesheet_screen_passes_nothing_for_a_week_without_a_ruled_day(): void
    {
        Carbon::setTestNow('2026-10-10 09:30:00');

        $this->open('/app/timesheets?week=2026-10-05')->assertOk()
            ->assertViewHas('tsSpecialDays', []);
    }
}
