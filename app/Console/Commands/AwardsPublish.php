<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AppNotification;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Tenant;
use App\Models\WorkItem;
use App\Support\AutoDone;
use App\Support\AwardCatalog;
use App\Support\Awards;
use App\Tenancy\CurrentTenant;
use App\Timesheet\DayRules;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * CR-14a: every day at 08:00, on the first working day of the month only
 * (`App\Timesheet\DayRules::isWorkingDay`), publish the PREVIOUS month's awards from its
 * frozen `award_snapshots` into `award_results`. Resolves CR-14 rules 9 (max two awards a
 * person) and 10 (no repeat winner) by walking `Awards::KEYS` in list order: per award,
 * value-sorted groups (ties together) are tried best-first, skipping a group only when
 * every candidate in it is blocked by rule 10 or already capped by rule 9, until one
 * group survives or the award goes without a winner.
 *
 * Idempotency is keyed on an audit row (`action = 'awards.published'`,
 * `target = <month>`), not on `award_results` having rows — a month where nothing had a
 * winner still must not re-publish (and re-notify) every day after the trigger day.
 */
class AwardsPublish extends Command
{
    protected $signature = 'awards:publish';

    protected $description = 'Publish last month\'s awards from the frozen snapshot on the first working day of the month.';

    public function handle(CurrentTenant $context, DayRules $dayRules): int
    {
        $today = Carbon::now()->startOfDay();
        $published = 0;

        foreach (Tenant::query()->orderBy('id')->get() as $tenant) {
            $context->set($tenant);

            try {
                $published += $this->publishTenant($today, $dayRules);
            } catch (\Throwable $e) {
                report($e);
                $this->error("Awards publish failed for tenant {$tenant->id}: {$e->getMessage()}");
            }
        }

        $context->set(null);
        $this->info("Award result rows published: {$published}.");

        return self::SUCCESS;
    }

    private function publishTenant(Carbon $today, DayRules $dayRules): int
    {
        if (! $this->isFirstWorkingDayOfMonth($today, $dayRules)) {
            return 0;
        }

        $tenantId = app(CurrentTenant::class)->id();
        $month = $today->copy()->subMonthNoOverflow()->startOfMonth();

        $alreadyPublished = AuditLog::where('tenant_id', $tenantId)
            ->where('action', 'awards.published')->where('target', $month->toDateString())->exists();
        if ($alreadyPublished) {
            return 0;
        }

        $snapshot = DB::table('award_snapshots')
            ->where('tenant_id', $tenantId)->whereDate('month', $month->toDateString())->get()
            ->groupBy('award_key');

        $previousMonth = $month->copy()->subMonthNoOverflow()->startOfMonth();
        $previousWinners = DB::table('award_results')
            ->where('tenant_id', $tenantId)->whereDate('month', $previousMonth->toDateString())->get()
            ->groupBy('award_key')->map(fn (Collection $rows) => $rows->pluck('employee_id')->map(fn ($id) => (int) $id)->all());

        // CR-14b: main_character/office_yoda have no award_snapshots row (Awards::compute()
        // never computes them) — their candidates are this month's peer nominations, one
        // "value" per nominee = its vote count, same rule 9/10 resolver as every auto award.
        $nominations = DB::table('award_nominations')
            ->where('tenant_id', $tenantId)->whereDate('month', $month->toDateString())
            ->whereIn('award_key', AwardCatalog::NOMINATED_KEYS)
            ->get()->groupBy('award_key')
            ->map(fn (Collection $rows) => $rows->groupBy('nominee_employee_id')->map(fn (Collection $votes, $employeeId) => (object) [
                'employee_id' => $employeeId,
                'value' => (float) $votes->count(),
                'label' => $votes->count().' nomination'.($votes->count() === 1 ? '' : 's'),
            ])->values());

        $publishedAt = Carbon::now();
        $winCounts = [];
        $rows = [];

        foreach (Awards::KEYS as $key) {
            $rows = [...$rows, ...$this->publishAward($key, $snapshot->get($key, collect()), $previousWinners->get($key, []), $winCounts, 'auto', $tenantId, $month, $publishedAt)];
        }
        foreach (AwardCatalog::NOMINATED_KEYS as $key) {
            $rows = [...$rows, ...$this->publishAward($key, $nominations->get($key, collect()), $previousWinners->get($key, []), $winCounts, 'nomination', $tenantId, $month, $publishedAt)];
        }

        if ($rows !== []) {
            DB::table('award_results')->insert($rows);
        }

        AuditLog::record('awards.published', $month->toDateString());

        // CR-19: publishing closes every still-open "Select manual award winners" card for
        // the month, whoever it belongs to — not only whoever actually made a pick. A
        // pick already closed its own card (AwardController::select() via closeCard());
        // this sweeps everyone else's.
        WorkItem::where('source', 'awards')->where('source_ref', $month->format('Y-m').'-select')
            ->where('status', '!=', 'done')
            ->get()
            ->each(fn (WorkItem $card) => AutoDone::done($card, 'the awards were published'));

        AppNotification::sendMany(
            Employee::where('tenant_id', $tenantId)->active()->where('status', '!=', 'resigned')->whereNotNull('user_id')->pluck('user_id'),
            $month->format('F Y').' awards are out!',
            count($rows) > 0 ? 'See who won this month.' : 'No awards were eligible for a winner this month.',
            '/app/awards',
            "award-publish-{$month->toDateString()}",
        );

        return count($rows);
    }

