<?php

namespace Tests\Acceptance;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkItem;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Acceptance for docs/specs/CR-22.md (session S28, Amanahku Wrapped). The spec's single
 * Acceptance sentence is items 1 to 4 (Yati sees her September Wrapped with 6 to 8 cards
 * on 1 Oct; she can share it to the Wall; the dashboard shows the company Wrapped with
 * reactions; Keep it plain gets a plain text summary); the Rules paragraph is item 5
 * (private by default, no comment text, curated HR-editable arc list of 30+, numbers from
 * the CR-14 frozen snapshot, no comparison to other people).
 *
 * Shapes this file fixes for the generator (recorded in docs/build/OPEN.md):
 * - `wrapped:build`, scheduled `0 8 * * *`, acting only on the first working day of the
 *   month, builds the previous month's stories: one `wrapped_stories` row per active
 *   employee with a user (tenant_id, month = first of the month, employee_id, cards json,
 *   arc_title nullable, shared_at nullable, built_at, timestamps) plus one company row
 *   with `employee_id` NULL. Idempotent: a second run on the same month adds nothing.
 *   Nothing exists for a month before the command has run.
 * - personal numbers come from `award_snapshots` for that month and employee where an
 *   award covers them: cards closed = `done_and_dusted` value, high-priority situations =
 *   `chief_firefighter` value, lessons shared = `walking_wikipedia` value (0 when the
 *   person has no row). Numbers no award covers come from cards, dates only: "most
 *   productive day" = the weekday on which the person moved most cards to Done that
 *   month, "helped N different people" = distinct owners of cards where the person is a
 *   `helper` participant and the card was done that month. No comment body, card
 *   description or free text is read; the story is numbers, dates and curated titles.
 * - the personal story renders on `GET /app/wrapped` (The Playground, nav id `wrapped`,
 *   label "Wrapped"; `?month=YYYY-MM-01`, default the latest built) as
 *   `[data-wrapped="<story id>"]` with 6 to 8 `[data-wrapped-card="<n>"]` cards (n from
 *   1), each with a line of text, and `[data-wrapped-stat="<key>"]` elements carrying the
 *   raw numbers: keys `cards_closed`, `high_priority`, `helped_people`, `lessons_shared`,
 *   `best_day` (weekday name), `arc` (the title). Only your own story: `?emp=` is not a
 *   thing, another person's story id is never rendered for you, and the page never names
 *   another employee.
 * - character arc: table `wrapped_arcs` (tenant_id, title, rule, active bool, timestamps).
 *   `rule` is one of `firefighter` (high_priority >= 3), `helper` (helped_people >= 3),
 *   `closer` (cards_closed >= 10), `quiet` (cards_closed = 0), `steady` (anything else),
 *   checked in that order, first match wins; the title is picked among the active arcs
 *   of that rule. `wrapped:build` seeds 30 or more default arcs for a tenant that has
 *   none (at least 3 per rule). HR and director curate with `POST /app/wrapped/arcs
 *   {title, rule}` (302, audit `wrapped.arc_added`, target `wrapped_arc:<id>`) and
 *   `POST /app/wrapped/arcs/{arc}/retire` (active = false, audit `wrapped.arc_retired`);
 *   `employee`/`manager` 403. The list renders on `/app/wrapped` for HR as
 *   `[data-wrapped-arc="<id>"]`.
 * - share: `POST /app/wrapped/{story}/share` by the story's owner only (anyone else 403,
 *   the company story 404) sets `shared_at`, audit `wrapped.shared`, target
 *   `wrapped_story:<id>`; `POST /app/wrapped/{story}/unshare` clears it, audit
 *   `wrapped.unshared`. A shared story is listed on the Wall, `GET /app/wins`, as
 *   `[data-win-wrapped="<story id>"]` with the person's name, the month and the arc
 *   title; an unshared story appears nowhere but on its owner's `/app/wrapped`.
 * - company: one more moment in the moments band (contracts/dashboard-slots.md), shown
 *   from the month's first working day to the 7th inclusive on every dashboard in the
 *   tenant: `<section class="uj-db-band uj-db-moment" data-kind="wrapped"
 *   data-wrapped-company="<story id>">` with kicker "<MONTH>, WRAPPED", one sentence of
 *   the shape "N cards closed, N lessons shared, N fires extinguished and only N
 *   "urgent" tasks." backed by `[data-wrapped-stat]` for `cards_closed` (cards done that
 *   month, whole tenant), `lessons_shared` (sum of `walking_wikipedia` snapshot values),
 *   `fires` (high-priority cards done that month), `urgent` (high-priority cards created
 *   that month), a CR-30 picker (`data-reaction-pick`) and tallies (`data-reaction-count`).
 *   `POST /app/wrapped/{story}/react {reaction}` with CR-30 semantics (unknown key 422,
 *   same key again removes, a different key replaces, one per person), table
 *   `wrapped_reactions` (wrapped_story_id, employee_id, reaction). Other tenants see nothing.
 * - Keep it plain: `/app/wrapped` renders `[data-wrapped-plain]`, one paragraph carrying
 *   every number and the arc title, and no `[data-wrapped-card]`, no `uj-wr-art`, no
 *   `<canvas`, no `<audio`; the dashboard moment renders text only (no `uj-db-art`, no
 *   `uj-db-confetti`).
 */
