<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\PublicHoliday;
use App\Models\Tenant;
use App\Models\User;
use App\Support\DashboardBands;
use App\Support\DashboardWidgets;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * CR-32: the placement rule. Bands above the grid only when something is active,
 * new cards anchored after a named card, and "Keep it plain" saved with the prefs.
 * Reference: Tuesday 8 Sep 2026 is an ordinary day; Malaysia Day is Wed 16 Sep.
 */
class DashboardBandsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
    }

    private function signIn(string $role = 'employee'): User
    {
        $user = User::create(['name' => 'Emysha', 'email' => $role.'@acme.test', 'password' => Hash::make('password')]);
        $user->tenants()->attach($this->tenant->id, ['role' => $role]);
        Employee::create(['tenant_id' => $this->tenant->id, 'user_id' => $user->id, 'name' => 'Emysha', 'status' => 'active', 'workload' => 'green']);
        $this->actingAs($user)->withSession(['current_tenant' => $this->tenant->id]);

        return $user;
    }

    /** Acceptance 1: an ordinary Tuesday renders no band markup at all. */
    public function test_an_ordinary_day_renders_no_bands(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-08 10:00:00'));
        PublicHoliday::create(['tenant_id' => $this->tenant->id, 'name' => 'Malaysia Day', 'date' => '2026-09-16']);
        $this->signIn();

        $response = $this->get('/app/dash')->assertOk()->assertDontSee('uj-db-band', false);

        $bands = $response->viewData('bands');
        $this->assertNull($bands['moments']);
        $this->assertNull($bands['management']);
        $this->assertNull($bands['awards']);
    }

    /** Acceptance 2: the director sees no band either until CR-17 fills the slot. */
    public function test_a_director_gets_no_band_yet_on_an_ordinary_day(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-08 10:00:00'));
        $this->signIn('director');

        $this->get('/app/dash')->assertOk()->assertDontSee('uj-db-band', false);
    }

    /** The holiday-eve moment is the first band occupant: shown on the eve, gone after. */
    public function test_the_holiday_eve_moment_shows_on_the_eve_only(): void
    {
        PublicHoliday::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Malaysia Day', 'date' => '2026-09-16',
            'greeting_en' => 'Selamat Hari Malaysia.', 'greeting_ms' => 'Selamat Hari Malaysia!',
        ]);
        $this->signIn();

        $this->travelTo(CarbonImmutable::parse('2026-09-15 10:00:00'));
        $this->get('/app/dash')->assertOk()
            ->assertSee('uj-db-moment', false)
            ->assertSee('data-kind="holiday-eve"', false)
            ->assertSee('Malaysia Day tomorrow')
            ->assertSee('Selamat Hari Malaysia. See you Thu 17 Sep.')
            ->assertSee('uj-db-stamp', false);

        $this->travelTo(CarbonImmutable::parse('2026-09-16 10:00:00'));
        $this->get('/app/dash')->assertOk()->assertDontSee('uj-db-band', false);
    }

    /** Several active moments share one slot, rotating by day rather than stacking. */
    public function test_several_moments_rotate_by_day_in_one_slot(): void
    {
        $a = ['kind' => 'a', 'kicker' => ['en' => '', 'ms' => ''], 'title' => ['en' => 'A', 'ms' => 'A'], 'sub' => ['en' => '', 'ms' => ''], 'cta' => null, 'art' => null];
        $b = ['kind' => 'b'] + $a;

        $day1 = DashboardBands::compose([$a, $b], null, null, CarbonImmutable::parse('2026-09-15'));
        $day2 = DashboardBands::compose([$a, $b], null, null, CarbonImmutable::parse('2026-09-16'));

        $this->assertSame(2, $day1['moments_count']);
        $this->assertNotSame($day1['moments']['kind'], $day2['moments']['kind']);
        $this->assertNull(DashboardBands::compose([], null, null, CarbonImmutable::parse('2026-09-15'))['moments']);
    }

    /** Keep it plain drops the ornament but keeps the words. */
    public function test_keep_it_plain_strips_the_ornament(): void
    {
        PublicHoliday::create(['tenant_id' => $this->tenant->id, 'name' => 'Malaysia Day', 'date' => '2026-09-16']);
        $user = $this->signIn();
        $user->dashboard_prefs = ['dash' => ['hidden' => [], 'order' => [], 'plain' => true]];
        $user->save();

        $this->travelTo(CarbonImmutable::parse('2026-09-15 10:00:00'));
        $this->get('/app/dash')->assertOk()
            ->assertSee('Malaysia Day tomorrow')
            ->assertSee('<div class="uj-db" data-plain="">', false)
            ->assertDontSee('uj-db-stamp', false);
    }

    /** The flag rides along with the prefs save and survives a save that omits it. */
    public function test_keep_it_plain_is_saved_with_the_prefs(): void
    {
        $user = $this->signIn();

        $this->postJson(route('dashboard.prefs.update'), ['hidden' => [], 'order' => [], 'plain' => true])->assertOk();
        $this->assertTrue($user->fresh()->dashboard_prefs['dash']['plain']);

        $this->postJson(route('dashboard.prefs.update'), ['hidden' => ['work'], 'order' => []])->assertOk();
        $this->assertTrue($user->fresh()->dashboard_prefs['dash']['plain']);

        $this->postJson(route('dashboard.prefs.update'), ['hidden' => [], 'order' => [], 'plain' => 'nope'])->assertStatus(422);
    }

    /** A widget with an `after` anchor lands right under that card by default, not at the bottom. */
    public function test_an_anchored_widget_sits_after_its_anchor(): void
    {
        $registry = DashboardWidgets::ALL + [
            'signoff' => ['column' => 'left', 'after' => 'tasks'],
            'events' => ['column' => 'right', 'after' => 'attendance'],
            'orphan' => ['column' => 'right', 'after' => 'not-shown'],
        ];
        $available = ['summary', 'clock', 'tasks', 'leave', 'signoff', 'calendar', 'notices', 'events', 'orphan'];

        $layout = DashboardWidgets::layoutWith($registry, $available, [], []);

        $this->assertSame(['summary', 'clock', 'tasks', 'signoff', 'leave'], $layout['left']);
        // Anchor not on the page (role/module gated off): fall to the bottom, never vanish.
        $this->assertSame(['calendar', 'notices', 'events', 'orphan'], $layout['right']);

        // A saved drag order still outranks the anchor.
        $dragged = DashboardWidgets::layoutWith($registry, $available, ['left' => ['signoff', 'summary'], 'right' => []], []);
        $this->assertSame(['signoff', 'summary', 'clock', 'tasks', 'leave'], $dragged['left']);
    }
}
