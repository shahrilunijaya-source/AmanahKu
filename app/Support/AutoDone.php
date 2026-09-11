<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\WorkItem;
use App\Models\WorkItemComment;

/**
 * CR-19: the one place every system-generated card's auto-close/-archive/-cancel goes
 * through, so the Global Clause 1 audit row, the `work_items.auto_closed_at` marker and
 * the "Closed automatically – <reason>" activity line can never drift between the six
 * different triggers (event attendance, awards nomination/select, the scheduler).
 *
 * Every method saves the model directly (never a query-builder bulk update), so
 * App\Models\Concerns\AuditsChanges fires and writes the audit row itself — the same
 * reason WorkItemController::move()/archive()/cancel() already work this way.
 */
final class AutoDone
{
    /** Close a card Done, automatically. */
    public static function done(WorkItem $card, string $reason): void
    {
        $card->status = 'done';
        $card->progress = 100;
        $card->done_at = $card->done_at ?? now();
        $card->auto_closed_at = now();
        $card->save();

        self::leaveTrail($card, $reason);
    }

    /** Archive a card off the board, automatically — not Done. */
    public static function archived(WorkItem $card, string $reason): void
    {
        $card->archived_at = now();
        $card->auto_closed_at = now();
        $card->save();

        self::leaveTrail($card, $reason);
    }

    /** Cancel (and archive) a card, automatically — an invitation withdrawn, never Done. */
    public static function cancelled(WorkItem $card, string $reason): void
    {
        $card->cancelled_at = now();
        $card->archived_at = now();
        $card->auto_closed_at = now();
        $card->save();

        self::leaveTrail($card, $reason);
    }

    /** The activity line every auto-close leaves: a system comment, en dash, no author. */
    private static function leaveTrail(WorkItem $card, string $reason): void
    {
        WorkItemComment::create([
            'work_item_id' => $card->id,
            'employee_id' => null,
            'body' => "Closed automatically \u{2013} {$reason}",
        ]);
    }
}