class CR22Test extends TestCase
{
    use AlwaysChecks;
    use RefreshDatabase;

    private const SEPTEMBER = '2026-09-01';

    private Employee $hidayah;

    private Employee $shahril;

    private Employee $kussairi;

    private Employee $yati;

    private Employee $shazwan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hidayah = $this->person('Hidayah HR', 'hr');
        $this->shahril = $this->person('Shahril Director', 'director');
        $this->kussairi = $this->person('Kussairi PM', 'manager');
        $this->yati = $this->person('Yati Dev');
        $this->shazwan = $this->person('Shazwan Dev');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    #[Test]
    public function test_acceptance_1_on_1_october_yati_sees_her_september_wrapped_with_6_to_8_cards(): void
    {
        $this->septemberFixture();

        // 30 Sep: nothing built yet.
        Carbon::setTestNow('2026-09-30 10:00:00');
        $this->assertSame(0, DB::table('wrapped_stories')->count());
        $this->actingInTenantAs($this->yati)->get('/app/wrapped')->assertOk()->assertDontSee('data-wrapped="', false);

        $this->build('2026-10-01 08:00:00');
        $this->assertSame(1, DB::table('wrapped_stories')->whereDate('month', self::SEPTEMBER)->where('employee_id', $this->yati->id)->count());
        $this->assertSame(1, DB::table('wrapped_stories')->whereDate('month', self::SEPTEMBER)->whereNull('employee_id')->count(), 'company story missing');
        $this->build('2026-10-01 09:00:00');
        $this->assertSame(6, DB::table('wrapped_stories')->whereDate('month', self::SEPTEMBER)->count(), 'a second run must add nothing (5 people + company)');

        Carbon::setTestNow('2026-10-01 10:00:00');
        $story = $this->story($this->yati);
        $page = $this->actingInTenantAs($this->yati)->get('/app/wrapped')->assertOk();
        $html = $page->getContent();
        $page->assertSee('data-wrapped="'.$story->id.'"', false);
        $cards = substr_count($html, 'data-wrapped-card="');
        $this->assertGreaterThanOrEqual(6, $cards, 'fewer than 6 cards');
        $this->assertLessThanOrEqual(8, $cards, 'more than 8 cards');
        $this->assertSame('5', $this->stat($html, 'cards_closed'));
        $this->assertSame('2', $this->stat($html, 'high_priority'));
        $this->assertSame('2', $this->stat($html, 'helped_people'));
        $this->assertSame('1', $this->stat($html, 'lessons_shared'));
        $this->assertSame('Tuesday', $this->stat($html, 'best_day'));
        $arc = $this->stat($html, 'arc');
        $this->assertNotSame('', $arc, 'no character arc');
        $this->assertSame($arc, DB::table('wrapped_stories')->where('id', $story->id)->value('arc_title'));
        $this->assertContains($arc, DB::table('wrapped_arcs')->where('rule', 'steady')->where('active', 1)->pluck('title')->all(), 'Yati (5 closed, 2 high, 2 helped) is a "steady" arc');
        // Spec-style lines: numbers in sentences, not bare stats.
        $page->assertSee('5 cards');
        $page->assertSee('Tuesday');

        // The nav entry lives in The Playground.
        $page->assertSee('/app/wrapped', false)->assertSee('Wrapped');
    }

