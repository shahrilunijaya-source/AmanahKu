<?php

namespace Tests\Acceptance;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\WorkItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Acceptance for docs/specs/CR-04.md (session S03, roles on a card), against
 * docs/build/contracts/roles.md.
 *
 * Shapes this file fixes for the generator (recorded in docs/build/OPEN.md):
 * - tagging is `PATCH /app/board/{id}` with `tagged` = list of `{employee_id, role}` where
 *   role is `helper` or `fyi`; it replaces the whole tagged set, like `participant_ids`
 *   does today. `participant_ids` keeps working and means `helper`.
 * - the Reviewer is `PATCH /app/board/{id}` with `reviewer_id` (nullable). Only "PM and
 *   above" (manager, hr, management tier) may set it; the card owner gets 403. A reviewer
 *   equal to the Assigned person is 422 on `reviewer_id`.
 * - every card face carries `data-role="assigned|helper|fyi|reviewer"` for the viewer, and
 *   the visible label text "Tagged – Helper", "Tagged – FYI" or "Reviewer" (Assigned has no
 *   label).
 * - the team board person row carries `data-helping` and `data-reviewing` next to the
 *   existing `data-open` / `data-overdue`, and shows the text "helping on N" / "reviewing N".
 * - the personal board has the filter chips Assigned / Tagged / Reviewing / All.
 */
class CR04Test extends TestCase
{
    use AlwaysChecks;
    use RefreshDatabase;

    private Employee $pm;

    private Employee $adri;

    private Employee $emysha;

    private Employee $yati;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(self::QUIET_DAY);

