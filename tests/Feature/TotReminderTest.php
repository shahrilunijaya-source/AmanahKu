<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\Employee;
use App\Models\Tenant;
use App\Models\TotSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Coverage for the tot:remind sweep. Dates are frozen with travelTo so each stage
 * (14 days out with no topic, 7 days out, 1 day out) can be triggered exactly.
 */
class TotReminderTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $presenter;

    private Employee $presenterEmployee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        $this->presenter = User::create(['name' => 'Nabil', 'email' => 'nabil@example.com', 'password' => Hash::make('password')]);
        $this->presenter->tenants()->attach($this->tenant->id, ['role' => 'employee']);
        $this->presenterEmployee = Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $this->presenter->id,
            'name' => 'Nabil', 'status' => 'active', 'workload' => 'green',
        ]);
    }

    private function makeSlot(array $overrides = []): TotSession
    {
        return TotSession::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'year' => 2026, 'month' => 3,
            'presenter_employee_id' => $this->presenterEmployee->id,
            'title' => 'Install git on our own server',
            'status' => 'confirmed',
        ], $overrides));
    }

    public function test_a_blank_topic_nudges_the_presenter_fourteen_days_out(): void
    {
        $this->makeSlot(['title' => null]);

        // First Saturday of March 2026 is the 7th; 14 days before is 21 February.
        $this->travelTo('2026-02-21 08:00:00');
        $this->artisan('tot:remind')->assertExitCode(0);

        $this->assertDatabaseHas('app_notifications', [
            'user_id' => $this->presenter->id,
            'dedupe_key' => 'tot:'.TotSession::first()->id.':topic',
        ]);
    }

    public function test_a_slot_with_a_topic_is_not_nudged_fourteen_days_out(): void
    {
        $this->makeSlot();

        $this->travelTo('2026-02-21 08:00:00');
        $this->artisan('tot:remind');

        $this->assertSame(0, AppNotification::count());
    }

    public function test_the_presenter_is_reminded_seven_days_out(): void
    {
        $slot = $this->makeSlot();

        $this->travelTo('2026-02-28 08:00:00');
        $this->artisan('tot:remind');

        $this->assertDatabaseHas('app_notifications', [
            'user_id' => $this->presenter->id,
            'dedupe_key' => 'tot:'.$slot->id.':prepare',
        ]);
    }

    public function test_everyone_is_reminded_one_day_out(): void
    {
        $other = User::create(['name' => 'Emy', 'email' => 'emy@example.com', 'password' => Hash::make('password')]);
        $other->tenants()->attach($this->tenant->id, ['role' => 'employee']);
        Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $other->id,
            'name' => 'Emy', 'status' => 'active', 'workload' => 'green',
        ]);

        $slot = $this->makeSlot();

        $this->travelTo('2026-03-06 08:00:00');
        $this->artisan('tot:remind');

        $this->assertDatabaseHas('app_notifications', [
            'user_id' => $other->id, 'dedupe_key' => 'tot:'.$slot->id.':tomorrow',
        ]);
        $this->assertDatabaseHas('app_notifications', [
            'user_id' => $this->presenter->id, 'dedupe_key' => 'tot:'.$slot->id.':tomorrow',
        ]);
    }

    public function test_running_twice_on_the_same_day_notifies_once(): void
    {
        $this->makeSlot();

        $this->travelTo('2026-02-28 08:00:00');
        $this->artisan('tot:remind');
        $this->artisan('tot:remind');

        $this->assertSame(1, AppNotification::count());
    }

    public function test_a_skipped_or_non_tot_slot_never_fires(): void
    {
        $this->makeSlot(['status' => 'skipped']);
        TotSession::create([
            'tenant_id' => $this->tenant->id, 'year' => 2026, 'month' => 4,
            'title' => 'Jamuan raya', 'status' => 'not_tot',
        ]);

        $this->travelTo('2026-02-28 08:00:00');
        $this->artisan('tot:remind');

        $this->assertSame(0, AppNotification::count());
    }

    public function test_a_slot_with_no_employee_presenter_still_sends_the_all_hands_reminder(): void
    {
        $slot = $this->makeSlot(['presenter_employee_id' => null, 'presenter_name' => 'Team']);

        $this->travelTo('2026-03-06 08:00:00');
        $this->artisan('tot:remind');

        $this->assertDatabaseHas('app_notifications', [
            'user_id' => $this->presenter->id, 'dedupe_key' => 'tot:'.$slot->id.':tomorrow',
        ]);
    }
}