    #[Test]
    public function test_acceptance_2_yati_can_share_her_wrapped_to_the_wall(): void
    {
        $this->septemberFixture();
        $this->build('2026-10-01 08:00:00');
        Carbon::setTestNow('2026-10-01 10:00:00');
        $story = $this->story($this->yati);
        $company = DB::table('wrapped_stories')->whereDate('month', self::SEPTEMBER)->whereNull('employee_id')->first();

        // Private by default: not on the Wall, not shareable by anyone else.
        $this->actingInTenantAs($this->shazwan)->get('/app/wins')->assertOk()->assertDontSee('data-win-wrapped=', false);
        $this->actingInTenantAs($this->shazwan)->post("/app/wrapped/{$story->id}/share")->assertStatus(403);
        $this->actingInTenantAs($this->hidayah)->post("/app/wrapped/{$story->id}/share")->assertStatus(403);
        $this->actingInTenantAs($this->yati)->post("/app/wrapped/{$company->id}/share")->assertStatus(404);
        $this->assertNull(DB::table('wrapped_stories')->where('id', $story->id)->value('shared_at'));

        $this->actingInTenantAs($this->yati)->post("/app/wrapped/{$story->id}/share")->assertRedirect();
        $this->assertNotNull(DB::table('wrapped_stories')->where('id', $story->id)->value('shared_at'));
        $audit = AuditLog::where('action', 'wrapped.shared')->sole();
        $this->assertSame("wrapped_story:{$story->id}", $audit->target);
        $this->assertSame($this->yati->user_id, $audit->user_id);

        foreach ([$this->shazwan, $this->kussairi, $this->yati] as $viewer) {
            $wins = $this->actingInTenantAs($viewer)->get('/app/wins')->assertOk();
            $wins->assertSee('data-win-wrapped="'.$story->id.'"', false)->assertSee('Yati Dev')->assertSee('September')->assertSee($story->arc_title);
        }

        // Unshare pulls it back.
        $this->actingInTenantAs($this->yati)->post("/app/wrapped/{$story->id}/unshare")->assertRedirect();
        $this->assertNull(DB::table('wrapped_stories')->where('id', $story->id)->value('shared_at'));
        $this->assertSame(1, AuditLog::where('action', 'wrapped.unshared')->count());
        $this->actingInTenantAs($this->shazwan)->get('/app/wins')->assertOk()->assertDontSee('data-win-wrapped=', false);
    }

