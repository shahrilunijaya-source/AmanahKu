<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Attendance\HolidayEve;
use App\Models\AppNotification;
use App\Models\Employee;
use App\Models\PublicHoliday;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * CR-20: the last clock-out before a public holiday greets the person once, and the
 * 5:30 PM sweep catches anyone who did not clock out. Reference: Malaysia Day,
 * Wednesday 16 Sep 2026.
 */
class HolidayEveGreetingTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    private User $hr;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $this->tenant = Tenant::create(['slug' => 'alpha', 'name' => 'Alpha', 'initials' => 'AL']);
        app(CurrentTenant::class)->set($this->tenant);

        $this->user = User::create(['name' => 'Worker', 'email' => 'worker@example.com', 'password' => Hash::make('password')]);
        $this->user->tenants()->attach($this->tenant->id, ['role' => 'employee']);
        $this->employee = Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $this->user->id,
            'name' => 'Worker', 'status' => 'active', 'workload' => 'green',
        ]);

        $this->hr = User::create(['name' => 'Boss', 'email' => 'boss@example.com', 'password' => Hash::make('password')]);
        $this->hr->tenants()->attach($this->tenant->id, ['role' => 'hr']);
    }

    protected function tearDown(): void
    {
        app(CurrentTenant::class)->set(null);
        parent::tearDown();
    }

    private function holiday(string $date, string $name = 'Malaysia Day', array $extra = []): PublicHoliday
    {
        return PublicHoliday::create(['tenant_id' => $this->tenant->id, 'name' => $name, 'date' => $date] + $extra);
    }

    private function punch(string $action): TestResponse
    {
        return $this->actingAs($this->user)
            ->withSession(['current_tenant' => $this->tenant->id])
            ->post('/app/attendance/clock', [
                'action' => $action, 'latitude' => 3.1073, 'longitude' => 101.6067,
                'photo' => UploadedFile::fake()->image('selfie.jpg'),
            ]);
    }

    // ---- resolver ----------------------------------------------------------

    public function test_the_day_before_a_midweek_holiday_is_its_eve(): void
    {
        $this->holiday('2026-09-16');

        $eve = app(HolidayEve::class)->forDay(CarbonImmutable::parse('2026-09-15'));

        $this->assertSame('Malaysia Day', $eve['holiday']->name);
        $this->assertSame('2026-09-17', $eve['next_working_day']->toDateString());
    }

    public function test_two_days_before_is_not_the_eve(): void
    {
        $this->holiday('2026-09-16');

        $this->assertNull(app(HolidayEve::class)->forDay(CarbonImmutable::parse('2026-09-14')));
    }

    public function test_friday_is_the_eve_of_a_monday_holiday(): void
    {
        $this->holiday('2026-09-21', 'Made-up Monday');

        $eve = app(HolidayEve::class)->forDay(CarbonImmutable::parse('2026-09-18'));

        $this->assertSame('Made-up Monday', $eve['holiday']->name);
        $this->assertSame('2026-09-22', $eve['next_working_day']->toDateString());
    }

    public function test_a_run_of_holidays_reports_the_first_and_the_real_return_day(): void
    {
        $this->holiday('2026-09-17', 'Day one');
        $this->holiday('2026-09-18', 'Day two');

        $eve = app(HolidayEve::class)->forDay(CarbonImmutable::parse('2026-09-16'));

        $this->assertSame('Day one', $eve['holiday']->name);
        $this->assertSame('2026-09-21', $eve['next_working_day']->toDateString());
    }

    public function test_a_blank_greeting_falls_back_to_the_other_language_then_generic(): void
    {
        $blank = $this->holiday('2026-09-16');
        $msOnly = $this->holiday('2026-09-17', 'Other', ['greeting_ms' => 'Selamat Hari Malaysia!']);
        $next = CarbonImmutable::parse('2026-09-18');

        $generic = app(HolidayEve::class)->payload($blank, $next);
        $this->assertSame(HolidayEve::GENERIC_EN, $generic['greeting_en']);
        $this->assertSame(HolidayEve::GENERIC_MS, $generic['greeting_ms']);
        $this->assertFalse($generic['curated']);

        $curated = app(HolidayEve::class)->payload($msOnly, $next);
        $this->assertSame('Selamat Hari Malaysia!', $curated['greeting_en']);
        $this->assertSame('Selamat Hari Malaysia!', $curated['greeting_ms']);
        $this->assertTrue($curated['curated']);
    }

    // ---- clock-out ---------------------------------------------------------

    public function test_clocking_out_on_the_eve_flashes_the_greeting_once(): void
    {
        $this->holiday('2026-09-16', 'Malaysia Day', ['greeting_en' => 'Selamat Hari Malaysia, see you Thursday.']);
        $this->travelTo(CarbonImmutable::parse('2026-09-15 09:00:00'));
        $this->punch('in')->assertSessionHas('clock_ok');

        $this->travelTo(CarbonImmutable::parse('2026-09-15 18:00:00'));
        $first = $this->punch('out');
        $first->assertSessionHas('clock_ok');
        $first->assertSessionHas('holiday_eve', fn (array $eve): bool => $eve['name'] === 'Malaysia Day'
            && $eve['greeting_en'] === 'Selamat Hari Malaysia, see you Thursday.'
            && $eve['next_working_day'] === '2026-09-17');
        $this->assertSame(1, AppNotification::where('user_id', $this->user->id)->where('dedupe_key', 'holiday-eve-1')->count());

        // The day's record is wiped (an admin correction) and the person punches again:
        // the day is still the eve, but the greeting is spent.
        $this->employee->attendanceRecords()->delete();
        $this->travelTo(CarbonImmutable::parse('2026-09-15 18:30:00'));
        $this->punch('in');
        $this->travelTo(CarbonImmutable::parse('2026-09-15 19:00:00'));
        $second = $this->punch('out');
        $second->assertSessionHas('clock_ok');
        $second->assertSessionMissing('holiday_eve');
        $this->assertSame(1, AppNotification::where('user_id', $this->user->id)->count());
    }

    public function test_clocking_out_on_an_ordinary_day_greets_nobody(): void
    {
        $this->holiday('2026-09-16');
        $this->travelTo(CarbonImmutable::parse('2026-09-10 09:00:00'));
        $this->punch('in');
        $this->travelTo(CarbonImmutable::parse('2026-09-10 18:00:00'));

        $this->punch('out')->assertSessionMissing('holiday_eve');
        $this->assertSame(0, AppNotification::count());
    }

    public function test_the_greeting_overlay_renders_on_the_attendance_screen(): void
    {
        $this->holiday('2026-09-16', 'Malaysia Day', ['greeting_en' => 'Selamat Hari Malaysia, see you Thursday.']);
        $this->travelTo(CarbonImmutable::parse('2026-09-15 09:00:00'));
        $this->punch('in');
        $this->travelTo(CarbonImmutable::parse('2026-09-15 18:00:00'));

        $this->from('/app/attendance')->followingRedirects()->punch('out')
            ->assertOk()
            ->assertSee('uj-hv-stamp', false)
            ->assertSee('Malaysia Day')
            ->assertSee('Selamat Hari Malaysia, see you Thursday.')
            ->assertSee('Thu 17 Sep');
    }

    // ---- sweep -------------------------------------------------------------

    public function test_the_sweep_notifies_everyone_once_and_skips_those_already_greeted(): void
    {
        $this->holiday('2026-09-16');
        $other = User::create(['name' => 'Other', 'email' => 'other@example.com', 'password' => Hash::make('password')]);
        $other->tenants()->attach($this->tenant->id, ['role' => 'employee']);
        Employee::create(['tenant_id' => $this->tenant->id, 'user_id' => $other->id, 'name' => 'Other', 'status' => 'active', 'workload' => 'green']);
        Employee::create(['tenant_id' => $this->tenant->id, 'name' => 'Left', 'status' => 'archived', 'workload' => 'green']);

        $this->travelTo(CarbonImmutable::parse('2026-09-15 09:00:00'));
        $this->punch('in');
        $this->travelTo(CarbonImmutable::parse('2026-09-15 17:00:00'));
        $this->punch('out');
        app(CurrentTenant::class)->set(null);

        $this->travelTo(CarbonImmutable::parse('2026-09-15 17:30:00'));
        Artisan::call('attendance:holiday-eve');
        Artisan::call('attendance:holiday-eve');

        $this->assertSame(2, AppNotification::count());
        $this->assertSame(1, AppNotification::where('user_id', $this->user->id)->count());
        $this->assertSame(1, AppNotification::where('user_id', $other->id)->count());
    }

    public function test_the_sweep_does_nothing_on_an_ordinary_day(): void
    {
        $this->holiday('2026-09-16');
        app(CurrentTenant::class)->set(null);
        $this->travelTo(CarbonImmutable::parse('2026-09-10 17:30:00'));

        Artisan::call('attendance:holiday-eve');

        $this->assertSame(0, AppNotification::count());
    }

    public function test_the_sweep_is_scheduled_on_weekday_evenings(): void
    {
        Artisan::call('schedule:list');
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($e): bool => str_contains((string) $e->command, 'attendance:holiday-eve'));

        $this->assertNotNull($event);
        $this->assertSame('30 17 * * 1-5', $event->expression);
    }

    // ---- HR --------------------------------------------------------------------

    public function test_hr_adds_a_holiday_with_greetings_and_edits_them_later(): void
    {
        $hr = fn () => $this->actingAs($this->hr)->withSession(['current_tenant' => $this->tenant->id]);

        $hr()->post('/app/leave-setup/holidays', [
            'name' => 'Malaysia Day', 'date' => '2026-09-16', 'greeting_en' => 'See you Thursday.', 'greeting_ms' => '',
        ])->assertRedirect();
        $holiday = PublicHoliday::firstOrFail();
        $this->assertSame('See you Thursday.', $holiday->greeting_en);
        $this->assertNull($holiday->greeting_ms);

        $hr()->post("/app/leave-setup/holidays/{$holiday->id}/greeting", [
            'greeting_en' => 'Have a good one.', 'greeting_ms' => 'Selamat bercuti.',
        ])->assertRedirect();
        $this->assertSame(['Have a good one.', 'Selamat bercuti.'], [$holiday->fresh()->greeting_en, $holiday->fresh()->greeting_ms]);

        $hr()->post("/app/leave-setup/holidays/{$holiday->id}/greeting", ['greeting_en' => str_repeat('x', 201)])
            ->assertSessionHasErrors('greeting_en');
    }

    public function test_an_employee_cannot_edit_a_greeting_and_hr_cannot_edit_another_tenants(): void
    {
        $holiday = $this->holiday('2026-09-16');
        $this->actingAs($this->user)->withSession(['current_tenant' => $this->tenant->id])
            ->post("/app/leave-setup/holidays/{$holiday->id}/greeting", ['greeting_en' => 'nope'])
            ->assertForbidden();

        $foreign = PublicHoliday::create(['tenant_id' => Tenant::create(['slug' => 'beta', 'name' => 'Beta', 'initials' => 'BE'])->id, 'name' => 'Theirs', 'date' => '2026-09-16']);
        $this->actingAs($this->hr)->withSession(['current_tenant' => $this->tenant->id])
            ->post("/app/leave-setup/holidays/{$foreign->id}/greeting", ['greeting_en' => 'nope'])
            ->assertNotFound();
        $this->assertNull($foreign->fresh()->greeting_en);
    }
}