        $this->pm = $this->person('Kussairi PM', 'manager');
        $this->adri = $this->person('Adri', 'employee', ['reports_to_id' => $this->pm->id]);
        $this->emysha = $this->person('Emysha', 'employee', ['reports_to_id' => $this->pm->id]);
        $this->yati = $this->person('Yati', 'employee', ['reports_to_id' => $this->pm->id]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    #[Test]
    public function test_acceptance_1_helper_tag_shows_on_her_board_with_label_and_counts_as_helping_not_assigned(): void
    {
        // Emysha owns one open, overdue card of her own so the Assigned counters are non-zero.
        $this->card($this->emysha, ['title' => 'Emysha own card', 'due_at' => '2026-09-01']);
        $card = $this->card($this->adri, ['title' => 'Adri prototype POC', 'due_at' => '2026-09-01']);

        $before = $this->personRow($this->emysha);
        $this->assertSame(['open' => 1, 'overdue' => 1], ['open' => $before['open'], 'overdue' => $before['overdue']]);

        $this->actingInTenantAs($this->adri)
            ->patchJson("/app/board/{$card->id}", ['tagged' => [['employee_id' => $this->emysha->id, 'role' => 'helper']]])
            ->assertSuccessful();

        $this->assertDatabaseHas('work_item_participant', [
            'work_item_id' => $card->id, 'employee_id' => $this->emysha->id, 'role' => 'helper',
        ]);

        // Tagging is a state change: audited with the role.
        $audit = AuditLog::where('subject_id', $card->id)->where('field', 'participants')->latest('id')->first();
        $this->assertNotNull($audit, 'tagging not audited');
        $this->assertStringContainsString('helper', (string) $audit->new_value);

        // Her own board shows the card with the label and the role attribute.
        $board = $this->actingInTenantAs($this->emysha)->get('/app/board')->assertOk();
        $board->assertSee('Adri prototype POC');
        $board->assertSee('Tagged – Helper');
        $this->assertMatchesRegularExpression(
            '/data-id="'.$card->id.'"[^>]*data-role="helper"|data-role="helper"[^>]*data-id="'.$card->id.'"/',
            $board->getContent(),
            'the tagged card must carry data-role="helper" for Emysha'
        );

        // Team board: Assigned counters unchanged, helping on 1.
        $after = $this->personRow($this->emysha);
        $this->assertSame(1, $after['open'], 'open counter must count Assigned only');
        $this->assertSame(1, $after['overdue'], 'overdue counter must count Assigned only');
        $this->assertSame(1, $after['helping'], 'helping counter must show the helper tag');
        $this->assertSame(0, $after['reviewing']);
        $this->actingInTenantAs($this->pm)->get('/app/team-board')->assertOk()->assertSee('helping on 1');
    }

    #[Test]
    public function test_acceptance_2_fyi_tag_shows_with_label_and_gives_no_credit_and_no_counter(): void
    {
        $this->card($this->emysha, ['title' => 'Emysha own card', 'due_at' => '2026-10-01']);
        $card = $this->card($this->adri, ['title' => 'Adri client deck', 'due_at' => '2026-09-01']);

        $this->actingInTenantAs($this->adri)
            ->patchJson("/app/board/{$card->id}", ['tagged' => [['employee_id' => $this->emysha->id, 'role' => 'fyi']]])
            ->assertSuccessful();

        $this->assertDatabaseHas('work_item_participant', [
            'work_item_id' => $card->id, 'employee_id' => $this->emysha->id, 'role' => 'fyi',
        ]);

        $board = $this->actingInTenantAs($this->emysha)->get('/app/board')->assertOk();
        $board->assertSee('Adri client deck');
        $board->assertSee('Tagged – FYI');
        $board->assertDontSee('Tagged – Helper');

        $row = $this->personRow($this->emysha);
        $this->assertSame(1, $row['open'], 'FYI must not count as Assigned');
        $this->assertSame(0, $row['overdue'], 'an overdue FYI card must not count against her');
        $this->assertSame(0, $row['helping'], 'FYI gives no credit and no helping counter');
        $this->assertSame(0, $row['reviewing']);

        // Changing the same person from FYI to Helper is a replace, not a duplicate row.
        $this->actingInTenantAs($this->adri)
            ->patchJson("/app/board/{$card->id}", ['tagged' => [['employee_id' => $this->emysha->id, 'role' => 'helper']]])
            ->assertSuccessful();
        $this->assertSame(1, DB::table('work_item_participant')->where('work_item_id', $card->id)->count());
        $this->assertSame('helper', DB::table('work_item_participant')->where('work_item_id', $card->id)->value('role'));

        // An unknown role is refused.
        $this->actingInTenantAs($this->adri)
            ->patchJson("/app/board/{$card->id}", ['tagged' => [['employee_id' => $this->emysha->id, 'role' => 'owner']]])
            ->assertStatus(422);
    }

    #[Test]
    public function test_acceptance_3_reviewer_sees_the_card_under_reviewing_and_only_she_moves_in_review_to_done(): void
    {
        $card = $this->card($this->emysha, ['title' => 'Emysha reviewed card', 'due_at' => '2026-10-01', 'status' => 'review']);

        // The owner may not appoint a reviewer; a PM may. Reviewer can never be the owner.
        $this->actingInTenantAs($this->emysha)
            ->patchJson("/app/board/{$card->id}", ['reviewer_id' => $this->yati->id])
            ->assertStatus(403);
        $this->actingInTenantAs($this->pm)
            ->patchJson("/app/board/{$card->id}", ['reviewer_id' => $this->emysha->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['reviewer_id']);
        $this->actingInTenantAs($this->pm)
            ->patchJson("/app/board/{$card->id}", ['reviewer_id' => $this->yati->id])
            ->assertSuccessful();
        $this->assertSame($this->yati->id, $card->fresh()->reviewer_id);

        $audit = AuditLog::where('subject_id', $card->id)->where('field', 'reviewer_id')->latest('id')->first();
        $this->assertNotNull($audit, 'setting a reviewer not audited');
        $this->assertSame($this->yati->id, (int) json_decode($audit->new_value));

        // Yati's board shows it as a Reviewer card; her Assigned counters stay at zero.
        $board = $this->actingInTenantAs($this->yati)->get('/app/board')->assertOk();
        $board->assertSee('Emysha reviewed card');
        $board->assertSee('Reviewer');
        $this->assertMatchesRegularExpression(
            '/data-id="'.$card->id.'"[^>]*data-role="reviewer"|data-role="reviewer"[^>]*data-id="'.$card->id.'"/',
            $board->getContent()
        );
        $row = $this->personRow($this->yati);
        $this->assertSame(0, $row['open']);
        $this->assertSame(1, $row['reviewing']);
        $this->actingInTenantAs($this->pm)->get('/app/team-board')->assertOk()->assertSee('reviewing 1');

        // Only the reviewer moves In Review to Done. Owner, a helper and the PM cannot bypass.
        $card->participants()->attach($this->adri->id, ['role' => 'helper']);
        foreach ([$this->emysha, $this->adri, $this->pm] as $blocked) {
            $this->actingInTenantAs($blocked)
                ->postJson("/app/board/{$card->id}/move", ['status' => 'done'])
                ->assertStatus(403);
            $this->assertSame('review', $card->fresh()->status, "{$blocked->name} bypassed the reviewer");
        }
        // The reviewer's only extra power is that one move: she cannot edit the card...
        $this->actingInTenantAs($this->yati)
            ->patchJson("/app/board/{$card->id}", ['title' => 'Renamed by reviewer'])
            ->assertStatus(403);
        // ...but she can send it to Done.
        $this->actingInTenantAs($this->yati)
            ->postJson("/app/board/{$card->id}/move", ['status' => 'done'])
            ->assertSuccessful();
        $this->assertSame('done', $card->fresh()->status);

        // Without a reviewer the owner may move In Review to Done as today.
        $plain = $this->card($this->emysha, ['title' => 'Unreviewed card', 'due_at' => '2026-10-01', 'status' => 'review']);
        $this->actingInTenantAs($this->emysha)
            ->postJson("/app/board/{$plain->id}/move", ['status' => 'done'])
            ->assertSuccessful();
        $this->assertSame('done', $plain->fresh()->status);
    }

    #[Test]
    public function test_acceptance_4_assigned_filter_hides_tagged_and_reviewing_items(): void
    {
        $own = $this->card($this->emysha, ['title' => 'Emysha own card', 'due_at' => '2026-10-01']);
        $helped = $this->card($this->adri, ['title' => 'Adri helped card', 'due_at' => '2026-10-01']);
        $helped->participants()->attach($this->emysha->id, ['role' => 'helper']);
        $fyi = $this->card($this->adri, ['title' => 'Adri fyi card', 'due_at' => '2026-10-01']);
        $fyi->participants()->attach($this->emysha->id, ['role' => 'fyi']);
        $reviewed = $this->card($this->adri, ['title' => 'Adri reviewed card', 'due_at' => '2026-10-01', 'status' => 'review']);
        $reviewed->update(['reviewer_id' => $this->emysha->id]);

        $board = $this->actingInTenantAs($this->emysha)->get('/app/board')->assertOk();
        $html = $board->getContent();

        // All four cards render, each with the role the filter switches on.
        foreach ([$own->id => 'assigned', $helped->id => 'helper', $fyi->id => 'fyi', $reviewed->id => 'reviewer'] as $id => $role) {
            $this->assertMatchesRegularExpression(
                '/data-id="'.$id.'"[^>]*data-role="'.$role.'"|data-role="'.$role.'"[^>]*data-id="'.$id.'"/',
                $html,
                "card {$id} must carry data-role=\"{$role}\""
            );
        }

        // The chips exist; the Assigned chip must only keep data-role="assigned" cards. The
        // hiding itself is client-side and is checked in the browser by /qa grade.
        $board->assertSeeInOrder(['Assigned', 'Tagged', 'Reviewing', 'All'], false);
        $board->assertSee('data-role-filter="assigned"', false);
        $board->assertSee('data-role-filter="tagged"', false);
        $board->assertSee('data-role-filter="reviewing"', false);
        $board->assertSee('data-role-filter="all"', false);

        // Column badges count Assigned only: To Do holds Emysha's own card, not the two tagged ones.
        $board->assertSee('data-count="todo" style="', false);
        $this->assertMatchesRegularExpression('/data-count="todo"[^>]*>\s*1\s*</', $html, 'To Do badge must count Assigned cards only');
        $this->assertMatchesRegularExpression('/data-count="review"[^>]*>\s*0\s*</', $html, 'In Review badge must not count a card Emysha only reviews');
    }

    #[Test]
    public function test_acceptance_cr05_migration_existing_participants_and_subtask_helpers_are_helpers(): void
    {
        // Rows that pre-date the role column read back as helper (contract: safe default).
        $legacy = $this->card($this->adri, ['title' => 'Legacy shared card', 'due_at' => '2026-10-01']);
        DB::table('work_item_participant')->insert([
            'work_item_id' => $legacy->id, 'employee_id' => $this->emysha->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->assertSame('helper', DB::table('work_item_participant')->where('work_item_id', $legacy->id)->value('role'), 'pivot role must default to helper');
        $this->actingInTenantAs($this->emysha)->get('/app/board')->assertOk()->assertSee('Tagged – Helper');

        // Subtask helpers (CR-05) go through the same pivot with role helper, no second model.
        $parent = $this->card($this->adri, ['title' => 'Parent with sub', 'due_at' => '2026-10-01']);
        $this->actingInTenantAs($this->adri)->postJson('/app/board', [
            'title' => 'Sub for Yati', 'parent_id' => $parent->id, 'due_at' => '2026-09-20',
            'employee_id' => $this->yati->id, 'helper_ids' => [$this->emysha->id],
        ])->assertSuccessful();
        $child = WorkItem::withoutGlobalScopes()->where('parent_id', $parent->id)->firstOrFail();
        $this->assertDatabaseHas('work_item_participant', ['work_item_id' => $child->id, 'employee_id' => $this->emysha->id, 'role' => 'helper']);
        $this->assertSame(0, DB::table('work_item_participant')->where('work_item_id', $child->id)->where('employee_id', $this->yati->id)->count(), 'the subtask Assigned person is not also a participant');
    }

    #[Test]
    public function test_always_checks_from_s03(): void
    {
        $this->assertDueDateLocked();
        $this->assertAuditLogImmutable();
        $this->assertDashboardUnchanged();
        $this->assertKeepItPlainHonoured();
    }

    /**
     * The team board person row for $who as the PM sees it.
     *
     * @return array{open: int, overdue: int, helping: int, reviewing: int}
     */
    private function personRow(Employee $who): array
    {
        $html = $this->actingInTenantAs($this->pm)->get('/app/team-board')->assertOk()->getContent();
        $ok = preg_match('/<[^>]*data-person-id="'.$who->id.'"[^>]*>/', $html, $m);
        $this->assertSame(1, $ok, "no team-board row for {$who->name}");
        $row = $m[0];
        $attr = function (string $name) use ($row): int {
            $this->assertMatchesRegularExpression('/'.$name.'="(\d+)"/', $row, "row lacks {$name}");
            preg_match('/'.$name.'="(\d+)"/', $row, $mm);

            return (int) $mm[1];
        };

        return [
            'open' => $attr('data-open'),
            'overdue' => $attr('data-overdue'),
            'helping' => $attr('data-helping'),
            'reviewing' => $attr('data-reviewing'),
        ];
    }
}