    #[Test]
    public function test_acceptance_3_dashboard_shows_company_wrapped_with_reactions(): void
    {
        $this->septemberFixture();

        Carbon::setTestNow('2026-09-30 10:00:00');
        $this->actingInTenantAs($this->shazwan)->get('/app/dash')->assertOk()->assertDontSee('data-kind="wrapped"', false);

        $this->build('2026-10-01 08:00:00');
        Carbon::setTestNow('2026-10-01 10:00:00');
        $company = DB::table('wrapped_stories')->whereDate('month', self::SEPTEMBER)->whereNull('employee_id')->first();

        foreach ([$this->shazwan, $this->yati, $this->shahril] as $viewer) {
            $page = $this->actingInTenantAs($viewer)->get('/app/dash')->assertOk();
            $html = $page->getContent();
            $page->assertSee('data-kind="wrapped"', false)
                ->assertSee('data-wrapped-company="'.$company->id.'"', false)
                ->assertSee('SEPTEMBER, WRAPPED')
                ->assertSee('data-reaction-pick', false)
                ->assertSee('"urgent"', false);
            $moment = $this->section($html, 'data-kind="wrapped"', '</section>');
            $this->assertSame('9', $this->stat($moment, 'cards_closed'));
            $this->assertSame('4', $this->stat($moment, 'lessons_shared'));
            $this->assertSame('5', $this->stat($moment, 'fires'));
            $this->assertSame('3', $this->stat($moment, 'urgent'));
            $this->assertStringContainsString('9 cards closed', $moment);
            $this->assertStringContainsString('4 lessons shared', $moment);
            $this->assertStringContainsString('5 fires extinguished', $moment);
            $this->assertStringContainsString('only 3 "urgent" tasks', $moment);
            $page->assertSeeInOrder(['data-kind="wrapped"', 'Current month summary', 'Daily clock log', 'Pending tasks'], false);
        }

        // Reactions, CR-30 semantics.
        $this->actingInTenantAs($this->shazwan)->postJson("/app/wrapped/{$company->id}/react", ['reaction' => 'thumbs'])->assertStatus(422);
        $this->actingInTenantAs($this->shazwan)->postJson("/app/wrapped/{$company->id}/react", ['reaction' => 'respect'])->assertOk();
        $this->actingInTenantAs($this->yati)->postJson("/app/wrapped/{$company->id}/react", ['reaction' => 'respect'])->assertOk();
        $this->actingInTenantAs($this->shazwan)->postJson("/app/wrapped/{$company->id}/react", ['reaction' => 'legend'])->assertOk();
        $rows = DB::table('wrapped_reactions')->where('wrapped_story_id', $company->id)->get();
        $this->assertSame(2, $rows->count(), 'one reaction per person');
        $this->assertSame('legend', $rows->firstWhere('employee_id', $this->shazwan->id)->reaction);
        $this->actingInTenantAs($this->yati)->postJson("/app/wrapped/{$company->id}/react", ['reaction' => 'respect'])->assertOk();
        $this->assertSame(1, DB::table('wrapped_reactions')->where('wrapped_story_id', $company->id)->count(), 'same key again removes');
        $moment = $this->section($this->actingInTenantAs($this->kussairi)->get('/app/dash')->getContent(), 'data-kind="wrapped"', '</section>');
        $this->assertMatchesRegularExpression('/data-reaction-count="legend"[^>]*>[^<]*1/', $moment);

        // Gone after the 7th, and never for another tenant.
        Carbon::setTestNow('2026-10-08 10:00:00');
        $this->actingInTenantAs($this->shazwan)->get('/app/dash')->assertOk()->assertDontSee('data-kind="wrapped"', false);
        Carbon::setTestNow('2026-10-02 10:00:00');
        $other = Tenant::create(['slug' => 'other', 'name' => 'Other', 'initials' => 'OT']);
        $user = User::create(['name' => 'Stranger', 'email' => 'stranger@example.com', 'password' => bcrypt('password')]);
        $user->tenants()->attach($other->id, ['role' => 'employee']);
        Employee::create(['tenant_id' => $other->id, 'user_id' => $user->id, 'name' => 'Stranger', 'status' => 'active', 'workload' => 'green']);
        $this->actingAs($user)->withSession(['current_tenant' => $other->id])->get('/app/dash')->assertOk()->assertDontSee('data-kind="wrapped"', false);
        $this->actingAs($user)->withSession(['current_tenant' => $other->id])->postJson("/app/wrapped/{$company->id}/react", ['reaction' => 'respect'])->assertNotFound();
    }

    #[Test]
    public function test_acceptance_4_keep_it_plain_gets_a_plain_text_summary(): void
    {
        $this->septemberFixture();
        $this->build('2026-10-01 08:00:00');
        Carbon::setTestNow('2026-10-01 10:00:00');
        $story = $this->story($this->yati);

        $loud = $this->actingInTenantAs($this->yati)->get('/app/wrapped')->assertOk();
        $loud->assertSee('data-wrapped-card="1"', false)->assertDontSee('data-wrapped-plain', false);

        $this->actingInTenantAs($this->yati)->postJson('/app/dashboard/prefs', ['plain' => true])->assertOk();
        $page = $this->actingInTenantAs($this->yati)->get('/app/wrapped')->assertOk();
        $html = $page->getContent();
        $page->assertSee('data-wrapped="'.$story->id.'"', false)
            ->assertSee('data-wrapped-plain', false)
            ->assertDontSee('data-wrapped-card=', false)
            ->assertDontSee('uj-wr-art', false)
            ->assertDontSee('<canvas', false)
            ->assertDontSee('<audio', false);
        $plain = $this->section($html, 'data-wrapped-plain', '</');
        foreach (['5', '2', 'Tuesday', $story->arc_title] as $needle) {
            $this->assertStringContainsString($needle, $plain, "plain summary lacks {$needle}");
        }

        $dash = $this->actingInTenantAs($this->yati)->get('/app/dash')->assertOk();
        $dash->assertSee('data-kind="wrapped"', false)->assertSee('data-plain', false)
            ->assertDontSee('uj-db-art', false)->assertDontSee('uj-db-confetti', false)
            ->assertSee('9 cards closed');
    }

