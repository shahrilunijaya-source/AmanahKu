<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\AwardResult;
use App\Models\Employee;
use App\Tenancy\CurrentTenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * CR-14b: one shared query building the "slides" for a published month — one entry per
 * award_key, a tie sharing its slide (every winner listed), in AwardCatalog::order().
 * Used by both the dashboard carousel band and the Awards screen's "This month's
 * winners" / "Past winners" tabs, so the two never drift apart on grouping or counts.
 *
 * A tied slide's reactions/comments live on its FIRST result row (lowest id) — CR-14
 * never says how a shared slide's engagement should split across winners, and no
 * acceptance test exercises reacting on a tie, so one shared thread per slide is the
 * simplest reading. Reversal: key `award_reactions`/`award_comments` by (result ids in
 * the tie) instead of one id, if that ever needs to change.
 */
final class AwardBoard
{
    /**
     * @return Collection<int, object{award_key:string, winners: Collection<int, AwardResult>, copy: array, primaryResultId: int, label: string, reason: ?string, source: string, reactionCount: int, comments: Collection}|object{award_key:string, employee: ?Employee, employee_id: int, category: string, explanation: string, byDirector: bool, monthLabel: string}>
     */
    public static function slidesForMonth(string $monthDate): Collection
    {
        $mystery = self::mysterySlide($monthDate);

        $results = AwardResult::whereDate('month', $monthDate)->with('employee:id,name,nickname,position_id,avatar_color,initials')->orderBy('id')->get();
        if ($results->isEmpty()) {
            return $mystery === null ? collect() : collect([$mystery]);
        }

        $primaryIds = $results->groupBy('award_key')->map(fn (Collection $rows) => $rows->sortBy('id')->first()->id);

        $reactionCounts = DB::table('award_reactions')->whereIn('award_result_id', $primaryIds->values())
            ->select('award_result_id', DB::raw('count(*) as c'))->groupBy('award_result_id')->pluck('c', 'award_result_id');

        $comments = DB::table('award_comments')->join('employees', 'employees.id', '=', 'award_comments.employee_id')
            ->whereIn('award_result_id', $primaryIds->values())
            ->orderBy('award_comments.created_at')
            ->get(['award_result_id', 'body', 'employees.name as name'])
            ->groupBy('award_result_id');

        $order = AwardCatalog::order();

        $slides = $results->groupBy('award_key')
            ->sortBy(fn (Collection $rows, string $key) => array_search($key, $order, true))
            ->values()
            ->map(function (Collection $rows) use ($reactionCounts, $comments) {
                $primary = $rows->sortBy('id')->first();

                return (object) [
                    'award_key' => $primary->award_key,
                    'winners' => $rows,
                    'copy' => AwardCatalog::copy($primary->award_key),
                    'primaryResultId' => $primary->id,
                    'label' => $primary->label,
                    'reason' => $primary->reason,
                    'source' => $primary->source,
                    'reactionCount' => (int) ($reactionCounts[$primary->id] ?? 0),
                    'comments' => $comments->get($primary->id, collect()),
                ];
            });

        return $mystery === null ? $slides : $slides->push($mystery);
    }

    /**
     * CR-27: the Mystery Award is never an `award_results` row (it must never reach the
     * rule-9/10 resolver, Hall of Fame or the profile badge), so it is looked up on the
     * side and appended last by the caller. Null until `awards:publish` stamps
     * `published_at` — before that the category and explanation stay off every page.
     */
    private static function mysterySlide(string $monthDate): ?object
    {
        $row = DB::table('mystery_awards')->where('tenant_id', app(CurrentTenant::class)->id())
            ->whereDate('month', $monthDate)->whereNotNull('published_at')->first();
        if ($row === null) {
            return null;
        }

        $picker = Employee::find($row->picked_by);
        $tenant = app(CurrentTenant::class)->get();
        $byDirector = $tenant !== null && $picker?->user?->roleIn($tenant) === 'director';

        return (object) [
            'award_key' => 'mystery',
            'employee' => Employee::find($row->employee_id),
            'employee_id' => (int) $row->employee_id,
            'category' => $row->category,
            'explanation' => $row->explanation,
            'byDirector' => $byDirector,
            'monthLabel' => Carbon::parse($monthDate)->format('F'),
        ];
    }

    /** Every month with published results, newest first, as 'Y-m-d'. @return list<string> */
    public static function publishedMonths(): array
    {
        return AwardResult::query()->select('month')->distinct()->orderByDesc('month')
            ->pluck('month')->map(fn ($m) => Carbon::parse($m)->toDateString())->all();
    }
}