    /**
     * Resolves one award's winner(s) and shapes them into `award_results` insert rows,
     * tagged with the given `source` — 'auto' for the 15 computed awards, 'nomination'
     * for the two peer-voted ones (CR-14b). Also advances $winCounts by reference so rule
     * 9 (max two awards per person) is enforced across BOTH loops in publishTenant(), not
     * just within one of them.
     *
     * @param  array<int, int>  &$winCounts
     * @param  list<int>  $blocked
     * @return list<array<string, mixed>>
     */
    private function publishAward(string $key, Collection $candidates, array $blocked, array &$winCounts, string $source, int $tenantId, Carbon $month, Carbon $publishedAt): array
    {
        $rows = [];
        foreach ($this->resolveWinners($candidates, $key, $blocked, $winCounts) as $winner) {
            $rows[] = [
                'tenant_id' => $tenantId,
                'month' => $month->toDateString(),
                'award_key' => $key,
                'employee_id' => (int) $winner->employee_id,
                'value' => $winner->value,
                'label' => $winner->label,
                'source' => $source,
                'reason' => null,
                'published_at' => $publishedAt,
                'created_at' => $publishedAt,
                'updated_at' => $publishedAt,
            ];
            $winCounts[(int) $winner->employee_id] = ($winCounts[(int) $winner->employee_id] ?? 0) + 1;
        }

        return $rows;
    }

    /**
     * Rule 9 + rule 10: value-ordered groups (best first, ties together), skip a whole
     * group only when every candidate in it is blocked. `beating_the_traffic` orders
     * low-to-high (earliest arrival wins); every other award orders high-to-low.
     *
     * @param  array<int, int>  $winCounts  employee_id => wins so far this run
     * @param  list<int>  $blocked  last month's winner(s) of this exact award
     * @return list<object{employee_id:int, value:float}>
     */
    private function resolveWinners(Collection $candidates, string $key, array $blocked, array $winCounts): array
    {
        if ($candidates->isEmpty()) {
            return [];
        }

        // QA F4: PHP truncates float array keys to int, so grouping by (float) value merged
        // -4.53 and -4.90 into one "tie". Group by the two-decimal string the column holds
        // and order the groups numerically.
        $groups = $candidates->groupBy(fn ($row) => number_format((float) $row->value, 2, '.', ''));
        $groups = $key === 'beating_the_traffic'
            ? $groups->sortBy(fn ($group, $value) => (float) $value)
            : $groups->sortByDesc(fn ($group, $value) => (float) $value);

        foreach ($groups as $group) {
            $eligible = $group->filter(fn ($row) => ! in_array((int) $row->employee_id, $blocked, true)
                && ($winCounts[(int) $row->employee_id] ?? 0) < 2);
            if ($eligible->isNotEmpty()) {
                return $eligible->values()->all();
            }
        }

        return [];
    }

    private function isFirstWorkingDayOfMonth(Carbon $today, DayRules $dayRules): bool
    {
        $day = $today->copy()->startOfMonth();
        while (! $dayRules->isWorkingDay($day)) {
            $day->addDay();
        }

        return $today->isSameDay($day);
    }
}