    #[Test]
    public function test_acceptance_5_rules_private_numbers_only_curated_arcs_snapshot_no_comparison(): void
    {
        $this->septemberFixture();
        $this->build('2026-10-01 08:00:00');
        Carbon::setTestNow('2026-10-01 10:00:00');
        $yati = $this->story($this->yati);
        $shazwan = $this->story($this->shazwan);

        // Private: only your own story, no way to reach another person's.
        $mine = $this->actingInTenantAs($this->shazwan)->get('/app/wrapped')->assertOk();
        $mine->assertSee('data-wrapped="'.$shazwan->id.'"', false)->assertDontSee('data-wrapped="'.$yati->id.'"', false);
        $this->actingInTenantAs($this->shazwan)->get('/app/wrapped?emp='.$this->yati->id)->assertOk()->assertDontSee('data-wrapped="'.$yati->id.'"', false);
        $this->actingInTenantAs($this->shahril)->get('/app/wrapped')->assertOk()->assertDontSee('data-wrapped="'.$yati->id.'"', false);

        // No comparison: another person's name never appears on your story, no ranking words.
        $html = $mine->getContent();
        $body = substr($html, strpos($html, 'data-wrapped="'));
        foreach (['Yati Dev', 'Kussairi PM', 'Hidayah HR', 'Shahril Director'] as $name) {
            $this->assertStringNotContainsString($name, $body, "{$name} named on Shazwan's story");
        }
        $this->assertDoesNotMatchRegularExpression('/\b(rank|ranked|top \d|#\d|than (everyone|anyone|the team|your colleagues)|more than|less than|fewer than|percentile)\b/i', strip_tags($body));

        // Numbers only: the distinctive comment and description text never reaches a story.
        $cards = DB::table('wrapped_stories')->whereDate('month', self::SEPTEMBER)->pluck('cards')->implode(' ');
        $this->assertStringNotContainsString('zebra-quokka-9000', $cards);
        $this->assertStringNotContainsString('zebra-quokka-9000', $html);

        // Snapshot is the source: the number shown is the frozen one, not a live recount.
        $this->assertSame('5', $this->stat($this->actingInTenantAs($this->yati)->get('/app/wrapped')->getContent(), 'cards_closed'));
        $this->assertSame(7, WorkItem::where('employee_id', $this->yati->id)->where('status', 'done')->count(), 'fixture: 7 done cards on the board, 5 in the frozen snapshot');

        // Curated arcs: 30+ seeded, at least 3 per rule, HR-editable, employee/manager not.
        $arcs = DB::table('wrapped_arcs')->where('tenant_id', $this->tenant()->id)->where('active', 1)->get();
        $this->assertGreaterThanOrEqual(30, $arcs->count());
        foreach (['firefighter', 'helper', 'closer', 'quiet', 'steady'] as $rule) {
            $this->assertGreaterThanOrEqual(3, $arcs->where('rule', $rule)->count(), "fewer than 3 '{$rule}' arcs");
        }
        $this->actingInTenantAs($this->kussairi)->post('/app/wrapped/arcs', ['title' => 'Somehow Got It Done', 'rule' => 'steady'])->assertStatus(403);
        $this->actingInTenantAs($this->shazwan)->post('/app/wrapped/arcs', ['title' => 'Somehow Got It Done', 'rule' => 'steady'])->assertStatus(403);
        $this->actingInTenantAs($this->hidayah)->post('/app/wrapped/arcs', ['title' => 'Somehow Got It Done', 'rule' => 'nope'])->assertSessionHasErrors('rule');
        $this->actingInTenantAs($this->hidayah)->post('/app/wrapped/arcs', ['title' => 'Somehow Got It Done', 'rule' => 'steady'])->assertRedirect()->assertSessionHasNoErrors();
        $added = DB::table('wrapped_arcs')->where('title', 'Somehow Got It Done')->first();
        $this->assertNotNull($added);
        $this->assertSame(1, AuditLog::where('action', 'wrapped.arc_added')->where('target', "wrapped_arc:{$added->id}")->count());
        $this->actingInTenantAs($this->hidayah)->get('/app/wrapped')->assertOk()->assertSee('data-wrapped-arc="'.$added->id.'"', false);
        $this->actingInTenantAs($this->shazwan)->get('/app/wrapped')->assertOk()->assertDontSee('data-wrapped-arc=', false);
        $this->actingInTenantAs($this->kussairi)->post("/app/wrapped/arcs/{$added->id}/retire")->assertStatus(403);
        $this->actingInTenantAs($this->shahril)->post("/app/wrapped/arcs/{$added->id}/retire")->assertRedirect();
        $this->assertSame(0, (int) DB::table('wrapped_arcs')->where('id', $added->id)->value('active'));
        $this->assertSame(1, AuditLog::where('action', 'wrapped.arc_retired')->count());

        // Rule order: 3+ high-priority makes a firefighter, whatever else happened.
        $this->assertContains($shazwan->arc_title, DB::table('wrapped_arcs')->where('rule', 'firefighter')->pluck('title')->all(), 'Shazwan (3 high-priority) should be a firefighter arc');

        // No export, no report, no management view.
        $paths = collect(app('router')->getRoutes()->getRoutes())->map(fn ($r) => $r->uri())->filter(fn ($u) => str_contains($u, 'wrapped'))->values()->all();
        $this->assertEqualsCanonicalizing(['app/wrapped', 'app/wrapped/arcs', 'app/wrapped/arcs/{arc}/retire', 'app/wrapped/{story}/share', 'app/wrapped/{story}/unshare', 'app/wrapped/{story}/react'], $paths);
    }

