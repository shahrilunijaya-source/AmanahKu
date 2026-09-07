<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\BirthdayWish;
use App\Models\BirthdayWishReaction;
use App\Models\Employee;
use App\Models\PublicHoliday;
use App\Models\Tenant;
use App\Models\User;
use App\Support\DashboardBands;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * CR-13: the dashboard birthday band, wishes, reactions, the 8 AM notice, the
 * profile Wall, and the private opt-out. Reference: Tuesday 8 Sep 2026 is an
 * ordinary day, Malaysia Day (16 Sep 2026) is a Wednesday holiday.
 */
class BirthdayWishesTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
    }

    private function signIn(string $role = 'employee', string $name = 'Emysha'): Employee
    {
        $user = User::create(['name' => $name, 'email' => strtolower($name).$role.'@acme.test', 'password' => Hash::make('password')]);
        $user->tenants()->attach($this->tenant->id, ['role' => $role]);
        $employee = Employee::create(['tenant_id' => $this->tenant->id, 'user_id' => $user->id, 'name' => $name, 'status' => 'active', 'workload' => 'green']);
        $this->actingAs($user)->withSession(['current_tenant' => $this->tenant->id]);

        return $employee;
    }

    private function colleague(string $name, string $dob): Employee
    {
        $user = User::create(['name' => $name, 'email' => strtolower(str_replace(' ', '', $name)).'@acme.test', 'password' => Hash::make('password')]);
        $user->tenants()->attach($this->tenant->id, ['role' => 'employee']);

        return Employee::create(['tenant_id' => $this->tenant->id, 'user_id' => $user->id, 'name' => $name, 'status' => 'active', 'workload' => 'green', 'date_of_birth' => $dob]);
    }

    /** ---- Band content ---- */
    public function test_birthday_today_shows_band_with_composer_for_a_colleague_and_thanks_for_self(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-08 09:00'));
        $viewer = $this->signIn();
        $celebrant = $this->colleague('Ahmad Yusof', '1990-09-08');

        $this->get('/app/dash')->assertOk()
            ->assertSee("It's Ahmad Yusof's birthday")
            ->assertSee('Write a wish')
            ->assertSee('Acme');

        // The celebrant sees "Say thanks", not the composer meant for colleagues.
        $this->actingAs($celebrant->user)->withSession(['current_tenant' => $this->tenant->id]);
        $this->get('/app/dash')->assertOk()->assertSee('Say thanks')->assertDontSee('Write a wish');
    }

    /** ---- The advance (weekend/holiday) case ---- */
    public function test_birthday_on_saturday_shows_on_friday_only(): void
    {
        $this->signIn();
        $this->colleague('Weekend Person', '1990-09-19'); // 19 Sep 2026 is a Saturday

        $this->travelTo(CarbonImmutable::parse('2026-09-17 09:00')); // Thursday
        $this->get('/app/dash')->assertOk()->assertDontSee("Weekend Person's birthday");

        $this->travelTo(CarbonImmutable::parse('2026-09-18 09:00')); // Friday, last working day
        $this->get('/app/dash')->assertOk()->assertSee("Weekend Person's birthday is on Sat 19 Sep");

        $this->travelTo(CarbonImmutable::parse('2026-09-21 09:00')); // Monday, next working day
        $this->get('/app/dash')->assertOk()->assertDontSee("Weekend Person's birthday");
    }

    public function test_birthday_on_a_holiday_monday_shows_on_the_preceding_friday(): void
    {
        PublicHoliday::create(['tenant_id' => $this->tenant->id, 'name' => 'Made-up Monday', 'date' => '2026-09-21']);
        $this->signIn();
        $this->colleague('Holiday Person', '1990-09-21');

        $this->travelTo(CarbonImmutable::parse('2026-09-18 09:00')); // Friday before the weekend+holiday run
        $this->get('/app/dash')->assertOk()->assertSee("Holiday Person's birthday is on Mon 21 Sep");
    }

    /** ---- Private opt-out ---- */
    public function test_private_employee_has_no_band_no_notification_and_is_not_in_upcoming(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-08 09:00'));
        $viewer = $this->signIn();
        $private = $this->colleague('Quiet Person', '1990-09-08');
        $private->update(['birthday_private' => true]);

        $response = $this->get('/app/dash')->assertOk()->assertDontSee('Quiet Person');
        $this->assertSame([], $response->viewData('bands')['moments']);

        Artisan::call('birthday:notify');
        $this->assertSame(0, AppNotification::count());

        $soon = $this->colleague('Soon Person', '1990-09-10');
        $soon->update(['birthday_private' => false]);
        $response = $this->get('/app/dash')->assertOk();
        $names = array_column($response->viewData('bands')['upcoming'], 'name');
        $this->assertContains('Soon Person', $names);

        $private->update(['date_of_birth' => '1990-09-10', 'birthday_private' => true]);
        $response = $this->get('/app/dash')->assertOk();
        $names = array_column($response->viewData('bands')['upcoming'], 'name');
        $this->assertNotContains('Quiet Person', $names);
    }

    /** ---- Wishes ---- */
    public function test_posting_a_wish_stores_it_and_renders_for_everyone_and_the_recipient(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-08 09:00'));
        $author = $this->signIn('employee', 'Author');
        $celebrant = $this->colleague('Birthday Person', '1990-09-08');

        $this->postJson(route('birthday.wish', $celebrant), ['body' => 'Happy birthday!'])
            ->assertOk()->assertJson(['ok' => true]);

        $this->assertDatabaseHas('birthday_wishes', [
            'employee_id' => $celebrant->id, 'author_id' => $author->id, 'body' => 'Happy birthday!',
        ]);

        $this->get('/app/dash')->assertOk()->assertSee('Happy birthday!');

        $this->actingAs($celebrant->user)->withSession(['current_tenant' => $this->tenant->id]);
        $this->get('/app/dash')->assertOk()->assertSee('Happy birthday!');
    }

    public function test_wishing_someone_not_celebrated_today_is_rejected(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-08 09:00'));
        $this->signIn();
        $notToday = $this->colleague('Not Today', '1990-09-09');

        $this->postJson(route('birthday.wish', $notToday), ['body' => 'Hi'])->assertStatus(422);
    }

    public function test_recipient_cannot_wish_self(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-08 09:00'));
        $celebrant = $this->signIn('employee', 'Celebrant');
        $celebrant->update(['date_of_birth' => '1990-09-08']);

        $this->postJson(route('birthday.wish', $celebrant), ['body' => 'Hi me'])->assertStatus(422);
    }

    /** ---- Thanks ---- */
    public function test_thanks_only_recipient_and_replaces_previous(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-08 09:00'));
        $stranger = $this->signIn('employee', 'Stranger');
        $celebrant = $this->colleague('Celebrant', '1990-09-08');

        $this->actingAs($stranger->user)->withSession(['current_tenant' => $this->tenant->id]);
        $this->postJson(route('birthday.thanks', $celebrant), ['body' => 'Not mine to say'])->assertStatus(403);

        $this->actingAs($celebrant->user)->withSession(['current_tenant' => $this->tenant->id]);
        $this->postJson(route('birthday.thanks', $celebrant), ['body' => 'Thanks all!'])->assertOk();
        $this->assertSame(1, BirthdayWish::where('employee_id', $celebrant->id)->where('is_thanks', true)->count());

        $this->postJson(route('birthday.thanks', $celebrant), ['body' => 'Thanks again!'])->assertOk();
        $this->assertSame(1, BirthdayWish::where('employee_id', $celebrant->id)->where('is_thanks', true)->count());
        $this->assertDatabaseHas('birthday_wishes', ['employee_id' => $celebrant->id, 'is_thanks' => true, 'body' => 'Thanks again!']);
    }

    /** ---- Reactions ---- */
    public function test_react_toggles_creates_undoes_and_replaces(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-08 09:00'));
        $author = $this->signIn('employee', 'Author');
        $celebrant = $this->colleague('Celebrant', '1990-09-08');
        $wish = BirthdayWish::create(['tenant_id' => $this->tenant->id, 'employee_id' => $celebrant->id, 'author_id' => $author->id, 'body' => 'Hi', 'celebrated_on' => '2026-09-08']);

        $this->postJson(route('birthday.react', $wish), ['emoji' => '👍'])->assertOk();
        $this->assertSame(1, BirthdayWishReaction::where('wish_id', $wish->id)->where('employee_id', $author->id)->count());

        $this->postJson(route('birthday.react', $wish), ['emoji' => '👍'])->assertOk();
        $this->assertSame(0, BirthdayWishReaction::where('wish_id', $wish->id)->count());

        $this->postJson(route('birthday.react', $wish), ['emoji' => '👍'])->assertOk();
        $this->postJson(route('birthday.react', $wish), ['emoji' => '🔥'])->assertOk();
        $reactions = BirthdayWishReaction::where('wish_id', $wish->id)->where('employee_id', $author->id)->get();
        $this->assertCount(1, $reactions);
        $this->assertSame('🔥', $reactions->first()->emoji);
    }

    /** ---- Cross-tenant: route-model binding is not tenant-scoped, controller must check ---- */
    public function test_cross_tenant_wish_thanks_and_react_are_rejected(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-08 09:00'));
        $this->signIn();

        $otherTenant = Tenant::create(['slug' => 'other', 'name' => 'Other Co', 'initials' => 'OC']);
        $otherUser = User::create(['name' => 'Outsider', 'email' => 'outsider@other.test', 'password' => Hash::make('password')]);
        $otherUser->tenants()->attach($otherTenant->id, ['role' => 'employee']);
        $otherCelebrant = Employee::create([
            'tenant_id' => $otherTenant->id, 'user_id' => $otherUser->id, 'name' => 'Other Celebrant',
            'status' => 'active', 'workload' => 'green', 'date_of_birth' => '1990-09-08',
        ]);
        $otherWish = BirthdayWish::create([
            'tenant_id' => $otherTenant->id, 'employee_id' => $otherCelebrant->id, 'author_id' => $otherCelebrant->id,
            'body' => 'Cross tenant', 'celebrated_on' => '2026-09-08',
        ]);

        $this->postJson(route('birthday.wish', $otherCelebrant), ['body' => 'Hi'])->assertStatus(404);
        $this->postJson(route('birthday.thanks', $otherCelebrant), ['body' => 'Hi'])->assertStatus(404);
        $this->postJson(route('birthday.react', $otherWish), ['emoji' => '👍'])->assertStatus(404);
    }

    /** ---- Next day: band gone, wish appears on the Wall ---- */
    public function test_next_day_band_is_gone_and_wish_shows_on_the_wall(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-08 09:00'));
        $author = $this->signIn('employee', 'Author');
        $celebrant = $this->colleague('Celebrant', '1990-09-08');
        $this->postJson(route('birthday.wish', $celebrant), ['body' => 'Cheers!'])->assertOk();

        $this->travelTo(CarbonImmutable::parse('2026-09-09 09:00'));
        $this->get('/app/dash')->assertOk()->assertDontSee('uj-db-band', false);

        // View the celebrant's own profile — canViewFull always holds for your own record.
        $this->actingAs($celebrant->user)->withSession(['current_tenant' => $this->tenant->id]);
        $this->get('/app/profile')->assertOk()->assertSee('Cheers!')->assertSee('Wall');
    }

    /** ---- Upcoming line ---- */
    public function test_upcoming_line_lists_next_seven_days_in_order_excluding_today_and_private(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-08 09:00'));
        $this->signIn();
        $this->colleague('Today Person', '1990-09-08');
        $this->colleague('In Three', '1990-09-11');
        $this->colleague('In One', '1990-09-09');
        $this->colleague('Too Far', '1990-09-20');

        $response = $this->get('/app/dash')->assertOk();
        $upcoming = $response->viewData('bands')['upcoming'];
        $names = array_column($upcoming, 'name');

        $this->assertSame(['In One', 'In Three'], $names);
        $this->assertNotContains('Today Person', $names);
        $this->assertNotContains('Too Far', $names);
    }

    /** ---- birthday:notify ---- */
    public function test_birthday_notify_dedupes_and_skips_the_celebrant(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-08 09:00'));
        $celebrant = $this->colleague('Celebrant', '1990-09-08');
        $other = $this->colleague('Other', '1990-01-01');

        $this->artisan('birthday:notify')->assertSuccessful();
        $this->assertSame(1, AppNotification::where('user_id', $other->user_id)->count());
        $this->assertSame(0, AppNotification::where('user_id', $celebrant->user_id)->count());

        $this->artisan('birthday:notify')->assertSuccessful();
        $this->assertSame(1, AppNotification::where('user_id', $other->user_id)->count());
    }

    /** ---- Pure unit test of the rule ---- */
    public function test_celebrated_on_rule_with_a_fake_working_day(): void
    {
        // Friday working, Sat/Sun not, Monday working again.
        $isWorkingDay = fn (CarbonImmutable $d): bool => ! in_array($d->dayOfWeekIso, [6, 7], true);

        $friday = CarbonImmutable::parse('2026-09-18'); // Friday
        $dates = DashboardBands::celebratedOn($friday, $isWorkingDay);
        $this->assertCount(3, $dates); // Fri, Sat, Sun
        $this->assertSame(['2026-09-18', '2026-09-19', '2026-09-20'], array_map(fn ($d) => $d->toDateString(), $dates));

        $saturday = CarbonImmutable::parse('2026-09-19');
        $this->assertSame(['2026-09-19'], array_map(fn ($d) => $d->toDateString(), DashboardBands::celebratedOn($saturday, $isWorkingDay)));

        $monday = CarbonImmutable::parse('2026-09-21');
        $this->assertSame(['2026-09-21'], array_map(fn ($d) => $d->toDateString(), DashboardBands::celebratedOn($monday, $isWorkingDay)));
    }
}
