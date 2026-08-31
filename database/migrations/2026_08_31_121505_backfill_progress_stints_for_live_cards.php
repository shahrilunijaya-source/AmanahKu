<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Start the clock on every card that was already sitting in In Progress or In Review
 * when stints arrived. A stint is only opened when a card MOVES into one of those
 * columns, so without this the whole live board is invisible to the timesheet: a
 * staffer opens the capture screen, sees "Nothing from your board for this day", and
 * the only way out is dragging every card out of its column and back in.
 *
 * started_at is the moment this runs, not the card's updated_at. updated_at sounds
 * more truthful but a card last touched three weeks ago would then propose a row for
 * every working day since, and the week would open as a wall of prefilled lines
 * nobody worked. Counting from launch matches what actually happened: nothing was
 * recording this before today.
 *
 * Cards with a stint already open are skipped, so this is safe to re-run and cannot
 * double-count a card that moved between the deploy and the migration.
 *
 * Written as a loop rather than an INSERT ... SELECT so it runs on sqlite as well
 * as MySQL, matching the other backfills here.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $live = DB::table('work_items')
            ->whereIn('status', ['prog', 'review'])
            ->whereNull('archived_at')
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('work_item_progress_stints')
                    ->whereColumn('work_item_progress_stints.work_item_id', 'work_items.id')
                    ->whereNull('ended_at');
            })
            ->get(['id', 'tenant_id']);

        foreach ($live->chunk(200) as $chunk) {
            DB::table('work_item_progress_stints')->insert(
                $chunk->map(fn ($card) => [
                    'tenant_id' => $card->tenant_id,
                    'work_item_id' => $card->id,
                    'started_at' => $now,
                    'ended_at' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all()
            );
        }
    }

    /**
     * Irreversible by design: once a card has been moved around, a backfilled stint is
     * indistinguishable from one the board opened itself, and deleting both would throw
     * away real worked days.
     */
    public function down(): void {}
};
