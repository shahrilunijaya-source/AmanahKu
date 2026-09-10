<?php

namespace Tests\Acceptance;

use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Acceptance for docs/specs/CR-32.md (session S04, the dashboard placement rule),
 * against docs/build/contracts/dashboard-slots.md.
 *
 * Shapes this file fixes for the generator (recorded in docs/build/OPEN.md):
 * - a band is a `<section class="uj-db-band" data-band="management|awards">` inside the
 *   existing `.uj-db` wrapper; moments keep `data-kind`. Exactly the slots that are active
 *   render, in the contract order moments, management, awards.
 * - the management band shows for `Permissions::FINAL_APPROVAL_ROLES` (management, director,
 *   hr) on every day, never for employee or manager. What it contains is CR-17 (S15); S04
 *   owns the slot, its gate and its text-only form.
 * - the awards band shows for everyone from the first working day of the month to the 7th
 *   inclusive, by the tenant's working-day rule (weekends and public holidays are not
 *   working days). What it contains is CR-14 (S17/S18); S04 owns the window.
 * - the Friday sign-off is a registry widget `friday` in the left column right after `tasks`
 *   (`data-widget="friday"`), rendered from Friday 15:00 to Monday 09:00 exclusive. Its
 *   content is CR-29 (S25); S04 owns the slot and the window.
 * - the widget order on a quiet day is the registry default, read from `data-widget` ids
 *   inside each `data-col` column.
 */
class CR32Test extends TestCase
{
    use AlwaysChecks;
    use RefreshDatabase;

    /** The registry default order on a quiet day, per column (contracts/dashboard-slots.md). */
    private const LEFT = ['summary', 'clock', 'tasks', 'leave'];

    /** Flowers only renders when there is at least one flower, so it is absent on a fresh tenant. */
    private const RIGHT = ['calendar', 'notices', 'claims', 'work', 'style'];

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    #[Test]
    public function test_acceptance_1_quiet_tuesday_as_staff_dashboard_is_exactly_as_today(): void
    {
        Carbon::setTestNow('2026-09-08 10:00:00');
        $emysha = $this->person('Emysha');

        $page = $this->actingInTenantAs($emysha)->get('/app/dash')->assertOk();
        $html = $page->getContent();

        $page->assertDontSee('uj-db-band', false);
        $this->assertSame(0, substr_count($html, 'data-band='), 'no band may render on a quiet day');
        $this->assertSame(self::LEFT, $this->widgetIds($html, 'left'));
        $this->assertSame(self::RIGHT, $this->widgetIds($html, 'right'));
        $page->assertDontSee('data-widget="friday"', false);
        $page->assertDontSee('data-widget="events"', false);
    }

    #[Test]
    public function test_acceptance_2_same_day_as_director_only_the_management_band_appears(): void
    {
        Carbon::setTestNow('2026-09-08 10:00:00');
        $director = $this->person('Shahril Director', 'director');
        $hr = $this->person('Hidayah HR', 'hr');
        $manager = $this->person('Kussairi Manager', 'manager');
        $staff = $this->person('Emysha Staff');

        $page = $this->actingInTenantAs($director)->get('/app/dash')->assertOk();
        $html = $page->getContent();

        $this->assertSame(1, substr_count($html, 'data-band='), 'exactly one band for a director on a quiet day');
        $page->assertSee('data-band="management"', false);
        $page->assertDontSee('data-band="awards"', false);
        $page->assertDontSee('data-kind=', false);
        // The band sits above the grid, and the grid itself is untouched.
        $this->assertLessThan(strpos($html, 'class="uj-dw-grid"'), strpos($html, 'data-band="management"'), 'the band renders above the widget grid');
        $this->assertSame(self::LEFT + [4 => 'stuck'], $this->widgetIds($html, 'left'), 'a director\'s left column is the registry default (stuck is role-gated and pre-existing)');

        // HR shares the band; manager and staff never see it.
        $this->actingInTenantAs($hr)->get('/app/dash')->assertOk()->assertSee('data-band="management"', false);
        $this->actingInTenantAs($manager)->get('/app/dash')->assertOk()->assertDontSee('data-band=', false);
        $this->actingInTenantAs($staff)->get('/app/dash')->assertOk()->assertDontSee('data-band=', false);

        // Keep it plain: the band stays, as text, inside the plain wrapper.
        $this->actingInTenantAs($director)->postJson('/app/dashboard/prefs', ['plain' => true])->assertOk();
        $plain = $this->actingInTenantAs($director)->get('/app/dash')->assertOk();
        $plain->assertSee('data-band="management"', false);
        $plain->assertSee('class="uj-db" data-plain=""', false);
        $plain->assertDontSee('uj-db-art', false);
    }

