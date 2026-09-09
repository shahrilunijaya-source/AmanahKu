<?php

namespace Tests\Acceptance;

use App\Models\AuditLog;
use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Acceptance for docs/specs/CR-29.md (session S25, Friday Sign-Off). The spec's
 * Acceptance sentence is items 1 to 4 in the order it lists them; the Rules paragraph
 * (shared vs private win, closes Monday 9 AM, never exported) is item 5; Keep it plain
 * (culture-pack preamble) is item 6.
 *
 * Shapes this file fixes for the generator (recorded in docs/build/OPEN.md):
 * - the card is the existing `friday` widget (contracts/dashboard-slots.md, S04 slot,
 *   `data-widget="friday"`, Friday 15:00 up to Monday 09:00). Inside it, before the
 *   viewer has answered: `[data-friday-signoff]` with four `[data-mood="<key>"]` buttons
 *   for keys `productive`, `chaotic`, `peaceful`, `survived` (labels "Productive",
 *   "Chaotic", "Suspiciously Peaceful", "I Survived"), a one-line `win` input (max 160)
 *   and a `share` checkbox. After answering: `[data-friday-done]` and no `[data-mood]`.
 * - `POST /app/friday-signoff {mood, win?, share?}` → 200 JSON `{ok:true}`; a second
 *   submission by the same person in the same week → 422; outside the window (before
 *   Friday 15:00 or from Monday 09:00) → 422; unknown mood → 422.
 * - tables: `friday_moods` (tenant_id, week_of date = that Friday, mood, created_at)
 *   with NO employee_id, user_id or any identity column; `friday_receipts` (tenant_id,
 *   week_of, receipt char(64)) with NO mood column and NO created_at; `friday_wins`
 *   (tenant_id, week_of, employee_id, text, shared bool, timestamps). Receipt =
 *   `hash_hmac('sha256', "<user id>:<week_of>", config('app.key'))`. The audit entry for
 *   a sign-off is `friday.signed_off` with `user_id` NULL and target `friday:<week_of>`
 *   (a state change per global-clause.md, anonymous per this CR). A shared win is
 *   audited normally as `friday.win_shared`, target `friday_win:<id>`.
 * - company mood: from Friday 17:00 (until the window closes) the widget carries
 *   `[data-friday-mood]` with one `[data-mood-pct="<key>"]` per mood holding the integer
 *   percentage of responses (largest remainder, sums to 100), for every role. Under 5
 *   responses there is no `[data-friday-mood]` and no percentage; the widget says the
 *   mood needs more sign-offs. Before 17:00 there is no `[data-friday-mood]` either.
 * - no per-person mood anywhere: no route other than the sign-off POST and the dashboard
 *   mentions "friday" (in particular no export), the director's dashboard, team board,
 *   audit screen and audit export never pair a person's name with a mood.
 * - wins: a win with `share` on renders in the widget for everyone from 17:00 as
 *   `[data-friday-win="<id>"]` with the author's name and the text; a win without
 *   `share` renders only for its author as `[data-friday-my-win]` and never carries a
 *   name for anyone else.
 * - Keep it plain: the widget keeps the buttons, the percentages and the wins as text,
 *   with no `uj-fr-art`, no `uj-db-confetti`, no `<canvas`, no `<audio`, and the
 *   cheeky label "Suspiciously Peaceful" becomes "Peaceful", "I Survived" stays.
 */
class CR29Test extends TestCase
{
    use AlwaysChecks;
    use RefreshDatabase;

    private const FRIDAY = '2026-09-11';

    private Employee $shahril;

    private Employee $kussairi;

    private Employee $yati;

    private Employee $shazwan;

    /** @var list<Employee> */
    private array $others = [];

