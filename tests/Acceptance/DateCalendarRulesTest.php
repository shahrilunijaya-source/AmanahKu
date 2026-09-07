<?php

namespace Tests\Acceptance;

use App\Mcp\Tools\ConfirmWriteTool;
use App\Mcp\Tools\UpdateCardTool;
use App\Models\AuditLog;
use App\Models\WorkItem;
use App\Support\WorkforceInsights;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Throwable;

/**
 * Acceptance for docs/specs/date-calendar-rules.md (session S02, the lock engine).
 *
 * Items 1, 2, 3, 5 and 6 need an Event type (S13, CR-11) and the CalendarPort stub
 * with `port_outbox` (S07). Item 8 needs TOT Tindakan (S12, CR-10). None of those exist
 * at S02, so those items are incomplete here and named as debts of CR11Test / CR10Test.
 * Items 4 and 7 are the S02 gate and are exercised in full.
 *
 * Shapes this file fixes for the generator (recorded in docs/build/OPEN.md):
 * - a locked due date is refused with HTTP 422 on `due_at`, and a model update throws;
 * - cancel-and-recreate is `POST /app/board/{id}/cancel` with a required `reason`;
 *   it stamps `cancelled_at`, takes the card off the board like an archive, and writes
 *   an audit row for field `cancelled_at` carrying that reason;
 * - a top-level work card created through `POST /app/board` must carry `due_at`;
 * - rows with `type = 'event'` never count as overdue.
 */
class DateCalendarRulesTest extends TestCase
{
    use AlwaysChecks;
    use RefreshDatabase;