    #[Test]
    public function test_acceptance_3_first_of_october_awards_band_appears_and_existing_cards_are_unchanged_below_it(): void
    {
        $staff = $this->person('Emysha');

        Carbon::setTestNow('2026-10-01 10:00:00'); // Thursday, first working day
        $page = $this->actingInTenantAs($staff)->get('/app/dash')->assertOk();
        $html = $page->getContent();
        $page->assertSee('data-band="awards"', false);
        $this->assertSame(1, substr_count($html, 'data-band='));
        $this->assertLessThan(strpos($html, 'class="uj-dw-grid"'), strpos($html, 'data-band="awards"'));
        $this->assertSame(self::LEFT, $this->widgetIds($html, 'left'));
        $this->assertSame(self::RIGHT, $this->widgetIds($html, 'right'));

        // Through the 7th inclusive, gone on the 8th.
        Carbon::setTestNow('2026-10-07 17:00:00');
        $this->actingInTenantAs($staff)->get('/app/dash')->assertOk()->assertSee('data-band="awards"', false);
        Carbon::setTestNow('2026-10-08 09:00:00');
        $this->actingInTenantAs($staff)->get('/app/dash')->assertOk()->assertDontSee('data-band="awards"', false);

        // "First working day": 1 Nov 2026 is a Sunday, so the band starts on Monday the 2nd.
        Carbon::setTestNow('2026-11-01 10:00:00');
        $this->actingInTenantAs($staff)->get('/app/dash')->assertOk()->assertDontSee('data-band="awards"', false);
        Carbon::setTestNow('2026-11-02 10:00:00');
        $this->actingInTenantAs($staff)->get('/app/dash')->assertOk()->assertSee('data-band="awards"', false);

        // A director in the window sees management first, then awards: contract order.
        $director = $this->person('Shahril Director', 'director');
        Carbon::setTestNow('2026-10-02 10:00:00');
        $both = $this->actingInTenantAs($director)->get('/app/dash')->assertOk()->getContent();
        $this->assertSame(2, substr_count($both, 'data-band='));
        $this->assertLessThan(strpos($both, 'data-band="awards"'), strpos($both, 'data-band="management"'), 'management renders before awards');

        // Keep it plain keeps the band as text.
        $this->actingInTenantAs($staff)->postJson('/app/dashboard/prefs', ['plain' => true])->assertOk();
        $this->actingInTenantAs($staff)->get('/app/dash')->assertOk()
            ->assertSee('data-band="awards"', false)
            ->assertSee('class="uj-db" data-plain=""', false);
    }

    #[Test]
    public function test_acceptance_4_friday_sign_off_card_after_pending_tasks_from_friday_3pm_until_monday_9am(): void
    {
        $staff = $this->person('Emysha');

        Carbon::setTestNow('2026-09-11 14:59:00'); // Friday, before three
        $this->actingInTenantAs($staff)->get('/app/dash')->assertOk()->assertDontSee('data-widget="friday"', false);

        Carbon::setTestNow('2026-09-11 15:00:00');
        $html = $this->actingInTenantAs($staff)->get('/app/dash')->assertOk()->getContent();
        $this->assertSame(['summary', 'clock', 'tasks', 'friday', 'leave'], $this->widgetIds($html, 'left'), 'the sign-off card sits right after Pending tasks');
        $this->assertSame(self::RIGHT, $this->widgetIds($html, 'right'));
        $this->assertSame(0, substr_count($html, 'data-band='), 'a widget, not a band');

        Carbon::setTestNow('2026-09-12 12:00:00'); // Saturday
        $this->actingInTenantAs($staff)->get('/app/dash')->assertOk()->assertSee('data-widget="friday"', false);
        Carbon::setTestNow('2026-09-14 08:59:00'); // Monday, before nine
        $this->actingInTenantAs($staff)->get('/app/dash')->assertOk()->assertSee('data-widget="friday"', false);
        Carbon::setTestNow('2026-09-14 09:00:00');
        $this->actingInTenantAs($staff)->get('/app/dash')->assertOk()->assertDontSee('data-widget="friday"', false);
        Carbon::setTestNow('2026-09-10 16:00:00'); // Thursday afternoon
        $this->actingInTenantAs($staff)->get('/app/dash')->assertOk()->assertDontSee('data-widget="friday"', false);
    }

    #[Test]
    public function test_acceptance_5_layout_matches_appendix_b_where_the_code_does_not_disagree(): void
    {
        $this->markTestIncomplete('human check: with the bands and the Friday card active, the page matches docs/specs/appendix-b-dashboard-placement.md, allowing for the differences listed at the bottom of contracts/dashboard-slots.md (work and style in the right column; stuck and pulse present).');
    }

    #[Test]
    public function test_always_checks_from_s04(): void
    {
        $this->assertDueDateLocked();
        $this->assertAuditLogImmutable();
        $this->assertDashboardUnchanged();
        $this->assertKeepItPlainHonoured();
    }

    /**
     * The widget ids rendered in one grid column, in page order.
     *
     * @return list<string>
     */
    private function widgetIds(string $html, string $column): array
    {
        $start = strpos($html, 'data-col="'.$column.'"');
        $this->assertNotFalse($start, "no {$column} column in the page");
        $next = strpos($html, 'class="uj-dw-col"', $start + 1);
        $slice = $next === false ? substr($html, $start) : substr($html, $start, $next - $start);
        preg_match_all('/data-widget="([a-z_]+)"/', $slice, $m);

        return $m[1];
    }
}
