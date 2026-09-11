<?php

namespace Tests\Acceptance;

use App\Models\Employee;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Acceptance for docs/specs/CR-28.md (session S23, Victory Bell). The spec's Acceptance
 * sentence is items 1 to 3 in the order it lists them; the Rules paragraph is item 4;
 * Keep it plain (culture-pack preamble) is item 5.
 *
 * Shapes this file fixes for the generator (recorded in docs/build/OPEN.md):
 * - `work_items.is_milestone` (bool, default false). Set with the existing card editor
 *   `PATCH /app/board/{workItem}` `{is_milestone: 1|0}` by `manager`, `management` or
 *   `director` only; an `employee` sending `is_milestone` gets 403 even on their own
 *   card. Track WBS milestones are not in scope (no Track port call).
 * - moving a milestone card to Done with `POST /app/board/{workItem}/move` `{status: done}`
 *   (JSON) answers with a `bell` object: `{work_item_id, prompt}` where prompt is
 *   "Ring the bell?"; a non-milestone card answers `bell: null`; a milestone card already
 *   rung answers `bell: null`.
 * - ringing is `POST /app/board/{workItem}/bell` `{line?}` (optional line, max 160) by the
 *   card's owner (`employee_id`) or by `manager`/`management`/`director`; anyone else 403.
 *   422 when the card is not a milestone, when it is not Done, when it was already rung,
 *   or when the card's project already has 3 bells in the calendar month of `now()`.
 *   Writes `victory_bells` (tenant_id, work_item_id, project_id nullable, rung_by employee
 *   id, line nullable, rung_at, timestamps) and audit `victory_bell.rung`, target
 *   `victory_bell:<id>`.
 * - the celebration is one more moment in the moments band (contracts/dashboard-slots.md):
 *   `<section class="uj-db-band uj-db-moment" data-kind="victory-bell" data-victory-bell="<id>">`,
 *   kicker "WE HAVE MOVEMENT", the text "<card title> is officially Done.", the optional
 *   line, one `[data-victory-bell-member="<employee id>"]` avatar for the owner and each
 *   tagged participant, a CR-30 picker (`data-reaction-pick`) and tallies
 *   (`data-reaction-count`). Shown on every dashboard in the tenant while
 *   `now < rung_at + 24 hours`; other tenants never see it.
 * - reactions: `POST /app/victory-bells/{bell}/react` `{reaction}` with CR-30 semantics
 *   (unknown key 422, same key again removes, a different key replaces, one per person);
 *   table `victory_bell_reactions` (victory_bell_id, employee_id, reaction).
 * - after 24 hours the bell leaves the dashboard and is listed on the Wins page,
 *   `GET /app/wins`, as `[data-win-bell="<id>"]` with the card title.
 * - Keep it plain: the section still renders, text only: no `uj-db-confetti`, no
 *   `uj-db-art`, no `<canvas`, no `<audio`.
 */
class CR28Test extends TestCase
{
    use AlwaysChecks;
    use RefreshDatabase;

    private Employee $kussairi;

    private Employee $shazwan;

    private Employee $yati;

    private Project $mystods;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-09 10:00:00');