    protected function setUp(): void
    {
        parent::setUp();
        // Friday 11 Sep 2026, 15:00.
        Carbon::setTestNow(self::FRIDAY.' 15:00:00');

        $this->shahril = $this->person('Shahril Director', 'director');
        $this->kussairi = $this->person('Kussairi PM', 'manager');
        $this->yati = $this->person('Yati Dev');
        $this->shazwan = $this->person('Shazwan Dev');
        foreach (['Adri', 'Irfan', 'Syafiq'] as $n) {
            $this->others[] = $this->person($n.' Dev');
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    #[Test]
    public function test_acceptance_1_friday_3pm_prompt_appears(): void
    {
        // Thursday, and Friday 14:59: no card at all.
        foreach (['2026-09-10 15:00:00', self::FRIDAY.' 14:59:00'] as $when) {
            Carbon::setTestNow($when);
            $this->actingInTenantAs($this->yati)->get('/app/dash')->assertOk()->assertDontSee('data-widget="friday"', false);
            $this->actingInTenantAs($this->yati)->postJson('/app/friday-signoff', ['mood' => 'productive'])->assertStatus(422);
        }
        $this->assertSame(0, DB::table('friday_moods')->count());

        Carbon::setTestNow(self::FRIDAY.' 15:00:00');
        foreach ([$this->yati, $this->shahril] as $viewer) {
            $html = $this->widgetHtml($viewer);
            $this->assertStringContainsString('data-friday-signoff', $html);
            foreach (['productive', 'chaotic', 'peaceful', 'survived'] as $key) {
                $this->assertStringContainsString('data-mood="'.$key.'"', $html);
            }
            foreach (['Productive', 'Chaotic', 'Suspiciously Peaceful', 'I Survived'] as $label) {
                $this->assertStringContainsString($label, $html);
            }
            $this->assertMatchesRegularExpression('/name="win"/', $html);
            $this->assertMatchesRegularExpression('/name="share"/', $html);
            $this->assertStringNotContainsString('data-friday-done', $html);
            $this->assertStringNotContainsString('data-friday-mood', $html);
        }
    }

    #[Test]
    public function test_acceptance_2_tap_once_done(): void
    {
        $this->actingInTenantAs($this->yati)
            ->postJson('/app/friday-signoff', ['mood' => 'productive'])
            ->assertOk()->assertJsonPath('ok', true);

        $this->assertSame(1, DB::table('friday_moods')->where('week_of', self::FRIDAY)->where('mood', 'productive')->count());
        $this->assertSame(1, DB::table('friday_receipts')->where('week_of', self::FRIDAY)->count());
        $receipt = DB::table('friday_receipts')->value('receipt');
        $this->assertSame(hash_hmac('sha256', $this->yati->user_id.':'.self::FRIDAY, config('app.key')), $receipt);

        // Tap once: the widget now says done and offers no buttons.
        $html = $this->widgetHtml($this->yati);
        $this->assertStringContainsString('data-friday-done', $html);
        $this->assertStringNotContainsString('data-mood="', $html);

        // Twice in one week is refused, nothing else written.
        $this->actingInTenantAs($this->yati)->postJson('/app/friday-signoff', ['mood' => 'chaotic'])->assertStatus(422);
        $this->assertSame(1, DB::table('friday_moods')->count());
        $this->assertSame(1, DB::table('friday_receipts')->count());

        // Unknown mood refused; the mood is required.
        $this->actingInTenantAs($this->shazwan)->postJson('/app/friday-signoff', ['mood' => 'meh'])->assertStatus(422);
        $this->actingInTenantAs($this->shazwan)->postJson('/app/friday-signoff', ['win' => 'Shipped it'])->assertStatus(422);
        $this->assertSame(1, DB::table('friday_moods')->count());

        // The audit trail records that a sign-off happened, never by whom.
        $audit = AuditLog::where('action', 'friday.signed_off')->get();
        $this->assertCount(1, $audit);
        $this->assertNull($audit->first()->user_id);
        $this->assertSame('friday:'.self::FRIDAY, $audit->first()->target);
        $this->assertStringNotContainsString('Yati', (string) $audit->first()->actor_name);

        // Saturday still counts as this week (window open), Monday 09:00 does not (item 5).
        Carbon::setTestNow('2026-09-12 10:00:00');
        $this->actingInTenantAs($this->shazwan)->postJson('/app/friday-signoff', ['mood' => 'survived'])->assertOk();
        $this->assertSame(2, DB::table('friday_moods')->where('week_of', self::FRIDAY)->count());
    }

    #[Test]
    public function test_acceptance_3_5pm_company_mood_shows_percentages(): void
    {
        // 6 responses: 3 productive, 2 chaotic, 1 survived → 50 / 33 / 0 / 17.
        $this->signOff($this->yati, 'productive');
        $this->signOff($this->shazwan, 'productive');
        $this->signOff($this->kussairi, 'productive');
        $this->signOff($this->others[0], 'chaotic');
        $this->signOff($this->others[1], 'chaotic');
        $this->signOff($this->others[2], 'survived');

        // 16:59: still nothing, for anyone.
        Carbon::setTestNow(self::FRIDAY.' 16:59:00');
        foreach ([$this->yati, $this->shahril] as $viewer) {
            $this->assertStringNotContainsString('data-friday-mood', $this->widgetHtml($viewer));
        }

        Carbon::setTestNow(self::FRIDAY.' 17:00:00');
        foreach ([$this->yati, $this->shahril, $this->kussairi] as $viewer) {
            $html = $this->widgetHtml($viewer);
            $this->assertStringContainsString('data-friday-mood', $html);
            $this->assertSame(['productive' => 50, 'chaotic' => 33, 'peaceful' => 0, 'survived' => 17], $this->percentages($html));
            $this->assertStringContainsString('50%', $html);
        }

        // Still shown over the weekend, gone with the card on Monday 09:00.
        Carbon::setTestNow('2026-09-13 20:00:00');
        $this->assertStringContainsString('data-friday-mood', $this->widgetHtml($this->yati));
        Carbon::setTestNow('2026-09-14 09:00:00');
        $this->actingInTenantAs($this->yati)->get('/app/dash')->assertOk()->assertDontSee('data-widget="friday"', false);
    }

    #[Test]
    public function test_acceptance_4_director_view_has_no_per_person_mood(): void
    {
        $this->signOff($this->yati, 'chaotic');
        $this->signOff($this->shazwan, 'productive');
        $this->signOff($this->kussairi, 'survived');
        $this->signOff($this->others[0], 'peaceful');
        $this->signOff($this->others[1], 'productive');

        // No identity column on the answers, no answer column on the receipts.
        foreach (['employee_id', 'user_id', 'created_by', 'receipt', 'name'] as $col) {
            $this->assertFalse(Schema::hasColumn('friday_moods', $col), "friday_moods.{$col} ties a mood to a person");
        }
        foreach (['mood', 'employee_id', 'user_id', 'created_at'] as $col) {
            $this->assertFalse(Schema::hasColumn('friday_receipts', $col), "friday_receipts.{$col} can be joined back");
        }

        // Every audit row about a sign-off is anonymous.
        foreach (AuditLog::where('action', 'like', 'friday.signed%')->get() as $row) {
            $this->assertNull($row->user_id);
            foreach (['Yati', 'Shazwan', 'Kussairi', 'Adri', 'Irfan'] as $name) {
                $this->assertStringNotContainsString($name, $row->actor_name.' '.$row->target.' '.json_encode($row->meta ?? $row->details ?? ''));
            }
        }

        // The director's surfaces never pair a name with a mood, before or after 17:00.
        foreach ([self::FRIDAY.' 16:00:00', self::FRIDAY.' 17:30:00'] as $when) {
            Carbon::setTestNow($when);
            $friday = $this->widgetHtml($this->shahril);
            foreach (['Yati', 'Shazwan', 'Kussairi', 'Adri', 'Irfan'] as $name) {
                $this->assertStringNotContainsString($name, $friday, "director's Friday card names {$name}");
            }
            foreach (['/app/team-board', '/app/audit', '/app/audit/export'] as $url) {
                $body = $this->actingInTenantAs($this->shahril)->get($url);
                if ($body->getStatusCode() !== 200) {
                    continue;
                }
                $this->assertDoesNotMatchRegularExpression('/(Yati|Shazwan|Kussairi|Adri|Irfan)[^<\n]{0,80}(productive|chaotic|peaceful|survived)/i', $this->stripChrome($body->getContent()), "{$url} pairs a person with a mood");
                $this->assertDoesNotMatchRegularExpression('/(productive|chaotic|peaceful|survived)[^<\n]{0,80}(Yati|Shazwan|Kussairi|Adri|Irfan)/i', $this->stripChrome($body->getContent()), "{$url} pairs a mood with a person");
            }
        }

        // Nothing exports it: the only routes that know about the sign-off are the POST and its results-free dashboard.
        $fridayRoutes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($r) => str_contains($r->uri(), 'friday'))
            ->map(fn ($r) => implode('|', $r->methods()).' '.$r->uri())
            ->values()->all();
        foreach ($fridayRoutes as $route) {
            $this->assertStringNotContainsString('export', $route);
            $this->assertStringNotContainsString('report', $route);
            $this->assertStringNotContainsString('GET|HEAD', $route, "a GET route reads sign-off data: {$route}");
        }
    }

    #[Test]
    public function test_acceptance_5_with_4_responses_mood_is_hidden(): void
    {
        $this->signOff($this->yati, 'productive');
        $this->signOff($this->shazwan, 'productive');
        $this->signOff($this->kussairi, 'chaotic');
        $this->signOff($this->others[0], 'survived');

        Carbon::setTestNow(self::FRIDAY.' 17:00:00');
        foreach ([$this->yati, $this->shahril] as $viewer) {
            $html = $this->widgetHtml($viewer);
            $this->assertStringNotContainsString('data-friday-mood', $html);
            $this->assertStringNotContainsString('data-mood-pct', $html);
            $this->assertDoesNotMatchRegularExpression('/\d+%/', $html);
        }

        // The fifth answer flips it on.
        Carbon::setTestNow(self::FRIDAY.' 17:10:00');
        $this->signOff($this->others[1], 'peaceful');
        $html = $this->widgetHtml($this->shahril);
        $this->assertStringContainsString('data-friday-mood', $html);
        $this->assertSame(['productive' => 40, 'chaotic' => 20, 'peaceful' => 20, 'survived' => 20], $this->percentages($html));
    }

    #[Test]
    public function test_acceptance_6_my_win_shared_or_private(): void
    {
        $this->actingInTenantAs($this->yati)
            ->postJson('/app/friday-signoff', ['mood' => 'productive', 'win' => 'Closed the MySToDS audit', 'share' => true])
            ->assertOk();
        $this->actingInTenantAs($this->shazwan)
            ->postJson('/app/friday-signoff', ['mood' => 'chaotic', 'win' => 'Survived the payroll run', 'share' => false])
            ->assertOk();
        $this->actingInTenantAs($this->kussairi)
            ->postJson('/app/friday-signoff', ['mood' => 'chaotic'])
            ->assertOk();

        $this->assertSame(2, DB::table('friday_wins')->count());
        $shared = DB::table('friday_wins')->where('employee_id', $this->yati->id)->first();
        $private = DB::table('friday_wins')->where('employee_id', $this->shazwan->id)->first();
        $this->assertSame(1, (int) $shared->shared);
        $this->assertSame(0, (int) $private->shared);

        // The win is a plain text line: long ones are refused.
        $this->actingInTenantAs($this->others[0])
            ->postJson('/app/friday-signoff', ['mood' => 'survived', 'win' => str_repeat('x', 161)])
            ->assertStatus(422);

        // Shared win audited under the author; the mood next to it stays anonymous.
        $audit = AuditLog::where('action', 'friday.win_shared')->sole();
        $this->assertSame($this->yati->user_id, $audit->user_id);
        $this->assertSame("friday_win:{$shared->id}", $audit->target);

        Carbon::setTestNow(self::FRIDAY.' 17:00:00');
        // Everyone sees Yati's shared win under her name.
        foreach ([$this->kussairi, $this->shahril, $this->shazwan] as $viewer) {
            $html = $this->widgetHtml($viewer);
            $this->assertStringContainsString('data-friday-win="'.$shared->id.'"', $html);
            $this->assertStringContainsString('Closed the MySToDS audit', $html);
            $this->assertStringContainsString('Yati Dev', $html);
            if ($viewer->id !== $this->shazwan->id) {
                $this->assertStringNotContainsString('Survived the payroll run', $html);
                $this->assertStringNotContainsString('Shazwan', $html);
            }
        }
        // Shazwan sees his private win, marked private, without his name being posted.
        $mine = $this->widgetHtml($this->shazwan);
        $this->assertStringContainsString('data-friday-my-win', $mine);
        $this->assertStringContainsString('Survived the payroll run', $mine);
        $this->assertStringNotContainsString('data-friday-win="'.$private->id.'"', $mine);
    }

    #[Test]
    public function test_acceptance_7_keep_it_plain(): void
    {
        foreach ([$this->yati, $this->shazwan, $this->kussairi, $this->others[0], $this->others[1]] as $who) {
            $this->signOff($who, 'peaceful');
        }
        $this->actingInTenantAs($this->shahril)->postJson('/app/dashboard/prefs', ['plain' => true])->assertOk();

        Carbon::setTestNow(self::FRIDAY.' 15:30:00');
        $before = $this->widgetHtml($this->shahril);
        $this->assertStringContainsString('data-mood="peaceful"', $before);
        $this->assertStringNotContainsString('Suspiciously', $before);
        $this->assertStringContainsString('Peaceful', $before);

        Carbon::setTestNow(self::FRIDAY.' 17:00:00');
        $this->signOff($this->others[2], 'peaceful');
        $after = $this->widgetHtml($this->shahril);
        $this->assertStringContainsString('data-friday-mood', $after);
        $this->assertSame(['productive' => 0, 'chaotic' => 0, 'peaceful' => 100, 'survived' => 0], $this->percentages($after));
        foreach ([$before, $after] as $html) {
            $this->assertStringNotContainsString('uj-fr-art', $html);
            $this->assertStringNotContainsString('uj-db-confetti', $html);
            $this->assertStringNotContainsString('<canvas', $html);
            $this->assertStringNotContainsString('<audio', $html);
            $this->assertStringNotContainsString('Suspiciously', $html);
        }
    }

    #[Test]
    public function test_always_checks_from_s25(): void
    {
        Carbon::setTestNow();
        $this->assertDueDateLocked();
        $this->assertAuditLogImmutable();
        $this->assertDashboardUnchanged();
        $this->assertKeepItPlainHonoured();
    }

    // ── helpers ──

    private function signOff(Employee $who, string $mood): void
    {
        $this->actingInTenantAs($who)->postJson('/app/friday-signoff', ['mood' => $mood])->assertOk();
    }

    /** The Friday widget's HTML from the viewer's dashboard. */
    private function widgetHtml(Employee $viewer): string
    {
        $html = $this->actingInTenantAs($viewer)->get('/app/dash')->assertOk()->getContent();
        $start = strpos($html, 'data-widget="friday"');
        $this->assertNotFalse($start, 'friday widget missing from the dashboard');
        $rest = substr($html, $start);
        $end = strpos($rest, 'data-widget="', 1);

        return $end === false ? $rest : substr($rest, 0, $end);
    }

    /** @return array<string, int> */
    private function percentages(string $html): array
    {
        preg_match_all('/data-mood-pct="(\w+)"[^>]*>\s*(\d+)/', $html, $m, PREG_SET_ORDER);
        $out = ['productive' => null, 'chaotic' => null, 'peaceful' => null, 'survived' => null];
        foreach ($m as [$_, $key, $pct]) {
            $out[$key] = (int) $pct;
        }
        $this->assertNotContains(null, $out, 'a mood is missing its percentage');

        return $out;
    }

    private function stripChrome(string $html): string
    {
        $main = strpos($html, '<main');

        return $main === false ? $html : substr($html, $main);
    }
}
