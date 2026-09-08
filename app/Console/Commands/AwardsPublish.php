<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AppNotification;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Tenant;
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

        $publishedAt = Carbon::now();
        $winCounts = [];
        $rows = [];

        foreach (Awards::KEYS as $key) {
            $winners = $this->resolveWinners($snapshot->get($key, collect()), $key, $previousWinners->get($key, []), $winCounts);
            foreach ($winners as $row) {
                $rows[] = [
                    'tenant_id' => $tenantId,
                    'month' => $month->toDateString(),
                    'award_key' => $key,
                    'employee_id' => (int) $row->employee_id,
                    'value' => $row->value,
                    'label' => $row->label,
                    'source' => 'auto',
                    'reason' => null,
                    'published_at' => $publishedAt,
                    'created_at' => $publishedAt,
                    'updated_at' => $publishedAt,
                ];
                $winCounts[(int) $row->employee_id] = ($winCounts[(int) $row->employee_id] ?? 0) + 1;
            }
        }

        if ($rows !== []) {
            DB::table('award_results')->insert($rows);
        }

        AuditLog::record('awards.published', $month->toDateString());
        AppNotification::sendMany(
            Employee::where('tenant_id', $tenantId)->whereNotNull('user_id')->pluck('user_id'),
            $month->format('F Y').' awards are out!',
            count($rows) > 0 ? 'See who won this month.' : 'No awards were eligible for a winner this month.',
            '/app/awards',
            "award-publish-{$month->toDateString()}",
        );

        return count($rows);
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

        $groups = $candidates->groupBy(fn ($row) => (float) $row->value);
        $groups = $key === 'beating_the_traffic' ? $groups->sortKeys() : $groups->sortKeysDesc();

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
