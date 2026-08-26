<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\Employee;
use App\Models\Tenant;
use App\Models\TotSession;
use App\Models\User;
use App\Notifications\TotTomorrowMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
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
            // The key carries the employee id so each member of a team gets their own bell.
            'dedupe_key' => 'tot:'.TotSession::first()->id.':topic:'.$this->presenterEmployee->id,
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
            'dedupe_key' => 'tot:'.$slot->id.':prepare:'.$this->presenterEmployee->id,
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

    public function test_the_day_before_reminder_is_also_emailed_to_everyone_once(): void
    {
        Notification::fake();

        $slot = $this->makeSlot([
            'links' => [['label' => 'Google Meet', 'url' => 'https://meet.google.com/kby-hmzt-ouc']],
        ]);

        $this->travelTo('2026-03-06 08:00:00');
        $this->artisan('tot:remind');
        $this->artisan('tot:remind');

        Notification::assertSentToTimes($this->presenter, TotTomorrowMail::class, 1);
    }

    public function test_the_tomorrow_email_carries_the_presenter_topic_and_meet_link(): void
    {
        $this->presenterEmployee->update(['phone' => '+60 12-737 6973']);

        $slot = $this->makeSlot([
            'title' => 'PostgREST - Building REST APIs Directly from PostgreSQL',
            'links' => [
                ['label' => 'Slides', 'url' => 'https://example.com/slides'],
                ['label' => 'Meet', 'url' => 'https://meet.google.com/kby-hmzt-ouc'],
            ],
        ]);

        $mail = (new TotTomorrowMail($slot, 'https://amanahku.test/app/tot'))
            ->toMail($this->presenter);

        $body = implode("\n", array_merge($mail->introLines, $mail->outroLines));

        $this->assertStringContainsString('Nabil', $body);
        $this->assertStringContainsString('+60 12-737 6973', $body);
        $this->assertStringContainsString('PostgREST', $body);
        $this->assertStringContainsString('Sabtu, 07 Mac 2026', $body);
        $this->assertStringContainsString('10:30 pagi – 11:00 pagi', $body);
        $this->assertSame('https://meet.google.com/kby-hmzt-ouc', $mail->actionUrl);
    }

    public function test_the_tomorrow_email_uses_the_hour_stored_on_the_slot(): void
    {
        $slot = $this->makeSlot(['starts_at' => '14:00', 'ends_at' => '15:30']);

        $mail = (new TotTomorrowMail($slot->fresh(), 'https://amanahku.test/app/tot'))
            ->toMail($this->presenter);

        $body = implode("\n", $mail->introLines);

        $this->assertStringContainsString('bermula tepat pada 2:00 petang', $body);
        $this->assertStringContainsString('2:00 petang – 3:30 petang', $body);
    }

    public function test_the_tomorrow_email_falls_back_to_the_board_when_there_is_no_meet_link(): void
    {
        $mail = (new TotTomorrowMail($this->makeSlot(), 'https://amanahku.test/app/tot'))
            ->toMail($this->presenter);

        $this->assertSame('https://amanahku.test/app/tot', $mail->actionUrl);
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

    public function test_nothing_is_sent_eight_days_out(): void
    {
        $this->makeSlot();

        // First Saturday of March 2026 is the 7th; 7 days before is 28 February.
        // 8 days out (one day before that boundary) must not fire.
        $this->travelTo('2026-02-27 08:00:00');
        $this->artisan('tot:remind');

        $this->assertSame(0, AppNotification::count());
    }

    public function test_nothing_is_sent_six_days_out(): void
    {
        $this->makeSlot();

        // 6 days out (one day after the 7-day boundary) must not fire.
        $this->travelTo('2026-03-01 08:00:00');
        $this->artisan('tot:remind');

        $this->assertSame(0, AppNotification::count());
    }

    public function test_a_probation_status_employee_receives_the_all_hands_reminder(): void
    {
        $newHire = User::create(['name' => 'Fresh', 'email' => 'fresh@example.com', 'password' => Hash::make('password')]);
        $newHire->tenants()->attach($this->tenant->id, ['role' => 'employee']);
        Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $newHire->id,
            'name' => 'Fresh', 'status' => 'probation', 'workload' => 'green',
        ]);

        $slot = $this->makeSlot();

        $this->travelTo('2026-03-06 08:00:00');
        $this->artisan('tot:remind');

        $this->assertDatabaseHas('app_notifications', [
            'user_id' => $newHire->id, 'dedupe_key' => 'tot:'.$slot->id.':tomorrow',
        ]);
    }

    public function test_an_archived_employee_does_not_receive_the_all_hands_reminder(): void
    {
        $departed = User::create(['name' => 'Gone', 'email' => 'gone@example.com', 'password' => Hash::make('password')]);
        $departed->tenants()->attach($this->tenant->id, ['role' => 'employee']);
        Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $departed->id,
            'name' => 'Gone', 'status' => 'active', 'workload' => 'green',
            'archived_at' => now(),
        ]);

        $slot = $this->makeSlot();

        $this->travelTo('2026-03-06 08:00:00');
        $this->artisan('tot:remind');

        $this->assertDatabaseMissing('app_notifications', [
            'user_id' => $departed->id, 'dedupe_key' => 'tot:'.$slot->id.':tomorrow',
        ]);
    }
}
