<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Give every card booked to an unambiguously tagged project the category that project was
 * already tagged with. The board now asks for the category on the card itself rather than
 * inferring it from the project on every read, so the inference has to be written down
 * once — otherwise a card that has been costing correctly for weeks goes blank the moment
 * the inference is removed.
 *
 * 30 of 35 live projects carry exactly one tag, so this answers all but a handful. A card
 * on a project tagged several ways, and a card with no project at all, are left null: they
 * are the ones that genuinely never had an answer, and the drawer asks for it.
 *
 * Written as a loop rather than an UPDATE ... JOIN so it runs on sqlite as well as MySQL.
 */
return new class extends Migration
{
    public function up(): void
    {
        $single = DB::table('project_timesheet_category')
            ->select('project_id', DB::raw('MIN(timesheet_category_id) as category_id'), DB::raw('COUNT(*) as tags'))
            ->groupBy('project_id')
            ->having('tags', '=', 1)
            ->pluck('category_id', 'project_id');

        foreach ($single as $projectId => $categoryId) {
            DB::table('work_items')
                ->where('project_id', $projectId)
                ->whereNull('timesheet_category_id')
                ->update(['timesheet_category_id' => $categoryId]);
        }
    }

    /**
     * Irreversible by design: the pre-migration state cannot be told apart from a card
     * whose owner picked that same category by hand, and clearing both would throw away
     * a real answer.
     */
    public function down(): void {}
};
