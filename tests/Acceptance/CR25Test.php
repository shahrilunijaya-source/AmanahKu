<?php

namespace Tests\Acceptance;

use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Acceptance for docs/specs/CR-25.md (session S24, This Week's Plot Twist). The spec's
 * Acceptance sentence is items 1 to 4 in the order it lists them; the Rules paragraph
 * (question bank, who-questions with opt-out, social polls feeding CR-18) is item 5;
 * Keep it plain (culture-pack preamble) is item 6.
 *
 * Shapes this file fixes for the generator (recorded in docs/build/OPEN.md):
 * - screen `plot-twist` in The Playground, `GET /app/plot-twist`: this week's poll
 *   (`[data-poll="<id>"]`, the question, one `[data-poll-option="<option id>"]` per
 *   option, a vote form) or, once the viewer has voted, "You voted" with no choice shown.
 * - tables: `plot_twist_polls` (tenant_id, question, kind `fun`|`who`|`social`,
 *   named_employee_id nullable, status `draft`|`open`|`withdrawn`, opens_on date (a
 *   Monday), reveals_at datetime (that Friday 15:00), idea_fed_at nullable, created_by,
 *   timestamps); `plot_twist_options` (poll_id, label, sort_order);
 *   `plot_twist_votes` (poll_id, option_id, created_at) with NO employee_id, user_id or
 *   any identity column; `plot_twist_receipts` (poll_id, receipt hash) with NO option
 *   column; `plot_twist_questions` (tenant_id, text, kind, template bool, suggested_by
 *   employee id nullable, approved bool).
 * - HR/admin (`hr`, `director` only) publish a poll with `POST /app/plot-twist`
 *   `{question, kind, options[] (2..6), opens_on (Monday), named_employee_id?}` → 302,
 *   status `open`, `reveals_at` = Friday of that week 15:00. `employee`/`manager` → 403.
 *   Any signed-in person suggests with `POST /app/plot-twist/suggest {text, kind}` → a
 *   `plot_twist_questions` row with approved=0 (HR sees it in the bank; nobody else does).
 * - voting: `POST /app/plot-twist/{poll}/vote {option_id}` → 200 JSON `{ok:true}`;
 *   a second vote by the same person → 422; a vote before `opens_on` or after
 *   `reveals_at` → 422; an option of another poll → 422. Receipt = `hash_hmac('sha256',
 *   "<user id>:<poll id>", config('app.key'))`; no row anywhere links a person to an
 *   option.
 * - results: before `reveals_at` nobody (director included) can read counts: the
 *   screen shows no `[data-poll-result]`, and `GET /app/plot-twist/{poll}/results` is
 *   403 for everyone. From `reveals_at` on, the dashboard Notice board (`notices`
 *   widget, contracts/dashboard-slots.md) carries one row `[data-plot-twist="<id>"]`
 *   with the question and each option as "<label> <pct>%" (`[data-poll-result="<option
 *   id>"]` holding the integer percentage), and the screen shows the same. Percentages
 *   are of votes cast, rounded, summing to 100 by largest remainder; zero votes → "No
 *   votes this week." and no percentages.
 * - who-questions: `kind=who` needs a `named_employee_id` and the question text must
 *   come from the template bank (`plot_twist_questions` with template=1 and kind=who);
 *   free text for a who-question → 422. The named person can `POST
 *   /app/plot-twist/{poll}/opt-out` before `opens_on` → status `withdrawn`, poll never
 *   shown; anyone else → 403; after `opens_on` → 422.
 * - social polls (`kind=social`) feed CR-18: on the first results render after
 *   `reveals_at`, the winning option is written as a `work_item_comments` row ("Plot
 *   Twist result: <label> (<pct>%)") on the newest open card labelled `recurring` whose
 *   title contains "social activity", and `idea_fed_at` is set; rendered again it does
 *   not write twice; with no such card nothing is written and nothing fails.
 * - Keep it plain: the Notice board row and the screen keep the question and the
 *   percentages, with no `uj-pt-art`, no `<canvas`, no `<audio`, no cheeky kicker
 *   ("PLOT TWIST" becomes "Weekly poll").
 */
class CR25Test extends TestCase
{
    use AlwaysChecks;
    use RefreshDatabase;

    private Employee $hidayah;

    private Employee $shahril;

    private Employee $kussairi;

    private Employee $yati;

    private Employee $shazwan;

    protected function setUp(): void
    {
        parent::setUp();
        // Monday 7 Sep 2026, 09:00.
        Carbon::setTestNow('2026-09-07 09:00:00');

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
    public function test_acceptance_1_monday_poll_appears(): void
    {
        // Only HR/admin publish.
        $this->actingInTenantAs($this->kussairi)->post('/app/plot-twist', $this->payload())->assertStatus(403);
        $this->actingInTenantAs($this->yati)->post('/app/plot-twist', $this->payload())->assertStatus(403);
        $this->assertSame(0, DB::table('plot_twist_polls')->count());

        $poll = $this->publish();
        $this->assertSame('open', $poll->status);
        $this->assertSame('fun', $poll->kind);
        $this->assertSame('2026-09-07', substr((string) $poll->opens_on, 0, 10));
        $this->assertSame('2026-09-11 15:00:00', substr((string) $poll->reveals_at, 0, 19));
        $this->assertSame(4, DB::table('plot_twist_options')->where('poll_id', $poll->id)->count());

        foreach ([$this->yati, $this->shahril] as $viewer) {
            $page = $this->actingInTenantAs($viewer)->get('/app/plot-twist')->assertOk();
            $page->assertSee('data-poll="'.$poll->id.'"', false);
            $page->assertSee("Unijaya's unofficial national food?");
            foreach ($this->pollOptions($poll) as $option) {
                $page->assertSee('data-poll-option="'.$option->id.'"', false);
                $page->assertSee($option->label);
            }
            $page->assertDontSee('data-poll-result', false);
        }

        // Fewer than two options or more than six is refused; a non-Monday open date is refused.
        $this->actingInTenantAs($this->hidayah)->post('/app/plot-twist', $this->payload(['options' => ['Nasi lemak']]))->assertSessionHasErrors('options');
        $this->actingInTenantAs($this->hidayah)->post('/app/plot-twist', $this->payload(['opens_on' => '2026-09-08']))->assertSessionHasErrors('opens_on');
    }

    #[Test]
    public function test_acceptance_2_yati_votes_and_cannot_vote_twice(): void
    {
        $poll = $this->publish();
        [$nasi, $roti] = $this->pollOptions($poll);

        $this->actingInTenantAs($this->yati)
            ->postJson("/app/plot-twist/{$poll->id}/vote", ['option_id' => $nasi->id])
            ->assertOk()->assertJsonPath('ok', true);

        $this->assertSame(1, DB::table('plot_twist_votes')->where('poll_id', $poll->id)->where('option_id', $nasi->id)->count());
        $this->assertSame(1, DB::table('plot_twist_receipts')->where('poll_id', $poll->id)->count());
        $receipt = DB::table('plot_twist_receipts')->where('poll_id', $poll->id)->value('receipt');
        $this->assertSame(hash_hmac('sha256', "{$this->yati->user_id}:{$poll->id}", config('app.key')), $receipt);

        // Second vote, same or different option: refused, nothing changes.
        $this->actingInTenantAs($this->yati)->postJson("/app/plot-twist/{$poll->id}/vote", ['option_id' => $roti->id])->assertStatus(422);
        $this->actingInTenantAs($this->yati)->postJson("/app/plot-twist/{$poll->id}/vote", ['option_id' => $nasi->id])->assertStatus(422);
        $this->assertSame(1, DB::table('plot_twist_votes')->where('poll_id', $poll->id)->count());
        $this->assertSame(1, DB::table('plot_twist_receipts')->where('poll_id', $poll->id)->count());

        // The screen now says she voted, without saying what.
        $page = $this->actingInTenantAs($this->yati)->get('/app/plot-twist')->assertOk();
        $page->assertSee('You voted');
        $page->assertDontSee('data-poll-option="'.$nasi->id.'"', false);

        // Someone else still can; an option from another poll cannot; outside the window cannot.
        $this->actingInTenantAs($this->shazwan)->postJson("/app/plot-twist/{$poll->id}/vote", ['option_id' => $roti->id])->assertOk();
        $other = $this->publish(['question' => 'Which project deserves its own Netflix documentary?', 'opens_on' => '2026-09-14']);
        $foreign = $this->pollOptions($other)[0];
        $this->actingInTenantAs($this->kussairi)->postJson("/app/plot-twist/{$poll->id}/vote", ['option_id' => $foreign->id])->assertStatus(422);
        $this->actingInTenantAs($this->kussairi)->postJson("/app/plot-twist/{$other->id}/vote", ['option_id' => $foreign->id])->assertStatus(422);
        Carbon::setTestNow('2026-09-11 15:00:01');
        $this->actingInTenantAs($this->kussairi)->postJson("/app/plot-twist/{$poll->id}/vote", ['option_id' => $roti->id])->assertStatus(422);
        $this->assertSame(2, DB::table('plot_twist_votes')->where('poll_id', $poll->id)->count());
    }

    #[Test]
    public function test_acceptance_3_friday_3pm_results_shown_as_percentages(): void
    {
        $poll = $this->publish();
        [$nasi, $roti, $laksa] = $this->pollOptions($poll);
        $this->vote($this->yati, $poll, $nasi);
        $this->vote($this->shazwan, $poll, $nasi);
        $this->vote($this->kussairi, $poll, $roti);

        // Friday 14:59: nothing yet, not on the dashboard, not on the screen, not by URL.
        Carbon::setTestNow('2026-09-11 14:59:00');
        $this->actingInTenantAs($this->yati)->get('/app/dash')->assertOk()->assertDontSee('data-plot-twist="'.$poll->id.'"', false);
        $this->actingInTenantAs($this->shahril)->get('/app/plot-twist')->assertOk()->assertDontSee('data-poll-result', false);
        $this->actingInTenantAs($this->shahril)->getJson("/app/plot-twist/{$poll->id}/results")->assertStatus(403);

        // Friday 15:00: Notice board row with percentages, for everyone.
        Carbon::setTestNow('2026-09-11 15:00:00');
        foreach ([$this->yati, $this->shahril, $this->hidayah] as $viewer) {
            $dash = $this->actingInTenantAs($viewer)->get('/app/dash')->assertOk();
            $dash->assertSee('data-plot-twist="'.$poll->id.'"', false);
            $dash->assertSee("Unijaya's unofficial national food?");
            $this->assertMatchesRegularExpression('/data-poll-result="'.$nasi->id.'"[^>]*>[^<]*67/', $dash->getContent());
            $this->assertMatchesRegularExpression('/data-poll-result="'.$roti->id.'"[^>]*>[^<]*33/', $dash->getContent());
            $this->assertMatchesRegularExpression('/data-poll-result="'.$laksa->id.'"[^>]*>[^<]*0\b/', $dash->getContent());
            $dash->assertSeeInOrder(['Notice board', 'data-plot-twist="'.$poll->id.'"'], false);
        }
        $screen = $this->actingInTenantAs($this->yati)->get('/app/plot-twist')->assertOk();
        $this->assertMatchesRegularExpression('/data-poll-result="'.$nasi->id.'"[^>]*>[^<]*67/', $screen->getContent());

        // Still there on Monday morning before the next poll; gone from the Notice board once a new week's poll is open.
        Carbon::setTestNow('2026-09-14 08:00:00');
        $this->actingInTenantAs($this->yati)->get('/app/dash')->assertOk()->assertSee('data-plot-twist="'.$poll->id.'"', false);

        // A poll nobody voted on says so, no percentages.
        $quiet = $this->publish(['question' => 'Which project deserves its own Netflix documentary?', 'opens_on' => '2026-09-14']);
        Carbon::setTestNow('2026-09-18 15:00:00');
        $dash = $this->actingInTenantAs($this->yati)->get('/app/dash')->assertOk();
        $dash->assertSee('data-plot-twist="'.$quiet->id.'"', false);
        $dash->assertSee('No votes this week.');
        $dash->assertDontSee('data-poll-result="'.$this->pollOptions($quiet)[0]->id.'"', false);
    }

    #[Test]
    public function test_acceptance_4_no_one_including_director_can_see_who_voted_what(): void
    {
        $poll = $this->publish();
        [$nasi] = $this->pollOptions($poll);
        $this->vote($this->yati, $poll, $nasi);

        // Schema: the vote row carries no identity; the receipt carries no choice.
        foreach (['employee_id', 'user_id', 'voter_id', 'created_by', 'ip', 'session_id'] as $col) {
            $this->assertFalse(Schema::hasColumn('plot_twist_votes', $col), "plot_twist_votes must not have {$col}");
        }
        foreach (['option_id', 'choice', 'label', 'employee_id', 'user_id'] as $col) {
            $this->assertFalse(Schema::hasColumn('plot_twist_receipts', $col), "plot_twist_receipts must not have {$col}");
        }
        $vote = (array) DB::table('plot_twist_votes')->first();
        $this->assertStringNotContainsString((string) $this->yati->id, json_encode(array_diff_key($vote, ['id' => 1, 'poll_id' => 1, 'option_id' => 1, 'created_at' => 1])));

        // The receipt is a one-way hash: not the raw user id, not reversible without the key.
        $receipt = DB::table('plot_twist_receipts')->value('receipt');
        $this->assertNotSame((string) $this->yati->user_id, $receipt);
        $this->assertStringNotContainsString((string) $this->yati->user_id, $receipt);
        $this->assertSame(64, strlen($receipt));

        // Nothing rendered for a director before or after reveal names a voter.
        Carbon::setTestNow('2026-09-11 15:00:00');
        foreach (['/app/dash', '/app/plot-twist', "/app/plot-twist/{$poll->id}/results"] as $url) {
            $body = $this->actingInTenantAs($this->shahril)->get($url)->assertOk()->getContent();
            $this->assertStringNotContainsString('Yati Dev', $this->stripChrome($body), "{$url} names a voter");
        }
        $this->actingInTenantAs($this->shahril)->getJson("/app/plot-twist/{$poll->id}/results")->assertOk()
            ->assertJsonMissingPath('voters')->assertJsonMissingPath('votes.0.employee_id');

        // No audit row ties a person to a choice either.
        $this->assertSame(0, DB::table('audit_logs')->where('target', 'like', 'plot_twist_vote%')->count());
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'like', '%vote%')->where('user_id', $this->yati->user_id)->count());
    }

    #[Test]
    public function test_acceptance_5_rules_question_bank_who_templates_opt_out_and_social_ideas_feed_cr18(): void
    {
        // Anyone can suggest; suggestions land unapproved in the bank, visible to HR only.
        $this->actingInTenantAs($this->shazwan)
            ->post('/app/plot-twist/suggest', ['text' => 'Best mamak within 5 km of the office?', 'kind' => 'fun'])
            ->assertSessionHasNoErrors();
        $this->assertDatabaseHas('plot_twist_questions', ['text' => 'Best mamak within 5 km of the office?', 'kind' => 'fun', 'approved' => 0, 'suggested_by' => $this->shazwan->id]);
        $this->actingInTenantAs($this->hidayah)->get('/app/plot-twist')->assertOk()->assertSee('Best mamak within 5 km of the office?');
        $this->actingInTenantAs($this->kussairi)->get('/app/plot-twist')->assertOk()->assertDontSee('Best mamak within 5 km of the office?');

        // A who-question must come from the positive template bank and name someone.
        $this->actingInTenantAs($this->hidayah)->post('/app/plot-twist', $this->payload([
            'kind' => 'who', 'question' => 'Who is the slowest coder?', 'named_employee_id' => $this->shazwan->id,
            'options' => ['Shazwan', 'Yati'],
        ]))->assertSessionHasErrors('question');
        $this->actingInTenantAs($this->hidayah)->post('/app/plot-twist', $this->payload([
            'kind' => 'who', 'question' => 'Who is most likely to reply "Noted" within seven seconds?',
            'options' => ['Shazwan', 'Yati'],
        ]))->assertSessionHasErrors('named_employee_id');
        DB::table('plot_twist_questions')->insert([
            'tenant_id' => $this->tenant()->id, 'text' => 'Who is most likely to reply "Noted" within seven seconds?',
            'kind' => 'who', 'template' => 1, 'approved' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->actingInTenantAs($this->hidayah)->post('/app/plot-twist', $this->payload([
            'kind' => 'who', 'question' => 'Who is most likely to reply "Noted" within seven seconds?',
            'named_employee_id' => $this->shazwan->id, 'options' => ['Shazwan', 'Yati'], 'opens_on' => '2026-09-14',
        ]))->assertSessionHasNoErrors();
        $who = DB::table('plot_twist_polls')->where('kind', 'who')->first();
        $this->assertSame($this->shazwan->id, (int) $who->named_employee_id);

        // The named person can opt out before Monday; nobody else can; after Monday it is too late.
        $this->actingInTenantAs($this->yati)->post("/app/plot-twist/{$who->id}/opt-out")->assertStatus(403);
        $this->actingInTenantAs($this->shahril)->post("/app/plot-twist/{$who->id}/opt-out")->assertStatus(403);
        $this->actingInTenantAs($this->shazwan)->post("/app/plot-twist/{$who->id}/opt-out")->assertSessionHasNoErrors();
        $this->assertDatabaseHas('plot_twist_polls', ['id' => $who->id, 'status' => 'withdrawn']);
        Carbon::setTestNow('2026-09-14 09:00:00');
        $this->actingInTenantAs($this->yati)->get('/app/plot-twist')->assertOk()->assertDontSee('data-poll="'.$who->id.'"', false);
        $late = $this->publish(['kind' => 'who', 'question' => 'Who is most likely to reply "Noted" within seven seconds?', 'named_employee_id' => $this->shazwan->id, 'options' => ['Shazwan', 'Yati'], 'opens_on' => '2026-09-14']);
        $this->actingInTenantAs($this->shazwan)->post("/app/plot-twist/{$late->id}/opt-out")->assertStatus(422);

        // A social poll's winner feeds the CR-18 social-activity card once, as a comment.
        Carbon::setTestNow('2026-09-21 09:00:00');
        $social = $this->publish(['kind' => 'social', 'question' => 'What should the next social activity be?', 'options' => ['Bowling', 'Escape room', 'Hiking'], 'opens_on' => '2026-09-21']);
        [$bowling, $escape] = $this->pollOptions($social);
        $this->vote($this->yati, $social, $escape);
        $this->vote($this->shazwan, $social, $escape);
        $this->vote($this->kussairi, $social, $bowling);
        $card = $this->card($this->kussairi, ['title' => 'Organise company social activity', 'labels' => ['recurring'], 'status' => 'todo']);
        $this->card($this->kussairi, ['title' => 'Organise company social activity', 'labels' => ['recurring'], 'status' => 'done']);

        Carbon::setTestNow('2026-09-25 14:59:00');
        $this->actingInTenantAs($this->yati)->get('/app/dash')->assertOk();
        $this->assertSame(0, DB::table('work_item_comments')->where('work_item_id', $card->id)->count());

        Carbon::setTestNow('2026-09-25 15:00:00');
        $this->actingInTenantAs($this->yati)->get('/app/dash')->assertOk();
        $this->actingInTenantAs($this->shazwan)->get('/app/dash')->assertOk();
        $this->actingInTenantAs($this->kussairi)->get('/app/plot-twist')->assertOk();
        $comments = DB::table('work_item_comments')->where('work_item_id', $card->id)->get();
        $this->assertCount(1, $comments);
        $this->assertSame('Plot Twist result: Escape room (67%)', $comments[0]->body);
        $this->assertNotNull(DB::table('plot_twist_polls')->where('id', $social->id)->value('idea_fed_at'));
        $this->assertSame(0, DB::table('work_item_comments')->whereNot('work_item_id', $card->id)->count());
    }

    #[Test]
    public function test_acceptance_6_keep_it_plain_keeps_the_numbers_and_drops_the_fun(): void
    {
        $poll = $this->publish();
        [$nasi] = $this->pollOptions($poll);
        $this->vote($this->yati, $poll, $nasi);
        Carbon::setTestNow('2026-09-11 15:00:00');

        $loud = $this->actingInTenantAs($this->shazwan)->get('/app/dash')->assertOk();
        $loud->assertSee('data-plot-twist="'.$poll->id.'"', false);
        $loud->assertSee('PLOT TWIST');

        $this->actingInTenantAs($this->shazwan)->postJson('/app/dashboard/prefs', ['plain' => true])->assertOk();

        $plain = $this->actingInTenantAs($this->shazwan)->get('/app/dash')->assertOk();
        $plain->assertSee('data-plot-twist="'.$poll->id.'"', false);
        $plain->assertSee("Unijaya's unofficial national food?");
        $this->assertMatchesRegularExpression('/data-poll-result="'.$nasi->id.'"[^>]*>[^<]*100/', $plain->getContent());
        $row = $this->rowHtml($plain->getContent(), $poll->id);
        $this->assertStringNotContainsString('PLOT TWIST', $row);
        $this->assertStringContainsString('Weekly poll', $row);
        $this->assertStringNotContainsString('uj-pt-art', $row);
        $this->assertStringNotContainsString('<canvas', $row);
        $this->assertStringNotContainsString('<audio', $row);

        $screen = $this->actingInTenantAs($this->shazwan)->get('/app/plot-twist')->assertOk();
        $this->assertStringNotContainsString('uj-pt-art', $screen->getContent());
    }

    #[Test]
    public function test_always_checks_from_s24(): void
    {
        Carbon::setTestNow();
        $this->assertDueDateLocked();
        $this->assertAuditLogImmutable();
        $this->assertDashboardUnchanged();
        $this->assertKeepItPlainHonoured();
    }

    // ── helpers ──

    /** @return array<string, mixed> */
    private function payload(array $over = []): array
    {
        return array_merge([
            'question' => "Unijaya's unofficial national food?",
            'kind' => 'fun',
            'options' => ['Nasi lemak', 'Roti canai', 'Laksa', 'Char kuey teow'],
            'opens_on' => '2026-09-07',
        ], $over);
    }

    private function publish(array $over = []): object
    {
        $this->actingInTenantAs($this->hidayah)->post('/app/plot-twist', $this->payload($over))->assertSessionHasNoErrors();
        $poll = DB::table('plot_twist_polls')->orderByDesc('id')->first();
        $this->assertNotNull($poll, 'plot_twist_polls row missing');

        return $poll;
    }

    /** @return list<object> */
    private function pollOptions(object $poll): array
    {
        return DB::table('plot_twist_options')->where('poll_id', $poll->id)->orderBy('sort_order')->orderBy('id')->get()->all();
    }

    private function vote(Employee $who, object $poll, object $option): void
    {
        $this->actingInTenantAs($who)->postJson("/app/plot-twist/{$poll->id}/vote", ['option_id' => $option->id])->assertOk();
    }

    /** The Notice board row for a poll, from the dashboard HTML. */
    private function rowHtml(string $html, int $pollId): string
    {
        $start = strpos($html, 'data-plot-twist="'.$pollId.'"');
        $this->assertNotFalse($start, "plot twist row for poll {$pollId} missing");
        $start = strrpos(substr($html, 0, $start), '<div');
        $depth = 0;
        $pos = $start;
        do {
            $open = strpos($html, '<div', $pos + 1);
            $close = strpos($html, '</div>', $pos + 1);
            if ($close === false) {
                break;
            }
            if ($open !== false && $open < $close) {
                $depth++;
                $pos = $open;
            } else {
                $pos = $close;
                if ($depth === 0) {
                    return substr($html, $start, $close + 6 - $start);
                }
                $depth--;
            }
        } while (true);

        return substr($html, $start);
    }

    /** Drop the sidebar/header (which legitimately shows the signed-in user's own name) so only the poll surface is inspected. */
    private function stripChrome(string $html): string
    {
        $main = strpos($html, '<main');

        return $main === false ? $html : substr($html, $main);
    }
}
