<?php

namespace Tests\Acceptance;

use App\Models\AuditLog;
use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Acceptance for docs/specs/CR-27.md (session S27, Mystery Award). The spec's single
 * Acceptance sentence is items 1 and 2 (the slide reveals on the 1st with category, winner
 * and explanation; the category is visible nowhere before publish); the Rules paragraph is
 * item 3 (Director or the month's 3-person committee picks, funny-but-kind explanation, not
 * counted toward Hall of Fame or any streak, no back-to-back winner, audited, no rubric);
 * Keep it plain (culture-pack preamble) is item 4.
 *
 * Shapes this file fixes for the generator (recorded in docs/build/OPEN.md):
 * - table `mystery_awards` (tenant_id, month = first of the month, employee_id, category
 *   max 80, explanation max 500, picked_by employee id, published_at nullable datetime,
 *   timestamps), one row per tenant and month, a later pick for the same month replaces
 *   the earlier one. It is NOT an `award_results` row: `award_results` never carries an
 *   `award_key` of `mystery`, the profile never renders `data-award-badge="mystery"` or
 *   `data-hall-of-fame="mystery"`, and the CR-14 rule-9/10 resolver never sees it.
 * - table `mystery_committee` (tenant_id, month, employee_id): the rotating committee for
 *   a month. Director only sets it with `POST /app/awards/mystery/committee
 *   {employee_ids: [3 ids]}` for the month currently open for selection (same rule as
 *   `AwardController::selectionMonth()`); exactly 3 distinct active employees, otherwise
 *   422 on `employee_ids`; everyone else 403. Replaces the month's earlier committee.
 *   Audit `award.mystery_committee`, target `mystery_committee:YYYY-MM-01`.
 * - pick: `POST /app/awards/mystery {employee_id, category, explanation}` by the director
 *   or a member of that month's committee (anyone else 403), for the selection month.
 *   `category` and `explanation` required (422). The previous month's mystery winner is
 *   refused with 422 on `employee_id` (no back-to-back). No rubric field exists on the
 *   form or the table. Audit `award.mystery_picked`, target `mystery:<row id>`.
 * - reveal: `awards:publish` (the existing first-working-day command) stamps
 *   `published_at` on last month's `mystery_awards` row. Until then the category and the
 *   explanation appear on no page for anyone, the picker included: not on `/app/dash`,
 *   not on `/app/awards` (the Select tab confirms a pick exists without echoing it), not
 *   on any profile. Once published, the dashboard awards band (`data-band="awards"`)
 *   renders `data-slide="mystery"` as its LAST slide with the category as the award
 *   name, `data-winner="<employee_id>"`, the winner's name and the explanation; and
 *   `/app/awards` (default and `?month=YYYY-MM-01`) renders `data-award="mystery"` with
 *   the same three things.
 * - Keep it plain: the slide keeps category, winner and explanation, the band carries
 *   `data-plain` on `.uj-db` and no `uj-db-art`, no `<canvas`, no `<audio`.
 */
class CR27Test extends TestCase
{
    use AlwaysChecks;
    use RefreshDatabase;

