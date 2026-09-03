<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Scopes\ParentOnly;
use App\Models\WorkItem;
use Illuminate\Console\Command;

/**
 * Auto-archive board cards that have sat in Done for a full day.
 *
 * A single mass UPDATE across every tenant: WorkItem::query() runs with no CurrentTenant
 * set (BelongsToTenant's scope is fail-open on reads), so one statement covers all tenants
 * without a per-tenant loop. Mass update skips model events, which is fine here — nothing
 * listens on WorkItem save/update.
 *
 * Reads `done_at`, not `updated_at` — `updated_at` is bumped by column-reorder drags and
 * unrelated card edits (title, labels, participants), which would keep pushing a finished
 * card's archive date out. `done_at` is stamped once, only on the transition into Done
 * (WorkItemController::store()/move()), so it reflects how long the card has actually been done.
 */
class ArchiveDoneWorkItems extends Command
{
    protected $signature = 'work:archive-done';

    protected $description = 'Archive Done board cards that have been done for over a day.';

    public function handle(): int
    {
        $count = WorkItem::query()
            ->where('status', 'done')
            ->whereNull('archived_at')
            ->whereNotNull('done_at')
            ->where('done_at', '<=', now()->subDay())
            ->update(['archived_at' => now()]);

        // Children follow their parent off the board. Their own done_at is irrelevant:
        // an archived parent is finished business, subtasks included.
        WorkItem::withoutGlobalScope(ParentOnly::class)
            ->whereNotNull('parent_id')
            ->whereNull('archived_at')
            ->whereHas('parent', fn ($q) => $q->whereNotNull('archived_at'))
            ->update(['archived_at' => now()]);

        $this->info("Archived {$count} done work item(s).");

        return self::SUCCESS;
    }
}
