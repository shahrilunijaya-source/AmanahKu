<?php

use App\Support\WorkItemLabelMapper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * T.A.A. board redesign, Stage 4, Migration A. Three labels duplicated a field
 * the card already carried, and could contradict it (a card labelled `Urgent`
 * while `priority = low`; a card labelled `Review` while sitting in To Do):
 *
 *  - `urgent`  -> `priority = high` unless already `high`, label removed.
 *  - `waiting` -> renamed to `blocked`, de-duplicated if already present.
 *  - `review`  -> removed outright. It maps to nothing.
 *
 * `WorkItem::LABELS` is pruned to `blocked`, `client`, `internal` in the same
 * deploy (see WorkItem::LABELS itself, not this file).
 *
 * The actual mapping is WorkItemLabelMapper::map() — a plain static method
 * with no DB dependency, unit-tested directly in
 * tests/Unit/WorkItemLabelMapperTest.php so it is provable without running
 * this migration. This file's only job is to fetch every row, hand it to the
 * mapper, and write back what changed.
 *
 * Uses the raw query builder rather than the WorkItem model: the model's
 * BelongsToTenant global scope reads fail-open with no active tenant context
 * (true here), but going through DB::table avoids any accessor/cast surprises
 * and matches this codebase's existing convention for cross-tenant data
 * migrations (see 2026_07_08_000002_backfill_module_overtime_from_module_leave.php,
 * 2026_07_29_090000_darken_low_contrast_avatar_colors.php).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('work_items')->select(['id', 'labels', 'priority'])
            ->chunkById(200, function ($rows) {
                foreach ($rows as $row) {
                    /** @var array<int, string>|null $currentLabels */
                    $currentLabels = $row->labels !== null ? json_decode($row->labels, true) : null;

                    $result = WorkItemLabelMapper::map($currentLabels, $row->priority);

                    DB::table('work_items')->where('id', $row->id)->update([
                        'labels' => json_encode($result['labels']),
                        'priority' => $result['priority'],
                    ]);
                }
            });
    }

    /**
     * Honest partial reversal — the merges here are one-directional and this
     * does not pretend otherwise:
     *
     *  - `waiting` -> `blocked` cannot be un-merged. Once a card's `waiting`
     *    becomes `blocked`, there is no marker left distinguishing it from a
     *    `blocked` label the card already had (or from `blocked` some other
     *    process sets later). Restoring `waiting` would be a guess, not a
     *    reversal.
     *  - `review` was deleted outright with nothing recorded in its place.
     *    There is no data left to restore it from.
     *  - The `urgent` -> `priority=high` merge is one-directional for the same
     *    reason: a card already at `high` before this migration is
     *    indistinguishable afterwards from one raised to `high` by it.
     *
     * Rolling back this migration is therefore a schema-level no-op. Recovery
     * of the pre-migration label/priority values is only possible from the
     * `mysqldump` taken before Migration A ran.
     */
    public function down(): void
    {
        // Intentionally a no-op. See class docblock: none of Migration A's
        // three merges can be reversed from data left in the table.
    }
};
