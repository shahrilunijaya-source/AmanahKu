<?php

namespace Tests\Acceptance;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\WorkItem;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Acceptance for docs/specs/CR-14.md, half b (session S18): the dashboard carousel, the
 * Awards screen under The Playground, nominations, the four manual awards, the auto
 * Nominate/Select tasks, the profile badge, and Global Clause item 3 (the Director
 * override). Items 6 to 10 (computation) and the auto publish of item 1 are pinned by
 * CR14aTest and are not repeated here; this file pins what S17 left for S18.
 *
 * Shapes this file fixes for the generator (recorded in docs/build/OPEN.md):
 * - Screen `GET /app/awards`, nav id `awards`, label 'Awards', in The Playground group,
 *   visible to every role. `?month=YYYY-MM-01` picks a published month, default the latest
 *   published. Tabs: "This month's winners", "Nominate", "Past winners"; PM and above also
 *   see "Select". Each `award_results` row of the shown month renders an element with
 *   `data-award="<award_key>"` and `data-winner="<employee_id>"` carrying the winner's name
 *   and the result `label`. Past winners lists every published month as
 *   `data-month="YYYY-MM-01"`. An adjusted result (Global Clause) shows the text
 *   "Result adjusted – <reason>" (en dash, as the Global Clause writes it).
 * - Reactions and comments on a result, on the screen and on the dashboard slide:
 *   `POST /app/awards/{result}/react {reaction}` (a CR-30 key, `Reaction::activeKeys()`,
 *   one per person per result, same toggle semantics as `POST /app/tot/{session}/react`)
 *   and `POST /app/awards/{result}/comments {body}` (a comment push writes an audit row,
 *   Global Clause). Tables `award_reactions` (`award_result_id`, `employee_id`, `emoji`) and
 *   `award_comments` (`award_result_id`, `employee_id`, `body`). The slide and the screen
 *   row carry `data-reactions="<count>"` and `data-comments="<count>"`.
 * - Dashboard band: `DashboardBands::SLOTS` `awards`, rendered as
 *   `<section data-band="awards">` from the month's first working day to the 7th inclusive
 *   (`DashboardBands::awardsWindowOpen`), only when the previous month has `award_results`
 *   rows. Inside, one `data-slide="<award_key>"` per award (a tie shares the slide, every
 *   winner listed as `data-winner`), each with the award name, its explanation line, the
 *   winner name and role, the `label`, reactions and comments, in the order
 *   chosen_one, main_character, then `Awards::KEYS` order. A "View all" link to
 *   `/app/awards`. In plain mode the band renders with `data-plain` on `.uj-db`, no
 *   `uj-db-art`, no timer. Auto-rotation, hover pause and swipe are a human check.
 * - Nominations: `POST /app/awards/nominate {award_key, employee_id, reason}`, any staff,
 *   only `main_character` and `office_yoda`, only in the last week of the month (from the
 *   day that is 7 days before the last calendar day, e.g. 24 to 30 Sep), never yourself,
 *   one nomination per award per nominator per month (a second is 422, not a replace).
 *   Rows in `award_nominations` (`tenant_id`, `month` = first of the month, `award_key`,
 *   `nominator_employee_id`, `nominee_employee_id`, `reason`), audit row on submit.
 *   `awards:publish` (S17) tallies the previous month's nominations: most votes wins,
 *   ties both win, into `award_results` with `source` = 'nomination', `label` = the vote
 *   count in words ("3 nominations"), `reason` = null; rules 9 and 10 apply as for auto
 *   awards. No nominations, no row.
 * - Manual picks: `POST /app/awards/select {award_key, employee_id, reason}`.
 *   `new_but_dangerous`: manager, management or director; the pick must have
 *   `employees.joined_at` within the 6 months before the month being awarded (422
 *   otherwise). `chosen_one`: director only (403 for everyone else), `reason` required
 *   (422 without). The pick is for the month currently open for selection: the previous
 *   month once its awards are published, else the current month. Writes one
 *   `award_results` row (`source` 'manual', `reason`, `published_at` now), replacing an
 *   earlier pick of the same award and month by anyone, and an audit row.
 * - Auto tasks: `awards:tasks`, scheduled `0 8 * * *`, acting only on the last Monday of
 *   the month. Creates for every active employee with a user one card 'Nominate this
 *   month's awards' (owner the person, `due_at` the month's last working day,
 *   `source` = 'awards', `source_ref` = 'YYYY-MM-nominate', label `system`, type task,
 *   description carrying the `/app/awards` link) and for every manager, management and
 *   director one card 'Select manual award winners' (`due_at` the first working day of
 *   the next month, `source_ref` = 'YYYY-MM-select'). One card per person per source_ref,
 *   a second run adds nothing. The Nominate card moves to `done` when its owner submits
 *   a nomination; the Select card when its owner submits a pick. These cards never count
 *   for any award (`source` non-null, CR14aTest item 8).
 * - Badge: `GET /app/profile` (own) and `/app/profile?emp=<id>` render one
 *   `data-award-badge="<award_key>"` per distinct award the person has won, and
 *   `data-hall-of-fame="<award_key>"` once they hold three wins of that award (rule 10).
 * - Director override (Global Clause item 3): `POST /app/awards/{result}/adjust
 *   {employee_id, reason}`, director only (403 otherwise), `reason` required. The row's
 *   `employee_id` becomes the new winner, `source` = 'adjusted', `reason` stored; the
 *   Awards screen and the slide show "Result adjusted – <reason>"; one audit row on the
 *   result with `field` = 'employee_id', the old and new employee ids and the reason.
 */
