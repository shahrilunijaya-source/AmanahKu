<?php

namespace Tests\Acceptance;

use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Acceptance for docs/specs/global-clause.md (session S01, audit log).
 *
 * The clause's own text locks work-item due dates, so acceptance item 1 is exercised
 * on the one due-date write that stays legal after S02 (first set, null to a date) and
 * on a priority change, which carries a real old and new value. Event reschedules,
 * the other legal date change, arrive with CR-11 in S13 and are covered there.
 */
class GlobalClauseTest extends TestCase
{
    use AlwaysChecks;
    use RefreshDatabase;

    #[Test]
    public function test_acceptance_1_setting_a_due_date_is_audited_with_old_new_actor_time_reason_and_the_entry_is_immutable(): void
    {
        Carbon::setTestNow('2026-09-08 10:00:00');
        $owner = $this->person('Emysha');
        $item = $this->card($owner, ['title' => 'Prototype POC']);

        $this->actingInTenantAs($owner)
            ->patchJson("/app/board/{$item->id}", ['due_at' => '2026-10-01', 'reason' => 'Client agreed the date'])
            ->assertSuccessful();

        $this->assertSame('2026-10-01', $item->fresh()->due_at?->format('Y-m-d'));

        $entry = AuditLog::query()
            ->where('subject_type', $item->getMorphClass())
            ->where('subject_id', $item->id)
            ->where('field', 'due_at')
            ->latest('id')
            ->first();

        $this->assertNotNull($entry, 'no audit entry for the due date change');
        $this->assertNull(json_decode($entry->old_value), 'old value should be null on first set');
        $this->assertStringStartsWith('2026-10-01', (string) json_decode($entry->new_value));
        $this->assertSame($owner->user_id, $entry->user_id, 'actor');
        $this->assertSame('2026-09-08 10:00:00', $entry->created_at->format('Y-m-d H:i:s'), 'timestamp');
        $this->assertSame('Client agreed the date', $entry->reason);
        $this->assertSame('ui', $entry->source);

        $this->assertAuditLogImmutable();

        Carbon::setTestNow();
    }

    #[Test]
    public function test_acceptance_1b_priority_change_is_audited_with_both_values_and_the_actor(): void
    {
        $owner = $this->person('Emysha');
        $item = $this->card($owner, ['priority' => 'low', 'due_at' => '2026-10-01']);

        $this->actingInTenantAs($owner)
            ->patchJson("/app/board/{$item->id}", ['priority' => 'high'])
            ->assertSuccessful();

        $entry = AuditLog::query()
            ->where('subject_type', $item->getMorphClass())
            ->where('subject_id', $item->id)
            ->where('field', 'priority')
            ->latest('id')
            ->first();

        $this->assertNotNull($entry, 'priority change not audited');
        $this->assertSame('low', json_decode($entry->old_value));
        $this->assertSame('high', json_decode($entry->new_value));
        $this->assertSame($owner->user_id, $entry->user_id);
    }

    #[Test]
    public function test_acceptance_1c_web_board_moves_and_archives_are_audited(): void
    {
        $owner = $this->person('Emysha');
        $item = $this->card($owner, ['due_at' => '2026-10-01']);

        $this->actingInTenantAs($owner)->postJson("/app/board/{$item->id}/move", ['status' => 'done'])->assertSuccessful();
        $this->actingInTenantAs($owner)->postJson("/app/board/{$item->id}/archive")->assertSuccessful();

        $fields = AuditLog::query()
            ->where('subject_type', $item->getMorphClass())
            ->where('subject_id', $item->id)
            ->pluck('field')
            ->all();

        $this->assertContains('status', $fields, 'status move through the web UI not audited');
        $this->assertContains('archived_at', $fields, 'archive through the web UI not audited');

        $status = AuditLog::where('subject_id', $item->id)->where('field', 'status')->latest('id')->first();
        $this->assertSame('todo', json_decode($status->old_value));
        $this->assertSame('done', json_decode($status->new_value));
    }

    #[Test]
    public function test_acceptance_2_timesheet_approved_on_1_oct_for_september_counts_in_october_freeze(): void
    {
        $this->markTestIncomplete(
            'human check until S17: the frozen award snapshot (CR-14a) does not exist yet, so there is no '
            .'surface to assert which month an approval counts in. CR14Test acceptance 1 must include a '
            .'timesheet decided_at 2026-10-01 for a September week and assert it is absent from the '
            .'September snapshot and present in October.'
        );
    }

    #[Test]
    public function test_acceptance_3_director_override_shows_adjustment_note_and_audit_entry(): void
    {
        $this->markTestIncomplete(
            'human check until S18: award results and the Awards page (CR-14b) do not exist yet. '
            .'CR14Test must cover: director overrides a result with a reason, the Awards page shows '
            ."'Result adjusted – <reason>', and audit_logs holds the old and new winner with that reason."
        );
    }

    #[Test]
    public function test_always_checks_that_apply_from_s01(): void
    {
        $this->assertAuditLogImmutable();
        $this->assertDashboardUnchanged();
        $this->assertKeepItPlainHonoured();
    }
}