    private const TODAY = '2026-09-08 10:00:00';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(self::TODAY);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        app(CurrentTenant::class)->set(null);
        parent::tearDown();
    }

    #[Test]
    public function test_acceptance_1_event_moved_in_amanahku_updates_the_linked_calendar_event(): void
    {
        $this->markTestIncomplete(
            'human check until S13: no Event type and no CalendarPort/port_outbox exist at S02. '
            .'CR11Test acceptance 1 must move an Event from 2026-09-10 to 2026-09-15 and assert one '
            .'port_outbox row (port calendar, method upsertEvent) whose payload carries the 15 Sep date '
            .'and the same external id as the first upsert.'
        );
    }

    #[Test]
    public function test_acceptance_2_calendar_change_updates_the_amanahku_event(): void
    {
        $this->markTestIncomplete(
            'human check until S13: CR11Test acceptance 2 must feed a pullChanges result (stub) with '
            .'the linked event on 2026-09-18 and assert the Event start date becomes 18 Sep, with an '
            .'audit row old 15 Sep, new 18 Sep, source job.'
        );
    }

    #[Test]
    public function test_acceptance_3_repeated_reschedules_keep_one_event_and_a_full_history(): void
    {
        $this->markTestIncomplete(
            'human check until S13: CR11Test acceptance 3 must reschedule one Event three times and '
            .'assert exactly one work_items row, one distinct external id across every port_outbox '
            .'row, and three audit rows for start_at each with old and new values.'
        );
    }

    #[Test]
    public function test_acceptance_4_task_due_date_cannot_change_after_first_save_in_ui_and_api(): void
    {
        $owner = $this->person('Emysha');
        $this->actingInTenantAs($owner);

        // First set (null to a date) is the one legal due-date write, and it is audited.
        $item = $this->card($owner, ['title' => 'Prototype POC']);
        $this->patchJson("/app/board/{$item->id}", ['due_at' => '2026-10-01'])->assertSuccessful();
        $this->assertSame('2026-10-01', $item->fresh()->due_at?->format('Y-m-d'));
        $this->assertNotNull(
            AuditLog::where('subject_id', $item->id)->where('field', 'due_at')->first(),
            'first due-date set must be audited'
        );

        // Web API: a different date, and a null, are both refused with a validation error.
        $this->patchJson("/app/board/{$item->id}", ['due_at' => '2026-10-15'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['due_at']);
        $this->patchJson("/app/board/{$item->id}", ['due_at' => null])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['due_at']);
        // Sending the same date back (the drawer re-posts every field) is not a change.
        $this->patchJson("/app/board/{$item->id}", ['due_at' => '2026-10-01', 'title' => 'Prototype POC v2'])->assertSuccessful();
        $this->assertSame('2026-10-01', $item->fresh()->due_at?->format('Y-m-d'));
        $this->assertSame('Prototype POC v2', $item->fresh()->title);

        // Subtask: same lock through the same route.
        $this->postJson('/app/board', [
            'title' => 'Sub', 'parent_id' => $item->id, 'due_at' => '2026-09-20',
        ])->assertSuccessful();
        $child = WorkItem::withoutGlobalScopes()->where('parent_id', $item->id)->firstOrFail();
        $this->assertSame('2026-09-20', $child->due_at?->format('Y-m-d'));
        $this->patchJson("/app/board/{$child->id}", ['due_at' => '2026-09-25'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['due_at']);
        $this->assertSame('2026-09-20', $child->fresh()->due_at?->format('Y-m-d'));

        // MCP integration: update_card may not move it either, at preview or at confirm.
        $token = $owner->user->mintApiToken($this->tenant(), 'acceptance', ['board:write'])->plainTextToken;
        $headers = ['Authorization' => 'Bearer '.$token];
        $preview = $this->callTool(UpdateCardTool::class, ['work_item_id' => $item->id, 'due_at' => '2026-11-01'], $headers);
        if (! $preview->json('result.isError')) {
            $confirmToken = json_decode((string) $preview->json('result.content.0.text'), true)['confirm_token'] ?? null;
            $this->assertNotNull($confirmToken, 'preview neither refused nor minted a token');
            $confirm = $this->callTool(ConfirmWriteTool::class, ['confirm_token' => $confirmToken], $headers);
            $this->assertTrue((bool) $confirm->json('result.isError'), 'MCP confirm moved a locked due date');
        }
        $this->assertSame('2026-10-01', $item->fresh()->due_at?->format('Y-m-d'), 'MCP changed a locked due date');

        // A stray model write cannot slip past either.
        $item->refresh();
        $threw = false;
        try {
            $item->update(['due_at' => '2026-12-01']);
        } catch (Throwable) {
            $threw = true;
        }
        $this->assertTrue($threw, 'model update changed a locked due date');
        $this->assertSame('2026-10-01', $item->fresh()->due_at?->format('Y-m-d'));

        // Due date is mandatory on new work cards.
        $this->postJson('/app/board', ['title' => 'No date', 'type' => 'task', 'priority' => 'low'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['due_at']);
        $this->postJson('/app/board', ['title' => 'Dated', 'type' => 'task', 'priority' => 'low', 'due_at' => '2026-10-05'])
            ->assertSuccessful();
        $this->assertSame('2026-10-05', WorkItem::where('title', 'Dated')->firstOrFail()->due_at?->format('Y-m-d'));

        // Moving the work honestly: cancel with a reason, create a new card.
        $this->postJson("/app/board/{$item->id}/cancel", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['reason']);
        $this->postJson("/app/board/{$item->id}/cancel", ['reason' => 'Client moved the milestone'])->assertSuccessful();

        $cancelled = $item->fresh();
        $this->assertNotNull($cancelled->cancelled_at, 'cancel did not stamp cancelled_at');
        $this->assertNotNull($cancelled->archived_at, 'a cancelled card must leave the board like an archived one');
        $this->assertSame('2026-10-01', $cancelled->due_at?->format('Y-m-d'), 'cancel must not touch the locked date');
        $this->assertDatabaseHas('work_items', ['id' => $item->id]);

        $audit = AuditLog::where('subject_id', $item->id)->where('field', 'cancelled_at')->latest('id')->first();
        $this->assertNotNull($audit, 'cancel not audited');
        $this->assertSame('Client moved the milestone', $audit->reason);
        $this->assertSame($owner->user_id, $audit->user_id);

        $this->get('/app/board')->assertOk()->assertDontSee('Prototype POC v2');
        $this->getJson('/app/board/archived')->assertOk()->assertSee('Prototype POC v2');

        $this->postJson('/app/board', ['title' => 'Prototype POC (new date)', 'type' => 'task', 'priority' => 'low', 'due_at' => '2026-10-15'])
            ->assertSuccessful();
        $this->assertSame('2026-10-15', WorkItem::where('title', 'Prototype POC (new date)')->firstOrFail()->due_at?->format('Y-m-d'));
    }

    #[Test]
    public function test_acceptance_5_calendar_move_of_a_task_does_not_change_the_due_date_and_snaps_back(): void
    {
        $this->markTestIncomplete(
            'human check until S13: CR11Test acceptance 5 must feed a pullChanges result (stub) that '
            .'moves a Task calendar entry, then assert due_at unchanged, one port_outbox upsertEvent row '
            .'restoring the locked date, and an audit row noting the snap-back on the card.'
        );
    }

    #[Test]
    public function test_acceptance_6_calendar_cancellation_marks_the_event_cancelled_and_keeps_it(): void
    {
        $this->markTestIncomplete(
            'human check until S13: CR11Test acceptance 6 must feed a pullChanges result (stub) with '
            .'the linked event deleted, then assert the Event row still exists, is not archived, carries '
            .'cancelled_at, and has an audit row for it.'
        );
    }

    #[Test]
    public function test_acceptance_7_event_dates_never_count_as_overdue(): void
    {
        $owner = $this->person('Emysha');
        $this->actingInTenantAs($owner);
        app(CurrentTenant::class)->set($this->tenant());

        // A work card past its locked date is overdue.
        $task = $this->card($owner, ['title' => 'Late task', 'due_at' => '2026-09-01']);
        // An Event past its date is not, however it got there. Written straight to the
        // table because no Event UI exists before S13; the exclusion rule must already hold.
        $event = $this->card($owner, ['title' => 'Old townhall', 'type' => 'event', 'due_at' => '2026-09-01']);
        // A cancelled card is not overdue either.
        $cancelled = $this->card($owner, ['title' => 'Dropped work', 'due_at' => '2026-09-01']);
        $this->postJson("/app/board/{$cancelled->id}/cancel", ['reason' => 'Scope removed'])->assertSuccessful();

        $overdue = app(WorkforceInsights::class)->overdueItems()->pluck('id')->all();
        $this->assertContains($task->id, $overdue, 'a late task must be overdue');
        $this->assertNotContains($event->id, $overdue, 'an Event counted as overdue');
        $this->assertNotContains($cancelled->id, $overdue, 'a cancelled card counted as overdue');

        $board = $this->get('/app/board')->assertOk();
        $this->assertSame(1, substr_count($board->getContent(), 'wc-when--over'), 'only the late task may carry the overdue marker');

        // Rescheduling the Event to a future date must be allowed and audited; the awards side
        // (no effect on Deadline Who?, Done & Dusted, Chief Firefighter) is asserted by CR14Test.
        $event->refresh();
        $event->update(['due_at' => '2026-09-30']);
        $this->assertSame('2026-09-30', $event->fresh()->due_at?->format('Y-m-d'), 'an Event date must stay reschedulable');
        $this->assertNotNull(
            AuditLog::where('subject_id', $event->id)->where('field', 'due_at')->latest('id')->first(),
            'Event reschedule not audited'
        );
    }

    #[Test]
    public function test_acceptance_8_tot_tindakan_sasaran_editable_until_first_save_then_locked(): void
    {
        $this->markTestIncomplete(
            'human check until S12: TOT Tindakan does not exist at S02. CR10Test must create a Tindakan '
            .'with a Sasaran date, assert the Task it spawns carries that date, then assert a later '
            .'Sasaran edit and a Task due_at edit are both refused with 422.'
        );
    }

    #[Test]
    public function test_always_checks_from_s02(): void
    {
        $this->assertDueDateLocked();
        $this->assertAuditLogImmutable();
        $this->assertDashboardUnchanged();
        $this->assertKeepItPlainHonoured();
    }

    /** Same JSON-RPC envelope tests/Feature/Mcp/AmanahkuWriteToolsTest uses. */
    private function callTool(string $toolClass, array $arguments, array $headers): TestResponse
    {
        Auth::forgetGuards();

        return $this->postJson('/mcp/amanahku', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => app($toolClass)->name(), 'arguments' => $arguments],
        ], $headers);
    }
}