    private const SEPTEMBER = '2026-09-01';

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
        $this->ahmad = $this->person('Ahmad', 'manager', ['joined_at' => '2022-05-02']);
        $this->nurin = $this->person('Nurin', 'employee', ['joined_at' => '2026-06-01']);
        $this->emysha = $this->person('Emysha', 'employee', ['joined_at' => '2024-02-01']);
        $this->adri = $this->person('Adri', 'employee', ['joined_at' => '2025-01-06']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    #[Test]
    public function test_acceptance_1_slide_reveals_on_the_1st_with_category_winner_and_explanation(): void
    {
        $this->regularAward(self::SEPTEMBER, 'done_and_dusted', $this->emysha);

        // Director picks in the September select window.
        Carbon::setTestNow('2026-09-29 10:00:00');
        $this->actingInTenantAs($this->director)->post('/app/awards/mystery', [
            'employee_id' => $this->adri->id,
            'category' => 'Human Google',
            'explanation' => 'Asked a question in the group chat, got the answer before finishing the sentence.',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $row = DB::table('mystery_awards')->sole();
        $this->assertSame(self::SEPTEMBER, substr((string) $row->month, 0, 10));
        $this->assertSame($this->adri->id, (int) $row->employee_id);
        $this->assertSame('Human Google', $row->category);
        $this->assertSame($this->director->id, (int) $row->picked_by);
        $this->assertNull($row->published_at);

        // 1 Oct 2026 (Thursday, first working day): awards:publish reveals it.
        Carbon::setTestNow('2026-10-01 08:00:00');
        Artisan::call('awards:publish');
        $this->assertNotNull(DB::table('mystery_awards')->sole()->published_at, 'awards:publish did not stamp published_at');

        Carbon::setTestNow('2026-10-01 10:00:00');
        foreach ([$this->emysha, $this->hr, $this->director] as $viewer) {
            $page = $this->actingInTenantAs($viewer)->get('/app/dash')->assertOk();
            $html = $page->getContent();
            $page->assertSee('data-band="awards"', false)->assertSee('data-slide="mystery"', false);
            $slide = $this->slide($html, 'mystery');
            $this->assertStringContainsString('Human Google', $slide);
            $this->assertStringContainsString('data-winner="'.$this->adri->id.'"', $slide);
            $this->assertStringContainsString('Adri', $slide);
            $this->assertStringContainsString('got the answer before finishing the sentence', $slide);
            // Last slide in the carousel.
            $this->assertGreaterThan(strrpos($html, 'data-slide="done_and_dusted"'), strpos($html, 'data-slide="mystery"'), 'the mystery slide is not last');
            $this->assertSame(strrpos($html, 'data-slide="'), strpos($html, 'data-slide="mystery"'), 'the mystery slide is not last');
        }

        foreach (['/app/awards', '/app/awards?month='.self::SEPTEMBER] as $url) {
            $page = $this->actingInTenantAs($this->emysha)->get($url)->assertOk();
            $award = $this->award($page->getContent(), 'mystery');
            $this->assertStringContainsString('Human Google', $award);
            $this->assertStringContainsString('data-winner="'.$this->adri->id.'"', $award);
            $this->assertStringContainsString('got the answer before finishing the sentence', $award);
        }
    }

    #[Test]
    public function test_acceptance_2_category_is_visible_nowhere_before_publish(): void
    {
        $this->regularAward(self::SEPTEMBER, 'done_and_dusted', $this->emysha);

        Carbon::setTestNow('2026-09-29 10:00:00');
        $this->actingInTenantAs($this->director)->post('/app/awards/mystery', [
            'employee_id' => $this->adri->id,
            'category' => 'Professional Tab Collector',
            'explanation' => 'Forty-three tabs open, every one of them important, apparently.',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $secrets = ['Professional Tab Collector', 'Forty-three tabs open', 'data-slide="mystery"', 'data-award="mystery"'];
        $pages = ['/app/dash', '/app/awards', '/app/awards?month='.self::SEPTEMBER, '/app/profile', '/app/profile?emp='.$this->adri->id];

        // Before the 1st, nothing for anyone, the director who typed it included.
        foreach (['2026-09-29 10:05:00', '2026-09-30 23:00:00'] as $at) {
            Carbon::setTestNow($at);
            foreach ([$this->director, $this->adri, $this->emysha, $this->hr] as $viewer) {
                foreach ($pages as $url) {
                    $html = $this->actingInTenantAs($viewer)->get($url)->assertOk()->getContent();
                    foreach ($secrets as $secret) {
                        $this->assertStringNotContainsString($secret, $html, "{$secret} leaked on {$url} at {$at}");
                    }
                }
            }
        }

        // The picker still sees that a pick exists for the month, without the category.
        $select = $this->actingInTenantAs($this->director)->get('/app/awards')->assertOk()->getContent();
        $this->assertStringContainsString('data-mystery-picked="'.self::SEPTEMBER.'"', $select);

        // Reveal, then it is everywhere it should be.
        Carbon::setTestNow('2026-10-01 08:00:00');
        Artisan::call('awards:publish');
        Carbon::setTestNow('2026-10-01 10:00:00');
        $this->actingInTenantAs($this->emysha)->get('/app/dash')->assertOk()->assertSee('Professional Tab Collector');
    }

    #[Test]
    public function test_acceptance_3_rules_picker_committee_no_back_to_back_not_counted_audited(): void
    {
        Carbon::setTestNow('2026-09-29 10:00:00');
        $pick = ['employee_id' => $this->adri->id, 'category' => 'Bug Whisperer', 'explanation' => 'Speaks softly to the flaky test and it passes.'];

        // Only the director or the committee picks; without a committee everyone else is 403.
        foreach ([$this->hr, $this->ahmad, $this->emysha] as $actor) {
            $this->actingInTenantAs($actor)->post('/app/awards/mystery', $pick)->assertStatus(403);
        }
        $this->assertSame(0, DB::table('mystery_awards')->count());

        // Committee: director only, exactly three.
        $trio = ['employee_ids' => [$this->ahmad->id, $this->emysha->id, $this->hr->id]];
        $this->actingInTenantAs($this->ahmad)->post('/app/awards/mystery/committee', $trio)->assertStatus(403);
        $this->actingInTenantAs($this->director)->post('/app/awards/mystery/committee', ['employee_ids' => [$this->ahmad->id, $this->emysha->id]])->assertSessionHasErrors('employee_ids');
        $this->actingInTenantAs($this->director)->post('/app/awards/mystery/committee', ['employee_ids' => [$this->ahmad->id, $this->emysha->id, $this->emysha->id]])->assertSessionHasErrors('employee_ids');
        $this->actingInTenantAs($this->director)->post('/app/awards/mystery/committee', $trio)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertEqualsCanonicalizing(
            [$this->ahmad->id, $this->emysha->id, $this->hr->id],
            DB::table('mystery_committee')->whereDate('month', self::SEPTEMBER)->pluck('employee_id')->map(fn ($id) => (int) $id)->all()
        );
        $this->assertSame(1, AuditLog::where('action', 'award.mystery_committee')->where('target', 'mystery_committee:'.self::SEPTEMBER)->count());

        // A committee member can pick; a non-member still cannot.
        $this->actingInTenantAs($this->nurin)->post('/app/awards/mystery', $pick)->assertStatus(403);
        $this->actingInTenantAs($this->director)->post('/app/awards/mystery', ['employee_id' => $this->adri->id, 'category' => '', 'explanation' => ''])->assertSessionHasErrors(['category', 'explanation']);
        $this->actingInTenantAs($this->emysha)->post('/app/awards/mystery', $pick)->assertRedirect()->assertSessionHasNoErrors();
        $row = DB::table('mystery_awards')->sole();
        $this->assertSame($this->emysha->id, (int) $row->picked_by);
        $audit = AuditLog::where('action', 'award.mystery_picked')->sole();
        $this->assertSame("mystery:{$row->id}", $audit->target);
        $this->assertSame($this->emysha->user_id, $audit->user_id);
        $this->assertFalse(in_array('rubric', array_keys((array) $row), true), 'no rubric on a mystery award');

        // A second pick for the same month replaces the first, one row per month.
        $this->actingInTenantAs($this->director)->post('/app/awards/mystery', ['employee_id' => $this->nurin->id, 'category' => 'Calm in the Chaos', 'explanation' => 'Three incidents, zero raised voices.'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(1, DB::table('mystery_awards')->count());
        $this->assertSame($this->nurin->id, (int) DB::table('mystery_awards')->sole()->employee_id);

        // Same person cannot win two months in a row: Nurin won September, October refuses her.
        Carbon::setTestNow('2026-10-01 08:00:00');
        Artisan::call('awards:publish');
        Carbon::setTestNow('2026-10-27 10:00:00');
        $this->actingInTenantAs($this->director)->post('/app/awards/mystery', ['employee_id' => $this->nurin->id, 'category' => 'Meeting Survivor of the Month', 'explanation' => 'Eleven back-to-back calls, still smiling.'])->assertSessionHasErrors('employee_id');
        $this->assertSame(1, DB::table('mystery_awards')->count());
        $this->actingInTenantAs($this->director)->post('/app/awards/mystery', ['employee_id' => $this->adri->id, 'category' => 'Meeting Survivor of the Month', 'explanation' => 'Eleven back-to-back calls, still smiling.'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('2026-10-01', substr((string) DB::table('mystery_awards')->where('employee_id', $this->adri->id)->sole()->month, 0, 10));

        // Never counted: no award_results row, no badge, no Hall of Fame even after three wins.
        $this->assertSame(0, DB::table('award_results')->where('award_key', 'mystery')->count());
        foreach (['2026-05-01', '2026-07-01'] as $month) {
            DB::table('mystery_awards')->insert([
                'tenant_id' => $this->tenant()->id, 'month' => $month, 'employee_id' => $this->nurin->id,
                'category' => 'Client Translator', 'explanation' => 'Turned "make it pop" into a ticket.',
                'picked_by' => $this->director->id, 'published_at' => Carbon::parse($month)->addMonth()->setTime(8, 0),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        Carbon::setTestNow('2026-11-02 10:00:00');
        Artisan::call('awards:publish');
        foreach (['/app/profile?emp='.$this->nurin->id, '/app/profile?emp='.$this->adri->id] as $url) {
            $this->actingInTenantAs($this->director)->get($url)->assertOk()
                ->assertDontSee('data-award-badge="mystery"', false)
                ->assertDontSee('data-hall-of-fame=', false);
        }
        $this->actingInTenantAs($this->nurin)->get('/app/profile')->assertOk()->assertDontSee('data-award-badge=', false);
        $this->assertSame(0, DB::table('award_results')->where('award_key', 'mystery')->count());
    }

    #[Test]
    public function test_acceptance_4_keep_it_plain_keeps_the_reveal_text_without_the_show(): void
    {
        $this->regularAward(self::SEPTEMBER, 'done_and_dusted', $this->emysha);
        DB::table('mystery_awards')->insert([
            'tenant_id' => $this->tenant()->id, 'month' => self::SEPTEMBER, 'employee_id' => $this->adri->id,
            'category' => 'PowerPoint Has Left the Chat', 'explanation' => 'Ran the whole review from a whiteboard photo.',
            'picked_by' => $this->director->id, 'published_at' => '2026-10-01 08:00:00',
            'created_at' => '2026-09-29 10:00:00', 'updated_at' => '2026-09-29 10:00:00',
        ]);

        Carbon::setTestNow('2026-10-01 10:00:00');
        $this->actingInTenantAs($this->adri)->postJson('/app/dashboard/prefs', ['plain' => true])->assertOk();
        $page = $this->actingInTenantAs($this->adri)->get('/app/dash')->assertOk();
        $html = $page->getContent();
        $page->assertSee('data-band="awards"', false)->assertSee('data-plain', false)
            ->assertDontSee('uj-db-art', false)->assertDontSee('<canvas', false)->assertDontSee('<audio', false);
        $slide = $this->slide($html, 'mystery');
        $this->assertStringContainsString('PowerPoint Has Left the Chat', $slide);
        $this->assertStringContainsString('Ran the whole review from a whiteboard photo.', $slide);
        $this->assertStringContainsString('data-winner="'.$this->adri->id.'"', $slide);
    }

    public function test_always_checks(): void
    {
        $this->assertDueDateLocked();
        $this->assertAuditLogImmutable();
        $this->assertDashboardUnchanged();
        $this->assertKeepItPlainHonoured();
    }

    // ── helpers ──

    /** A published CR-14 result so the awards band opens for the month. */
    private function regularAward(string $month, string $awardKey, Employee $winner): void
    {
        DB::table('award_results')->insert([
            'tenant_id' => $this->tenant()->id, 'month' => $month, 'award_key' => $awardKey,
            'employee_id' => $winner->id, 'value' => 12, 'label' => '12 cards finished this month', 'source' => 'auto',
            'reason' => null, 'published_at' => '2026-10-01 08:00:00', 'created_at' => '2026-10-01 08:00:00', 'updated_at' => '2026-10-01 08:00:00',
        ]);
    }

    private function slide(string $html, string $key): string
    {
        return $this->section($html, 'data-slide="'.$key.'"', 'data-slide="');
    }

    private function award(string $html, string $key): string
    {
        return $this->section($html, 'data-award="'.$key.'"', 'data-award="');
    }

    private function section(string $html, string $start, string $nextMarker): string
    {
        $from = strpos($html, $start);
        $this->assertNotFalse($from, "{$start} is not on the page");
        $to = strpos($html, $nextMarker, $from + strlen($start));

        return $to === false ? substr($html, $from) : substr($html, $from, $to - $from);
    }
}