class CR14bTest extends TestCase
{
    use AlwaysChecks;
    use RefreshDatabase;

    private const SEPTEMBER = '2026-09-01';

    private const OCTOBER = '2026-10-01';

    private Employee $director;

    private Employee $hr;

    private Employee $ahmad;

    private Employee $nurin;

    private Employee $emysha;

    private Employee $adri;

    protected function setUp(): void
    {
        parent::setUp();

        $this->director = $this->person('Shahril', 'director', ['joined_at' => '2020-01-06']);
        $this->hr = $this->person('Hidayah', 'hr', ['joined_at' => '2021-03-01']);
        $this->ahmad = $this->person('Ahmad', 'manager', ['joined_at' => '2022-05-02', 'position' => 'Project Manager']);
        $this->nurin = $this->person('Nurin', 'employee', ['joined_at' => '2026-06-01', 'position' => 'Project Engineer']);
        $this->emysha = $this->person('Emysha', 'employee', ['joined_at' => '2024-02-01']);
        $this->adri = $this->person('Adri', 'employee', ['joined_at' => '2025-01-06']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── 1. On 1 Oct, September's awards publish automatically, nominations included ──

    public function test_acceptance_1_nominated_awards_publish_on_1_october_with_the_most_votes(): void
    {
        // Last week of September: three people nominate. Main Character: Emysha 2 votes,
        // Adri 1. Office Yoda: Ahmad 1, Nurin 1, a tie.
        $this->nominate($this->nurin, 'main_character', $this->emysha, 'Carried the KPT go-live weekend', '2026-09-24 10:00:00')->assertOk();
        $this->nominate($this->adri, 'main_character', $this->emysha, 'Stayed on the client call until it was fixed', '2026-09-25 10:00:00')->assertOk();
        $this->nominate($this->ahmad, 'main_character', $this->adri, 'Quiet hero of the month', '2026-09-28 10:00:00')->assertOk();
        $this->nominate($this->emysha, 'office_yoda', $this->ahmad, 'Patient with every question', '2026-09-29 10:00:00')->assertOk();
        $this->nominate($this->adri, 'office_yoda', $this->nurin, 'Taught me the timesheet in a day', '2026-09-30 10:00:00')->assertOk();

        $this->assertSame(5, DB::table('award_nominations')->whereDate('month', self::SEPTEMBER)->count());
        $this->assertTrue(AuditLog::where('tenant_id', $this->tenant()->id)->where('action', 'like', '%nominat%')->exists(), 'a nomination wrote no audit row');

        Carbon::setTestNow('2026-09-30 23:59:00');
        Artisan::call('awards:freeze');

        // Not yet published: nothing on the screen for September.
        Carbon::setTestNow('2026-09-30 23:59:30');
        $this->assertSame(0, $this->results(self::SEPTEMBER)->count());

        Carbon::setTestNow('2026-10-01 08:00:00');
        Artisan::call('awards:publish');

        $this->assertWinners(self::SEPTEMBER, 'main_character', [$this->emysha]);
        $this->assertWinners(self::SEPTEMBER, 'office_yoda', [$this->ahmad, $this->nurin]);
        $row = $this->results(self::SEPTEMBER)->firstWhere('award_key', 'main_character');
        $this->assertSame('nomination', $row->source);
        $this->assertSame(2.0, (float) $row->value);
        $this->assertStringContainsString('2', (string) $row->label);
        $this->assertStringStartsWith('2026-10-01 08:00', (string) $row->published_at);
        $this->assertSame(0, $this->results(self::SEPTEMBER)->whereIn('award_key', ['new_but_dangerous', 'chosen_one'])->count(), 'a manual award with no pick got a winner');

        // The screen shows September's winners to everyone.
        $page = $this->actingInTenantAs($this->adri)->get('/app/awards')->assertOk();
        $page->assertSee('data-award="main_character"', false)
            ->assertSee('data-winner="'.$this->emysha->id.'"', false)
            ->assertSee('data-award="office_yoda"', false)
            ->assertSee('data-winner="'.$this->ahmad->id.'"', false)
            ->assertSee('data-winner="'.$this->nurin->id.'"', false)
            ->assertSee('Emysha');

        // A second publish adds nothing.
        Carbon::setTestNow('2026-10-01 08:00:30');
        Artisan::call('awards:publish');
        $this->assertSame(3, $this->results(self::SEPTEMBER)->count());
    }

    // ── 2. Carousel: one award per slide, reactions and comments on the slide ──

    public function test_acceptance_2_carousel_one_award_per_slide_with_reactions_and_comments(): void
    {
        $this->publishedResult(self::SEPTEMBER, 'done_and_dusted', $this->emysha, 12, '12 cards finished this month');
        $this->publishedResult(self::SEPTEMBER, 'never_late', $this->adri, 22, '22/22 on-time shifts');
        $this->publishedResult(self::SEPTEMBER, 'never_late', $this->nurin, 22, '22/22 on-time shifts');
        $this->publishedResult(self::SEPTEMBER, 'main_character', $this->adri, 2, '2 nominations', 'nomination');
        $chosen = $this->publishedResult(self::SEPTEMBER, 'chosen_one', $this->nurin, 1, "Director's pick", 'manual', 'Turned the KPT audit around in a week');

        // 30 Sep: no band yet. 1 Oct (first working day): the band.
        Carbon::setTestNow('2026-09-30 10:00:00');
        $this->actingInTenantAs($this->emysha)->get('/app/dash')->assertOk()->assertDontSee('data-band="awards"', false);

        Carbon::setTestNow('2026-10-01 10:00:00');
        $page = $this->actingInTenantAs($this->emysha)->get('/app/dash')->assertOk();
        $html = $page->getContent();
        $page->assertSee('data-band="awards"', false)
            ->assertSee('data-slide="chosen_one"', false)
            ->assertSee('data-slide="main_character"', false)
            ->assertSee('data-slide="never_late"', false)
            ->assertSee('data-slide="done_and_dusted"', false)
            ->assertSee('data-winner="'.$this->nurin->id.'"', false)
            ->assertSee('data-winner="'.$this->adri->id.'"', false)
            ->assertSee('data-winner="'.$this->emysha->id.'"', false)
            ->assertSee('22/22 on-time shifts')
            ->assertSee('Turned the KPT audit around in a week')
            ->assertSee('/app/awards', false);
        $this->assertSame(4, substr_count($html, 'data-slide="'), 'one slide per award, a tie shares its slide');
        $order = array_map(fn ($k) => strpos($html, 'data-slide="'.$k.'"'), ['chosen_one', 'main_character', 'never_late', 'done_and_dusted']);
        $this->assertSame($order, array_values(array_filter($order, fn ($p) => $p !== false)), 'a slide is missing');
        $sorted = $order;
        sort($sorted);
        $this->assertSame($sorted, $order, 'manual awards first, then the list order');
        $this->assertLessThan(strpos($html, 'uj-dw-grid'), strpos($html, 'data-band="awards"'), 'the band sits above the widget grid');

        // React and comment on the Chosen One slide without leaving the dashboard.
        $this->actingInTenantAs($this->emysha)->postJson("/app/awards/{$chosen}/react", ['reaction' => 'thumbs'])->assertStatus(422);
        $this->actingInTenantAs($this->emysha)->postJson("/app/awards/{$chosen}/react", ['reaction' => 'power'])->assertOk();
        $this->actingInTenantAs($this->adri)->postJson("/app/awards/{$chosen}/react", ['reaction' => 'legend'])->assertOk();
        $this->actingInTenantAs($this->emysha)->postJson("/app/awards/{$chosen}/react", ['reaction' => 'respect'])->assertOk();
        $this->assertSame(2, DB::table('award_reactions')->where('award_result_id', $chosen)->count(), 'one reaction per person per award');
        $this->assertDatabaseHas('award_reactions', ['award_result_id' => $chosen, 'employee_id' => $this->emysha->id, 'emoji' => 'respect']);

        $this->actingInTenantAs($this->emysha)->postJson("/app/awards/{$chosen}/comments", ['body' => ''])->assertStatus(422);
        $this->actingInTenantAs($this->emysha)->postJson("/app/awards/{$chosen}/comments", ['body' => 'Well deserved, Nurin!'])->assertOk();
        $this->assertDatabaseHas('award_comments', ['award_result_id' => $chosen, 'employee_id' => $this->emysha->id, 'body' => 'Well deserved, Nurin!']);
        $this->assertTrue(AuditLog::where('tenant_id', $this->tenant()->id)->where('user_id', $this->emysha->user_id)->where('action', 'like', '%omment%')->exists(), 'a comment push wrote no audit row');

        $page = $this->actingInTenantAs($this->adri)->get('/app/dash')->assertOk();
        $slide = $this->slide($page->getContent(), 'chosen_one');
        $this->assertStringContainsString('data-reactions="2"', $slide);
        $this->assertStringContainsString('data-comments="1"', $slide);
        $this->assertStringContainsString('Well deserved, Nurin!', $slide);

        // Same counts on the Awards screen.
        $this->actingInTenantAs($this->adri)->get('/app/awards')->assertOk()
            ->assertSee('data-reactions="2"', false)->assertSee('data-comments="1"', false);

        // 7 Oct still shows the band, 8 Oct does not.
        Carbon::setTestNow('2026-10-07 10:00:00');
        $this->actingInTenantAs($this->emysha)->get('/app/dash')->assertOk()->assertSee('data-band="awards"', false);
        Carbon::setTestNow('2026-10-08 10:00:00');
        $this->actingInTenantAs($this->emysha)->get('/app/dash')->assertOk()->assertDontSee('data-band="awards"', false);

        // Keep it plain: the band stays, text only.
        Carbon::setTestNow('2026-10-02 10:00:00');
        $this->actingInTenantAs($this->emysha)->postJson('/app/dashboard/prefs', ['plain' => true])->assertOk();
        $plain = $this->actingInTenantAs($this->emysha)->get('/app/dash')->assertOk();
        $plain->assertSee('data-band="awards"', false)->assertSee('data-plain', false)->assertSee('22/22 on-time shifts');
        $this->assertStringNotContainsString('uj-db-art', $this->slide($plain->getContent(), 'never_late'));

        $this->markTestIncomplete('human check: the carousel auto-rotates every ~6s, pauses on hover, swipes and arrows move it, dot indicators track the slide, and none of that runs in plain mode');
    }

    // ── 3. Last month's winner cannot win the same award: the screen shows the runner-up ──

    public function test_acceptance_3_the_screen_shows_octobers_traffic_winner_is_not_septembers(): void
    {
        $this->publishedResult(self::SEPTEMBER, 'beating_the_traffic', $this->emysha, -30, '30 minutes earlier than expected, typically');
        $this->publishedResult(self::OCTOBER, 'beating_the_traffic', $this->adri, -10, '10 minutes earlier than expected, typically', 'auto', null, '2026-11-02 08:00:00');

        Carbon::setTestNow('2026-11-02 10:00:00');
        $october = $this->actingInTenantAs($this->nurin)->get('/app/awards')->assertOk();
        $this->assertStringContainsString('data-winner="'.$this->adri->id.'"', $this->award($october->getContent(), 'beating_the_traffic'));
        $this->assertStringNotContainsString('data-winner="'.$this->emysha->id.'"', $this->award($october->getContent(), 'beating_the_traffic'));

        $september = $this->actingInTenantAs($this->nurin)->get('/app/awards?month='.self::SEPTEMBER)->assertOk();
        $this->assertStringContainsString('data-winner="'.$this->emysha->id.'"', $this->award($september->getContent(), 'beating_the_traffic'));

        $october->assertSee('data-month="'.self::SEPTEMBER.'"', false)->assertSee('data-month="'.self::OCTOBER.'"', false);
    }

    // ── 4. Awards screen in The Playground; Nominate and Select tasks auto-create and auto-close ──

    public function test_acceptance_4_awards_screen_in_the_playground_and_the_auto_tasks(): void
    {
        $this->assertScheduled('awards:tasks', '0 8 * * *');

        $page = $this->actingInTenantAs($this->nurin)->get('/app/awards')->assertOk();
        $page->assertSee('Awards')->assertSee("This month's winners")->assertSee('Nominate')->assertSee('Past winners');
        $this->assertStringContainsString('Knowledge Bank', $page->getContent(), 'the Playground nav is not on the screen');
        $this->assertStringContainsString('/app/awards', $page->getContent());
        $page->assertDontSee('Select');
        $this->actingInTenantAs($this->ahmad)->get('/app/awards')->assertOk()->assertSee('Select');
        $this->actingInTenantAs($this->director)->get('/app/awards')->assertOk()->assertSee('Select');

        // 21 Sep is a Monday but not the last one: nothing. 28 Sep: the cards.
        Carbon::setTestNow('2026-09-21 08:00:00');
        Artisan::call('awards:tasks');
        $this->assertSame(0, WorkItem::where('source', 'awards')->count());

        Carbon::setTestNow('2026-09-28 08:00:00');
        Artisan::call('awards:tasks');

        $nominate = WorkItem::where('source', 'awards')->where('source_ref', '2026-09-nominate')->get();
        $select = WorkItem::where('source', 'awards')->where('source_ref', '2026-09-select')->get();
        $staff = Employee::where('tenant_id', $this->tenant()->id)->whereNotNull('user_id')->pluck('id')->sort()->values()->all();
        $this->assertSame($staff, $nominate->pluck('employee_id')->sort()->values()->all(), 'every staff member gets a Nominate card');
        $this->assertSame(collect([$this->director->id, $this->ahmad->id])->sort()->values()->all(), $select->pluck('employee_id')->sort()->values()->all(), 'Select cards go to PM and above and the Director only');

        foreach ($nominate as $card) {
            $this->assertStringContainsString('Nominate', $card->title);
            $this->assertSame('2026-09-30', $card->due_at?->format('Y-m-d'));
            $this->assertSame('todo', $card->status);
            $this->assertContains('system', $card->labels ?? []);
            $this->assertStringContainsString('/app/awards', (string) $card->description);
        }
        foreach ($select as $card) {
            $this->assertStringContainsString('Select', $card->title);
            $this->assertSame('2026-10-01', $card->due_at?->format('Y-m-d'));
            $this->assertContains('system', $card->labels ?? []);
        }

        Carbon::setTestNow('2026-09-28 08:00:30');
        Artisan::call('awards:tasks');
        $this->assertSame($nominate->count() + $select->count(), WorkItem::where('source', 'awards')->count(), 'a second run duplicated cards');

        // Nominate gating.
        $this->nominate($this->nurin, 'main_character', $this->nurin, 'Me', '2026-09-29 09:00:00')->assertStatus(422);
        $this->nominate($this->nurin, 'deadline_who', $this->emysha, 'Not a nominated award', '2026-09-29 09:00:00')->assertStatus(422);
        $this->nominate($this->nurin, 'main_character', $this->emysha, 'Too early', '2026-09-20 09:00:00')->assertStatus(422);
        $this->assertSame('todo', $nominate->firstWhere('employee_id', $this->nurin->id)->fresh()->status, 'a rejected nomination closed the card');

        $this->nominate($this->nurin, 'main_character', $this->emysha, 'Carried the go-live', '2026-09-29 09:00:00')->assertOk();
        $this->assertSame('done', $nominate->firstWhere('employee_id', $this->nurin->id)->fresh()->status, 'the Nominate card did not auto-close');
        $this->assertSame('todo', $nominate->firstWhere('employee_id', $this->adri->id)->fresh()->status, "someone else's card closed");
        $this->nominate($this->nurin, 'main_character', $this->adri, 'Second try', '2026-09-29 09:05:00')->assertStatus(422);
        $this->assertSame(1, DB::table('award_nominations')->where('nominator_employee_id', $this->nurin->id)->count(), 'one nomination per award per person');
        $this->nominate($this->nurin, 'office_yoda', $this->ahmad, 'Patient', '2026-09-29 09:10:00')->assertOk();

        // Select gating and auto-close.
        Carbon::setTestNow('2026-09-29 10:00:00');
        $this->actingInTenantAs($this->nurin)->postJson('/app/awards/select', ['award_key' => 'new_but_dangerous', 'employee_id' => $this->adri->id])->assertStatus(403);
        $this->actingInTenantAs($this->ahmad)->postJson('/app/awards/select', ['award_key' => 'chosen_one', 'employee_id' => $this->nurin->id, 'reason' => 'Great month'])->assertStatus(403);
        $this->actingInTenantAs($this->ahmad)->postJson('/app/awards/select', ['award_key' => 'new_but_dangerous', 'employee_id' => $this->adri->id])->assertStatus(422);
        $this->actingInTenantAs($this->ahmad)->postJson('/app/awards/select', ['award_key' => 'deadline_who', 'employee_id' => $this->nurin->id])->assertStatus(422);
        $this->assertSame('todo', $select->firstWhere('employee_id', $this->ahmad->id)->fresh()->status);

        $this->actingInTenantAs($this->ahmad)->postJson('/app/awards/select', ['award_key' => 'new_but_dangerous', 'employee_id' => $this->nurin->id])->assertOk();
        $this->assertSame('done', $select->firstWhere('employee_id', $this->ahmad->id)->fresh()->status, 'the Select card did not auto-close');
        $this->assertSame('todo', $select->firstWhere('employee_id', $this->director->id)->fresh()->status);
        $pick = $this->results(self::SEPTEMBER)->firstWhere('award_key', 'new_but_dangerous');
        $this->assertNotNull($pick, 'the pick wrote no result row');
        $this->assertSame($this->nurin->id, (int) $pick->employee_id);
        $this->assertSame('manual', $pick->source);

        $this->actingInTenantAs($this->director)->postJson('/app/awards/select', ['award_key' => 'chosen_one', 'employee_id' => $this->emysha->id])->assertStatus(422);
        $this->actingInTenantAs($this->director)->postJson('/app/awards/select', ['award_key' => 'chosen_one', 'employee_id' => $this->emysha->id, 'reason' => 'Turned the KPT audit around'])->assertOk();
        $this->assertSame('done', $select->firstWhere('employee_id', $this->director->id)->fresh()->status);
        $chosen = $this->results(self::SEPTEMBER)->firstWhere('award_key', 'chosen_one');
        $this->assertSame($this->emysha->id, (int) $chosen->employee_id);
        $this->assertSame('Turned the KPT audit around', $chosen->reason);
        $this->assertTrue(AuditLog::where('tenant_id', $this->tenant()->id)->where('user_id', $this->director->user_id)->where('action', 'like', '%ward%')->exists(), 'a manual pick wrote no audit row');

        // The reason appears on the screen and, in the window, on the slide.
        Carbon::setTestNow('2026-10-01 10:00:00');
        $this->actingInTenantAs($this->adri)->get('/app/awards')->assertOk()->assertSee('Turned the KPT audit around')->assertSee('data-winner="'.$this->nurin->id.'"', false);
        $this->actingInTenantAs($this->adri)->get('/app/dash')->assertOk()->assertSee('data-slide="chosen_one"', false)->assertSee('Turned the KPT audit around');

        // The auto cards never count for any award (CR14aTest item 8 pins the marker).
        foreach ($nominate->merge($select) as $card) {
            $this->assertSame('awards', $card->fresh()->source);
        }
    }

    // ── 5. Winner's profile shows the award badge ──

    public function test_acceptance_5_the_winners_profile_shows_the_badge_and_hall_of_fame_after_three_wins(): void
    {
        $this->publishedResult('2026-07-01', 'done_and_dusted', $this->emysha, 9, '9 cards finished this month', 'auto', null, '2026-08-03 08:00:00');
        $this->publishedResult('2026-08-01', 'done_and_dusted', $this->emysha, 11, '11 cards finished this month', 'auto', null, '2026-09-01 08:00:00');
        $this->publishedResult('2026-08-01', 'chief_hype_officer', $this->emysha, 6, 'hyped up 6 colleagues this month', 'auto', null, '2026-09-01 08:00:00');

        Carbon::setTestNow('2026-09-08 10:00:00');
        $own = $this->actingInTenantAs($this->emysha)->get('/app/profile')->assertOk();
        $own->assertSee('data-award-badge="done_and_dusted"', false)
            ->assertSee('data-award-badge="chief_hype_officer"', false)
            ->assertDontSee('data-hall-of-fame=', false);
        $this->assertSame(2, substr_count($own->getContent(), 'data-award-badge="'), 'one badge per distinct award won');

        $this->actingInTenantAs($this->adri)->get('/app/profile?emp='.$this->emysha->id)->assertOk()
            ->assertSee('data-award-badge="done_and_dusted"', false);
        $this->actingInTenantAs($this->adri)->get('/app/profile')->assertOk()->assertDontSee('data-award-badge=', false);

        // Third win of the same award: Hall of Fame.
        $this->publishedResult(self::SEPTEMBER, 'done_and_dusted', $this->emysha, 14, '14 cards finished this month', 'auto', null, '2026-10-01 08:00:00');
        Carbon::setTestNow('2026-10-01 10:00:00');
        $this->actingInTenantAs($this->adri)->get('/app/profile?emp='.$this->emysha->id)->assertOk()
            ->assertSee('data-hall-of-fame="done_and_dusted"', false)
            ->assertDontSee('data-hall-of-fame="chief_hype_officer"', false);
    }

    // ── 6 to 10. Computation rules: pinned by CR14aTest ──

    public function test_acceptance_6_to_10_are_pinned_by_cr14a(): void
    {
        foreach ([6, 7, 8, 9, 10] as $item) {
            $methods = array_filter(get_class_methods(CR14aTest::class), fn ($m) => str_starts_with($m, "test_acceptance_{$item}_"));
            $this->assertNotEmpty($methods, "CR14aTest has no test for item {$item}");
        }
    }

    // ── Global Clause 3. Director overrides a result: note on the page, audit entry ──

    public function test_global_clause_3_director_override_shows_the_adjustment_note_and_writes_audit(): void
    {
        $result = $this->publishedResult(self::SEPTEMBER, 'done_and_dusted', $this->emysha, 12, '12 cards finished this month');
        Carbon::setTestNow('2026-10-02 10:00:00');

        $this->actingInTenantAs($this->hr)->postJson("/app/awards/{$result}/adjust", ['employee_id' => $this->adri->id, 'reason' => 'Two of the cards were test data'])->assertStatus(403);
        $this->actingInTenantAs($this->ahmad)->postJson("/app/awards/{$result}/adjust", ['employee_id' => $this->adri->id, 'reason' => 'Two of the cards were test data'])->assertStatus(403);
        $this->actingInTenantAs($this->director)->postJson("/app/awards/{$result}/adjust", ['employee_id' => $this->adri->id])->assertStatus(422);
        $this->assertSame($this->emysha->id, (int) DB::table('award_results')->where('id', $result)->value('employee_id'), 'a refused override changed the winner');

        $this->actingInTenantAs($this->director)->postJson("/app/awards/{$result}/adjust", ['employee_id' => $this->adri->id, 'reason' => 'Two of the cards were test data'])->assertOk();

        $row = DB::table('award_results')->where('id', $result)->first();
        $this->assertSame($this->adri->id, (int) $row->employee_id);
        $this->assertSame('adjusted', $row->source);
        $this->assertSame('Two of the cards were test data', $row->reason);
        $this->assertSame(1, $this->results(self::SEPTEMBER)->where('award_key', 'done_and_dusted')->count(), 'the override added a second row instead of replacing the winner');

        $audit = AuditLog::where('tenant_id', $this->tenant()->id)->where('user_id', $this->director->user_id)
            ->where('field', 'employee_id')->orderByDesc('id')->first();
        $this->assertNotNull($audit, 'no audit row for the override');
        $this->assertStringContainsString((string) $this->emysha->id, (string) $audit->old_value);
        $this->assertStringContainsString((string) $this->adri->id, (string) $audit->new_value);
        $this->assertStringContainsString('Two of the cards were test data', (string) $audit->reason);

        $page = $this->actingInTenantAs($this->nurin)->get('/app/awards')->assertOk();
        $award = $this->award($page->getContent(), 'done_and_dusted');
        $this->assertStringContainsString('data-winner="'.$this->adri->id.'"', $award);
        $this->assertStringContainsString('Result adjusted – Two of the cards were test data', $award);

        $slide = $this->slide($this->actingInTenantAs($this->nurin)->get('/app/dash')->assertOk()->getContent(), 'done_and_dusted');
        $this->assertStringContainsString('Result adjusted – Two of the cards were test data', $slide);
    }

    public function test_always_checks(): void
    {
        $this->assertDueDateLocked();
        $this->assertAuditLogImmutable();
        $this->assertDashboardUnchanged();
        $this->assertKeepItPlainHonoured();
    }

    // ── helpers ──

    private function assertScheduled(string $command, string $expression): void
    {
        $events = collect(app(Schedule::class)->events())->filter(fn ($e) => str_contains($e->command ?? '', $command));
        $this->assertTrue($events->isNotEmpty(), "{$command} is not in the scheduler");
        $this->assertSame($expression, $events->first()->expression, "{$command} schedule expression");
    }

    private function nominate(Employee $actor, string $awardKey, Employee $nominee, string $reason, string $at): TestResponse
    {
        Carbon::setTestNow($at);

        return $this->actingInTenantAs($actor)->postJson('/app/awards/nominate', ['award_key' => $awardKey, 'employee_id' => $nominee->id, 'reason' => $reason]);
    }

    /** Inserts a published result row and returns its id. */
    private function publishedResult(string $month, string $awardKey, Employee $winner, float $value, string $label, string $source = 'auto', ?string $reason = null, string $publishedAt = '2026-10-01 08:00:00'): int
    {
        return (int) DB::table('award_results')->insertGetId([
            'tenant_id' => $this->tenant()->id, 'month' => $month, 'award_key' => $awardKey,
            'employee_id' => $winner->id, 'value' => $value, 'label' => $label, 'source' => $source,
            'reason' => $reason, 'published_at' => $publishedAt, 'created_at' => $publishedAt, 'updated_at' => $publishedAt,
        ]);
    }

    private function results(string $month): Collection
    {
        return DB::table('award_results')->where('tenant_id', $this->tenant()->id)->whereDate('month', $month)->get();
    }

    private function assertWinners(string $month, string $awardKey, array $winners): void
    {
        $expected = collect($winners)->pluck('id')->sort()->values()->all();
        $actual = $this->results($month)->where('award_key', $awardKey)->pluck('employee_id')->map(fn ($id) => (int) $id)->sort()->values()->all();

        $this->assertSame($expected, $actual, "{$awardKey} winners for {$month}");
    }

    /** The HTML of one dashboard slide, from its data-slide attribute to the next slide or the end of the band. */
    private function slide(string $html, string $awardKey): string
    {
        return $this->section($html, 'data-slide="'.$awardKey.'"', 'data-slide="');
    }

    /** The HTML of one Awards-screen result, from its data-award attribute to the next one. */
    private function award(string $html, string $awardKey): string
    {
        return $this->section($html, 'data-award="'.$awardKey.'"', 'data-award="');
    }

    private function section(string $html, string $start, string $nextMarker): string
    {
        $from = strpos($html, $start);
        $this->assertNotFalse($from, "{$start} is not on the page");
        $to = strpos($html, $nextMarker, $from + strlen($start));

        return $to === false ? substr($html, $from) : substr($html, $from, $to - $from);
    }
}