    public function test_always_checks(): void
    {
        $this->assertDueDateLocked();
        $this->assertAuditLogImmutable();
        $this->assertDashboardUnchanged();
        $this->assertKeepItPlainHonoured();
    }

    // ── helpers ──

    /**
     * September on the board: Yati owns 7 done cards (5 counted by the frozen snapshot,
     * the board has moved on since), 3 of them done on Tuesdays (1, 8, 15 Sep), 2 high
     * priority; she helped on 3 cards owned by Shazwan and Kussairi (2 different people).
     * Shazwan: 3 high-priority cards done, one of them created in August. Kussairi: 1.
     * Snapshot (frozen 30 Sep): done_and_dusted Yati 5 / Shazwan 3 / Kussairi 1,
     * chief_firefighter Yati 2 / Shazwan 3, walking_wikipedia Yati 1 / Shazwan 3.
     * Company for September: 9 cards done (5 + 3 + 1), 4 lessons (1 + 3), 5 fires
     * (high-priority done: 2 + 3), 3 "urgent" (high-priority created in September: Yati 1,
     * her 1 Sep card was created on 31 Aug by finishedCard(), + Shazwan 2). QA fixture fix.
     */
    private function septemberFixture(): void
    {
        // Yati: 5 done in September on the board (3 Tuesdays), 2 of them high priority.
        $yatiDone = ['2026-09-01 10:00:00', '2026-09-08 10:00:00', '2026-09-15 10:00:00', '2026-09-03 10:00:00', '2026-09-10 10:00:00'];
        foreach ($yatiDone as $i => $at) {
            $this->finishedCard($this->yati, $at, ['title' => "Yati card {$i}", 'priority' => $i < 2 ? 'high' : 'low', 'description' => 'zebra-quokka-9000 secret plan']);
        }
        // Two more done in October: on the board but not in September's snapshot.
        $this->finishedCard($this->yati, '2026-10-01 07:00:00', ['title' => 'Yati late 1']);
        $this->finishedCard($this->yati, '2026-10-01 07:30:00', ['title' => 'Yati late 2']);

        // Shazwan: 3 high-priority done in September, one of them created in August.
        Carbon::setTestNow('2026-08-20 10:00:00');
        $old = $this->card($this->shazwan, ['title' => 'Shazwan old fire', 'priority' => 'high']);
        $this->moveAs($this->shazwan, $old, 'done', '2026-09-05 10:00:00');
        $this->finishedCard($this->shazwan, '2026-09-12 10:00:00', ['title' => 'Shazwan fire 2', 'priority' => 'high']);
        $s3 = $this->finishedCard($this->shazwan, '2026-09-19 10:00:00', ['title' => 'Shazwan fire 3', 'priority' => 'high']);
        // Helper credit: Yati helped on two of Shazwan's and one of Kussairi's cards.
        $s3->participants()->attach($this->yati->id, ['role' => 'helper']);
        $old->participants()->attach($this->yati->id, ['role' => 'helper']);
        $k = $this->finishedCard($this->kussairi, '2026-09-22 10:00:00', ['title' => 'Kussairi card']);
        $k->participants()->attach($this->yati->id, ['role' => 'helper']);

        // A comment with distinctive text: never analysed.
        Carbon::setTestNow('2026-09-23 10:00:00');
        $this->actingInTenantAs($this->yati)->postJson("/app/board/{$k->id}/comments", ['body' => 'zebra-quokka-9000 was the trick'])->assertSuccessful();

        // Frozen snapshot, as awards:freeze writes it (numbers the stories must use).
        $rows = [
            ['done_and_dusted', $this->yati, 5, '5 cards finished this month'],
            ['done_and_dusted', $this->shazwan, 3, '3 cards finished this month'],
            ['done_and_dusted', $this->kussairi, 1, '1 card finished this month'],
            ['chief_firefighter', $this->yati, 2, '2 high-priority cards put out this month'],
            ['chief_firefighter', $this->shazwan, 3, '3 high-priority cards put out this month'],
            ['walking_wikipedia', $this->yati, 1, '1 Knowledge Bank entry added this month'],
            ['walking_wikipedia', $this->shazwan, 3, '3 Knowledge Bank entries added this month'],
        ];
        foreach ($rows as [$key, $who, $value, $label]) {
            DB::table('award_snapshots')->insert([
                'tenant_id' => $this->tenant()->id, 'month' => self::SEPTEMBER, 'award_key' => $key, 'employee_id' => $who->id,
                'value' => $value, 'label' => $label, 'frozen_at' => '2026-09-30 23:59:00', 'created_at' => '2026-09-30 23:59:00', 'updated_at' => '2026-09-30 23:59:00',
            ]);
        }
        Carbon::setTestNow();
    }

