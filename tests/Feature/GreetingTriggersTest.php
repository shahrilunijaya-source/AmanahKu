<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\GreetingLine;
use App\Models\LeaveRequest;
use App\Models\PublicHoliday;
use App\Models\Tenant;
use App\Models\User;
use App\Support\GreetingBank;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * CR-33: one test per new trigger added to activeGreetingTriggers()/GreetingBank, plus
 * the deletion migration that removes the old overdue/not_clocked_in lines. Every
 * scenario below is deliberately built so no OTHER trigger of equal or higher bucket
 * priority (personal > situation > day > time) can also be active, so the picked line's
 * trigger can only be the one under test.
 */
class GreetingTriggersTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $signedInUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        GreetingBank::seed($this->tenant->id);
    }

    private function signIn(string $name = 'Aminah', array $employeeAttrs = []): Employee
    {
        $user = User::create(['name' => $name, 'email' => strtolower($name).'@acme.test', 'password' => Hash::make('password')]);
        $user->tenants()->attach($this->tenant->id, ['role' => 'employee']);
        $employee = Employee::create(array_merge([
            'tenant_id' => $this->tenant->id, 'user_id' => $user->id,
            'name' => $name, 'status' => 'active', 'workload' => 'green',
        ], $employeeAttrs));
        $this->actingAs($user)->withSession(['current_tenant' => $this->tenant->id]);
        $this->signedInUser = $user;

        return $employee;
    }

    /** The approved bank row a rendered heading came from, `{name}` filled in. */
    private function lineFor(string $h1, string $firstName): GreetingLine
    {
        $line = GreetingLine::where('tenant_id', $this->tenant->id)->approved()->get()
            ->first(fn (GreetingLine $l) => str_replace('{name}', $firstName, $l->text_en) === $h1);
        $this->assertNotNull($line, "heading is not an approved bank line: {$h1}");

        return $line;
    }

    public function test_early_trigger_fires_before_8am(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-08 07:00', 'Asia/Kuala_Lumpur')); // Tuesday
        $this->signIn();

        $head = $this->get('/app/dash')->assertOk()->viewData('head');

        $this->assertSame('early', $this->lineFor($head['h1'], 'Aminah')->trigger);
    }

    public function test_wednesday_trigger_beats_the_time_bucket(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-09 10:00', 'Asia/Kuala_Lumpur')); // Wednesday
        $this->signIn();

        $head = $this->get('/app/dash')->assertOk()->viewData('head');

        $this->assertSame('wednesday', $this->lineFor($head['h1'], 'Aminah')->trigger);
    }

    public function test_saturday_trigger_is_distinct_from_weekend(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-12 10:00', 'Asia/Kuala_Lumpur')); // Saturday
        $this->signIn();

        $head = $this->get('/app/dash')->assertOk()->viewData('head');

        $this->assertSame('saturday', $this->lineFor($head['h1'], 'Aminah')->trigger);
    }

    public function test_month_start_fires_once_on_the_first_load_of_the_month(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-01 10:00', 'Asia/Kuala_Lumpur')); // Thursday
        $this->signIn();

        $first = $this->get('/app/dash')->assertOk()->viewData('head');
        $this->assertSame('month_start', $this->lineFor($first['h1'], 'Aminah')->trigger);

        $second = $this->get('/app/dash')->assertOk()->viewData('head');
        $this->assertNotSame('month_start', $this->lineFor($second['h1'], 'Aminah')->trigger, 'month_start fired twice in one browser session');
    }

    public function test_all_clear_fires_only_with_open_cards_and_none_overdue(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-09 10:00', 'Asia/Kuala_Lumpur')); // Wednesday
        $employee = $this->signIn();
        $employee->workItems()->create([
            'tenant_id' => $this->tenant->id, 'title' => 'Not due yet', 'type' => 'task',
            'priority' => 'low', 'status' => 'todo', 'progress' => 0, 'due_at' => '2026-12-01',
        ]);

        $head = $this->get('/app/dash')->assertOk()->viewData('head');

        $this->assertSame('all_clear', $this->lineFor($head['h1'], 'Aminah')->trigger);
    }

    public function test_all_clear_does_not_fire_with_no_open_cards_at_all(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-09 10:00', 'Asia/Kuala_Lumpur')); // Wednesday, no cards
        $this->signIn();

        $head = $this->get('/app/dash')->assertOk()->viewData('head');

        $this->assertSame('wednesday', $this->lineFor($head['h1'], 'Aminah')->trigger);
    }

    public function test_long_weekend_fires_when_a_holiday_adjoins_the_coming_weekend(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-07 10:00', 'Asia/Kuala_Lumpur')); // Monday
        PublicHoliday::create(['tenant_id' => $this->tenant->id, 'name' => 'Bridge day', 'date' => '2026-09-11']); // that week's Friday
        $this->signIn();

        $head = $this->get('/app/dash')->assertOk()->viewData('head');

        $this->assertSame('long_weekend', $this->lineFor($head['h1'], 'Aminah')->trigger);
    }

    public function test_anniversary_fires_on_the_joined_at_month_and_day(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-09 10:00', 'Asia/Kuala_Lumpur')); // Wednesday
        $this->signIn('Aminah', ['joined_at' => '2020-09-09']);

        $head = $this->get('/app/dash')->assertOk()->viewData('head');

        $this->assertSame('anniversary', $this->lineFor($head['h1'], 'Aminah')->trigger);
    }

    public function test_back_from_leave_fires_once_after_an_approved_leave_that_ended_in_the_past(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-09 10:00', 'Asia/Kuala_Lumpur')); // Wednesday
        $employee = $this->signIn();
        LeaveRequest::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $employee->id,
            'date_from' => '2026-09-05', 'date_to' => '2026-09-08', 'status' => 'approved',
        ]);

        $first = $this->get('/app/dash')->assertOk()->viewData('head');
        $this->assertSame('back_from_leave', $this->lineFor($first['h1'], 'Aminah')->trigger);

        $second = $this->get('/app/dash')->assertOk()->viewData('head');
        $this->assertNotSame('back_from_leave', $this->lineFor($second['h1'], 'Aminah')->trigger, 'back_from_leave fired twice in one browser session');
    }

    public function test_rain_never_fires_even_when_the_weather_flag_is_enabled(): void
    {
        config(['services.weather.enabled' => true]);
        $this->travelTo(CarbonImmutable::parse('2026-09-09 10:00', 'Asia/Kuala_Lumpur')); // Wednesday
        $this->signIn();

        for ($i = 0; $i < 5; $i++) {
            $head = $this->get('/app/dash')->assertOk()->viewData('head');
            $this->assertNotSame('rain', $this->lineFor($head['h1'], 'Aminah')->trigger, 'rain fired with no weather source wired up');
        }
    }

    public function test_deletion_migration_removes_overdue_and_not_clocked_in_and_backfills_new_lines(): void
    {
        // A tenant seeded BEFORE this session, carrying only a pre-CR33-style bank
        // (no early/wednesday/... lines yet, still has the two retired triggers).
        $old = Tenant::create(['slug' => 'globex', 'name' => 'Globex', 'initials' => 'GX']);
        $now = now();
        GreetingLine::query()->insert([
            ['tenant_id' => $old->id, 'bucket' => 'time', 'trigger' => 'morning', 'text_en' => 'Morning, {name}.', 'text_ms' => 'Pagi, {name}.', 'approved_at' => $now, 'created_at' => $now, 'updated_at' => $now],
            ['tenant_id' => $old->id, 'bucket' => 'situation', 'trigger' => 'overdue', 'text_en' => 'Old overdue line', 'text_ms' => 'Baris lama', 'approved_at' => $now, 'created_at' => $now, 'updated_at' => $now],
            ['tenant_id' => $old->id, 'bucket' => 'situation', 'trigger' => 'not_clocked_in', 'text_en' => 'Old clock-in line', 'text_ms' => 'Baris lama 2', 'approved_at' => $now, 'created_at' => $now, 'updated_at' => $now],
        ]);
        $this->assertFalse(GreetingLine::where('tenant_id', $old->id)->where('trigger', 'early')->exists());

        (require base_path('database/migrations/2026_09_09_100000_cr33_greeting_bank_refresh.php'))->up();

        $this->assertFalse(GreetingLine::where('tenant_id', $old->id)->whereIn('trigger', ['overdue', 'not_clocked_in'])->exists(), 'retired triggers were not removed');
        $this->assertTrue(GreetingLine::where('tenant_id', $old->id)->where('trigger', 'early')->exists(), 'new trigger lines were not backfilled');
        $this->assertTrue(GreetingLine::where('tenant_id', $old->id)->where('text_en', 'Morning, {name}.')->count() === 1, 'an already-present line was duplicated');

        // Idempotent: running it again changes nothing further.
        $countAfterFirstRun = GreetingLine::where('tenant_id', $old->id)->count();
        (require base_path('database/migrations/2026_09_09_100000_cr33_greeting_bank_refresh.php'))->up();
        $this->assertSame($countAfterFirstRun, GreetingLine::where('tenant_id', $old->id)->count());
    }
}