        $this->kussairi = $this->person('Kussairi PM', 'manager');
        $this->shazwan = $this->person('Shazwan Dev');
        $this->yati = $this->person('Yati Dev');
        $this->mystods = Project::create([
            'tenant_id' => $this->tenant()->id, 'code' => 'MYSTODS', 'project_code' => 'MYSTODS-2026-01',
            'name' => 'MySToDS', 'client' => 'Jabatan', 'status' => 'active',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    #[Test]
    public function test_acceptance_1_moving_a_milestone_card_to_done_prompts_ring_the_bell(): void
    {
        $card = $this->milestoneCard('MySToDS Release 4');

        $this->assertDatabaseHas('work_items', ['id' => $card->id, 'is_milestone' => 1]);

        $move = $this->actingInTenantAs($this->shazwan)
            ->postJson("/app/board/{$card->id}/move", ['status' => 'done'])
            ->assertOk();
        $move->assertJsonPath('status', 'done');
        $move->assertJsonPath('bell.work_item_id', $card->id);
        $move->assertJsonPath('bell.prompt', 'Ring the bell?');

        // Nothing rung yet: the prompt is an offer, not a bell.
        $this->assertSame(0, DB::table('victory_bells')->count());
        $this->actingInTenantAs($this->yati)->get('/app/dash')->assertOk()->assertDontSee('data-kind="victory-bell"', false);
    }

    #[Test]
    public function test_acceptance_2_ringing_puts_the_celebration_with_team_avatars_on_every_dashboard(): void
    {
        $card = $this->milestoneCard('MySToDS Release 4');
        $card->participants()->attach($this->yati->id, ['role' => 'helper']);
        $this->done($card);

        $this->actingInTenantAs($this->shazwan)
            ->postJson("/app/board/{$card->id}/bell", ['line' => 'Six months. One release. Zero rollbacks.'])
            ->assertOk();

        $bell = DB::table('victory_bells')->first();
        $this->assertNotNull($bell, 'victory_bells row missing');
        $this->assertSame($card->id, (int) $bell->work_item_id);
        $this->assertSame($this->mystods->id, (int) $bell->project_id);
        $this->assertSame($this->shazwan->id, (int) $bell->rung_by);
        $this->assertSame('Six months. One release. Zero rollbacks.', $bell->line);
        $this->assertNotNull($bell->rung_at);
        $this->assertDatabaseHas('audit_logs', [
            'tenant_id' => $this->tenant()->id,
            'action' => 'victory_bell.rung',
            'target' => "victory_bell:{$bell->id}",
            'user_id' => $this->shazwan->user_id,
        ]);

        // Everyone in the tenant sees it, owner or not, above the first regular card.
        foreach ([$this->shazwan, $this->yati, $this->kussairi] as $viewer) {
            $page = $this->actingInTenantAs($viewer)->get('/app/dash')->assertOk();
            $page->assertSee('data-kind="victory-bell"', false);
            $page->assertSee('data-victory-bell="'.$bell->id.'"', false);
            $page->assertSee('WE HAVE MOVEMENT');
            $page->assertSee('MySToDS Release 4 is officially Done.');
            $page->assertSee('Six months. One release. Zero rollbacks.');
            $page->assertSee('data-victory-bell-member="'.$this->shazwan->id.'"', false);
            $page->assertSee('data-victory-bell-member="'.$this->yati->id.'"', false);
            $page->assertSeeInOrder(['data-kind="victory-bell"', 'Current month summary'], false);
        }
        $html = $this->bannerHtml($this->actingInTenantAs($this->yati)->get('/app/dash'), $bell->id);
        $this->assertStringContainsString('uj-db-confetti', $html);
        $this->assertStringContainsString('data-reaction-pick="respect"', $html);

        // Reactions with CR-30 semantics.
        $this->actingInTenantAs($this->yati)->postJson("/app/victory-bells/{$bell->id}/react", ['reaction' => 'party'])->assertStatus(422);
        $this->actingInTenantAs($this->yati)->postJson("/app/victory-bells/{$bell->id}/react", ['reaction' => 'respect'])->assertOk();
        $this->actingInTenantAs($this->kussairi)->postJson("/app/victory-bells/{$bell->id}/react", ['reaction' => 'respect'])->assertOk();
        $this->assertSame(2, DB::table('victory_bell_reactions')->where('victory_bell_id', $bell->id)->where('reaction', 'respect')->count());
        $html = $this->bannerHtml($this->actingInTenantAs($this->shazwan)->get('/app/dash'), $bell->id);
        $this->assertMatchesRegularExpression('/data-reaction-count="respect"[^>]*>[^<]*2/', $html);

        $this->actingInTenantAs($this->yati)->postJson("/app/victory-bells/{$bell->id}/react", ['reaction' => 'respect'])->assertOk();
        $this->assertSame(1, DB::table('victory_bell_reactions')->where('victory_bell_id', $bell->id)->count());
        $this->actingInTenantAs($this->kussairi)->postJson("/app/victory-bells/{$bell->id}/react", ['reaction' => 'legend'])->assertOk();
        $this->assertSame(['legend'], DB::table('victory_bell_reactions')->where('victory_bell_id', $bell->id)->pluck('reaction')->all());

        // Another tenant sees nothing.
        $other = Tenant::create(['slug' => 'other', 'name' => 'Other', 'initials' => 'OT']);
        $outsider = User::create(['name' => 'Outsider', 'email' => 'outsider@example.com', 'password' => bcrypt('password')]);
        $outsider->tenants()->attach($other->id, ['role' => 'employee']);
        Employee::create(['tenant_id' => $other->id, 'user_id' => $outsider->id, 'name' => 'Outsider', 'status' => 'active', 'workload' => 'green']);
        $this->actingAs($outsider)->withSession(['current_tenant' => $other->id])
            ->get('/app/dash')->assertOk()->assertDontSee('data-kind="victory-bell"', false);
    }

    #[Test]
    public function test_acceptance_3_a_non_milestone_card_shows_no_prompt_and_cannot_ring(): void
    {
        $card = $this->card($this->shazwan, ['title' => 'Fix the footer', 'project_id' => $this->mystods->id]);

        $move = $this->actingInTenantAs($this->shazwan)
            ->postJson("/app/board/{$card->id}/move", ['status' => 'done'])
            ->assertOk();
        $move->assertJsonPath('status', 'done');
        $move->assertJsonPath('bell', null);

        $this->actingInTenantAs($this->shazwan)
            ->postJson("/app/board/{$card->id}/bell", [])
            ->assertStatus(422);
        $this->assertSame(0, DB::table('victory_bells')->count());
        $this->actingInTenantAs($this->yati)->get('/app/dash')->assertOk()->assertDontSee('data-kind="victory-bell"', false);
    }

    #[Test]
    public function test_acceptance_4_rules_pm_flags_owner_or_pm_rings_three_per_project_per_month_24_hours_then_wins(): void
    {
        // Only PM and above flag a milestone; the owner cannot flag their own card.
        $card = $this->card($this->shazwan, ['title' => 'MySToDS Release 4', 'project_id' => $this->mystods->id]);
        $this->actingInTenantAs($this->shazwan)->patchJson("/app/board/{$card->id}", ['is_milestone' => 1])->assertStatus(403);
        $this->assertDatabaseHas('work_items', ['id' => $card->id, 'is_milestone' => 0]);
        $this->actingInTenantAs($this->kussairi)->patchJson("/app/board/{$card->id}", ['is_milestone' => 1])->assertOk();
        $this->assertDatabaseHas('work_items', ['id' => $card->id, 'is_milestone' => 1]);

        // Not Done yet: no bell.
        $this->actingInTenantAs($this->shazwan)->postJson("/app/board/{$card->id}/bell", [])->assertStatus(422);
        $this->done($card);

        // A colleague who is not the owner and not PM cannot ring it.
        $this->actingInTenantAs($this->yati)->postJson("/app/board/{$card->id}/bell", [])->assertStatus(403);
        $this->assertSame(0, DB::table('victory_bells')->count());

        // The PM can, without a line; the line is optional.
        $this->actingInTenantAs($this->kussairi)->postJson("/app/board/{$card->id}/bell", [])->assertOk();
        $this->assertSame(1, DB::table('victory_bells')->count());
        $this->assertNull(DB::table('victory_bells')->first()->line);

        // The same card cannot be rung twice, and the move answer no longer offers it.
        $this->actingInTenantAs($this->shazwan)->postJson("/app/board/{$card->id}/bell", [])->assertStatus(422);
        $this->actingInTenantAs($this->shazwan)->postJson("/app/board/{$card->id}/move", ['status' => 'review'])->assertOk();
        $this->actingInTenantAs($this->shazwan)->postJson("/app/board/{$card->id}/move", ['status' => 'done'])->assertOk()->assertJsonPath('bell', null);
        $this->assertSame(1, DB::table('victory_bells')->count());

        // Max 3 bells per project per month: two more ring, the fourth is refused.
        foreach (['Release 4.1', 'Release 4.2'] as $title) {
            $more = $this->milestoneCard($title);
            $this->done($more);
            $this->actingInTenantAs($this->shazwan)->postJson("/app/board/{$more->id}/bell", [])->assertOk();
        }
        $fourth = $this->milestoneCard('Release 4.3');
        $this->done($fourth);
        $this->actingInTenantAs($this->shazwan)->postJson("/app/board/{$fourth->id}/bell", [])->assertStatus(422);
        $this->assertSame(3, DB::table('victory_bells')->where('project_id', $this->mystods->id)->count());

        // A new month resets the count; a card with no project is not counted against any project.
        Carbon::setTestNow('2026-10-01 09:00:00');
        $this->actingInTenantAs($this->shazwan)->postJson("/app/board/{$fourth->id}/bell", [])->assertOk();
        $loose = $this->milestoneCard('Loose milestone', null);
        $this->done($loose);
        $this->actingInTenantAs($this->shazwan)->postJson("/app/board/{$loose->id}/bell", [])->assertOk();
        $this->assertSame(5, DB::table('victory_bells')->count());

        // 24 hours: shown at 23:59, gone at 24:01, then on the Wins page.
        $bell = DB::table('victory_bells')->where('work_item_id', $card->id)->first();
        Carbon::setTestNow('2026-09-10 09:59:00');
        $this->actingInTenantAs($this->yati)->get('/app/dash')->assertOk()->assertSee('data-victory-bell="'.$bell->id.'"', false);
        Carbon::setTestNow('2026-09-10 10:01:00');
        $this->actingInTenantAs($this->yati)->get('/app/dash')->assertOk()->assertDontSee('data-victory-bell="'.$bell->id.'"', false);

        $wins = $this->actingInTenantAs($this->yati)->get('/app/wins')->assertOk();
        $wins->assertSee('data-win-bell="'.$bell->id.'"', false);
        $wins->assertSee('MySToDS Release 4');
        $wins->assertSee('data-victory-bell-member="'.$this->shazwan->id.'"', false);

        Carbon::setTestNow('2026-11-10 10:00:00');
        $this->actingInTenantAs($this->shazwan)->get('/app/wins')->assertOk()->assertSee('data-win-bell="'.$bell->id.'"', false);
    }

    #[Test]
    public function test_acceptance_5_keep_it_plain_gives_a_text_only_celebration(): void
    {
        $card = $this->milestoneCard('MySToDS Release 4');
        $this->done($card);
        $this->actingInTenantAs($this->shazwan)->postJson("/app/board/{$card->id}/bell", ['line' => 'Zero rollbacks.'])->assertOk();
        $bell = DB::table('victory_bells')->first();

        $loud = $this->actingInTenantAs($this->yati)->get('/app/dash')->assertOk();
        $loudHtml = $this->bannerHtml($loud, $bell->id);
        $this->assertStringContainsString('uj-db-confetti', $loudHtml);

        $this->actingInTenantAs($this->yati)->postJson('/app/dashboard/prefs', ['plain' => true])->assertOk();

        $plain = $this->actingInTenantAs($this->yati)->get('/app/dash')->assertOk();
        $plain->assertSee('data-victory-bell="'.$bell->id.'"', false);
        $plain->assertSee('MySToDS Release 4 is officially Done.');
        $plain->assertSee('Zero rollbacks.');
        $plainHtml = $this->bannerHtml($plain, $bell->id);
        $this->assertStringNotContainsString('uj-db-confetti', $plainHtml);
        $this->assertStringNotContainsString('uj-db-art', $plainHtml);
        $this->assertStringNotContainsString('<canvas', $plainHtml);
        $this->assertStringNotContainsString('<audio', $plainHtml);
    }

    #[Test]
    public function test_always_checks_from_s23(): void
    {
        Carbon::setTestNow();
        $this->assertDueDateLocked();
        $this->assertAuditLogImmutable();
        $this->assertDashboardUnchanged();
        $this->assertKeepItPlainHonoured();
    }

    // ── helpers ──

    /** Shazwan's card on MySToDS (or no project), flagged Milestone by the PM. */
    private function milestoneCard(string $title, ?int $projectId = 0): WorkItem
    {
        $card = $this->card($this->shazwan, [
            'title' => $title,
            'project_id' => $projectId === 0 ? $this->mystods->id : $projectId,
        ]);
        $this->actingInTenantAs($this->kussairi)->patchJson("/app/board/{$card->id}", ['is_milestone' => 1])->assertOk();

        return $card->fresh();
    }

    private function done(WorkItem $card): void
    {
        $this->actingInTenantAs($this->shazwan)->postJson("/app/board/{$card->id}/move", ['status' => 'done'])->assertOk();
    }

    private function bannerHtml(TestResponse $page, int $bellId): string
    {
        $html = $page->getContent();
        $start = strpos($html, 'data-victory-bell="'.$bellId.'"');
        $this->assertNotFalse($start, "celebration for bell {$bellId} missing");
        $start = strrpos(substr($html, 0, $start), '<section');
        $end = strpos($html, '</section>', $start);

        return substr($html, $start, $end - $start);
    }
}