    private function build(string $at): void
    {
        Carbon::setTestNow($at);
        Artisan::call('wrapped:build');
        $this->assertScheduled('wrapped:build', '0 8 * * *');
    }

    private function assertScheduled(string $command, string $expression): void
    {
        $events = collect(app(Schedule::class)->events())->filter(fn ($e) => str_contains($e->command ?? '', $command));
        $this->assertTrue($events->isNotEmpty(), "{$command} is not in the scheduler");
        $this->assertSame($expression, $events->first()->expression, "{$command} schedule expression");
    }

    private function story(Employee $who): object
    {
        $row = DB::table('wrapped_stories')->whereDate('month', self::SEPTEMBER)->where('employee_id', $who->id)->first();
        $this->assertNotNull($row, "{$who->name} has no September story");

        return $row;
    }

    private function finishedCard(Employee $owner, string $doneAt, array $attrs = []): WorkItem
    {
        Carbon::setTestNow(Carbon::parse($doneAt)->subDay());
        $card = $this->card($owner, $attrs + ['title' => 'Card for '.$owner->name]);
        $this->moveAs($owner, $card, 'done', $doneAt);

        return $card->fresh();
    }

    private function moveAs(Employee $actor, WorkItem $card, string $status, string $at): void
    {
        Carbon::setTestNow($at);
        $this->actingInTenantAs($actor)->postJson("/app/board/{$card->id}/move", ['status' => $status])->assertOk();
        $this->assertSame($status, $card->fresh()->status);
    }

    /** The text of the first data-wrapped-stat="<key>" element. */
    private function stat(string $html, string $key): string
    {
        $this->assertMatchesRegularExpression('/data-wrapped-stat="'.preg_quote($key, '/').'"[^>]*>([^<]*)</', $html, "stat {$key} missing");
        preg_match('/data-wrapped-stat="'.preg_quote($key, '/').'"[^>]*>([^<]*)</', $html, $m);

        return trim($m[1]);
    }

    private function section(string $html, string $start, string $end): string
    {
        $from = strpos($html, $start);
        $this->assertNotFalse($from, "{$start} is not on the page");
        $to = strpos($html, $end, $from + strlen($start));

        return $to === false ? substr($html, $from) : substr($html, $from, $to - $from);
    }
}
