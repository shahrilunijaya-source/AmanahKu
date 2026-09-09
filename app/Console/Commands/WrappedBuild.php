<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Employee;
use App\Models\Tenant;
use App\Models\WrappedArc;
use App\Models\WrappedStory;
use App\Support\Awards;
use App\Tenancy\CurrentTenant;
use App\Timesheet\DayRules;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * CR-22 (session S28): every day at 08:00, on the first working day of the month only,
 * build the PREVIOUS month's "Amanahku Wrapped" — one `wrapped_stories` row per active
 * employee with a user, plus one company row (`employee_id` null). Idempotent: a second
 * run for a month that already has rows adds nothing (checked separately for the personal
 * half and the company row, same shape as `AwardsPublish`'s per-month guard).
 *
 * Personal numbers that an award already covers come straight from that month's frozen
 * `award_snapshots` (CR-14/global clause: never a live recount). Numbers no award covers
 * (`best_day`, `helped_people`) and every company number are derived from
 * `App\Support\Awards::creditableCards()` — the same exclusion set and completed-at rule
 * the awards themselves use — read for dates and counts only, never comment/description
 * text.
 */
class WrappedBuild extends Command
{
    protected $signature = 'wrapped:build';

    protected $description = 'Build last month\'s Amanahku Wrapped stories on the first working day of the month.';

    /** Rule => at least this many seeded titles, so 3-per-rule survives one retirement. */
    private const SEEDS_PER_RULE = 6;

    /**
     * @var array<string, list<string>>
     */
    private const SEED_ARCS = [
        'firefighter' => [
            'The Firefighter', 'Crisis Whisperer', 'The Extinguisher', 'Emergency Response Unit',
            'The One Who Runs Toward It', 'Situation Handler', 'The Calm in the Chaos', 'Red Alert Specialist',
        ],
        'helper' => [
            'The Wingman', 'Team Player of the Month', 'The Assist King/Queen', 'Everyone\'s Backup',
            'The Reliable One', 'Behind-the-Scenes MVP', 'The Bridge Builder', 'Quiet Support System',
        ],
        'closer' => [
            'The Closer', 'Machine Mode: Activated', 'The Finisher', 'Done-and-Dusted Champion',
            'The Human Conveyor Belt', 'Unstoppable This Month', 'The Deadline Slayer', 'Volume Dealer',
        ],
        'quiet' => [
            'The Long Game', 'Still Warming Up', 'Building Momentum', 'The Slow Burn',
            'Between Chapters', 'The Quiet Month', 'Regrouping', 'On the Bench, Not Benched',
        ],
        'steady' => [
            'The Steady Hand', 'Consistently Consistent', 'The Reliable Regular', 'Middle of the Story',
            'Just Getting It Done', 'The Even Keel', 'Business as Usual', 'The Dependable One',
        ],
    ];

    public function handle(CurrentTenant $context, DayRules $dayRules, Awards $awards): int
    {
        $today = Carbon::now()->startOfDay();
        $built = 0;

        foreach (Tenant::query()->orderBy('id')->get() as $tenant) {
            $context->set($tenant);

            try {
                $built += $this->buildTenant($today, $dayRules, $awards);
            } catch (\Throwable $e) {
                report($e);
                $this->error("Wrapped build failed for tenant {$tenant->id}: {$e->getMessage()}");
            }
        }

        $context->set(null);
        $this->info("Wrapped stories built: {$built}.");

        return self::SUCCESS;
    }

    private function buildTenant(Carbon $today, DayRules $dayRules, Awards $awards): int
    {
        if (! $this->isFirstWorkingDayOfMonth($today, $dayRules)) {
            return 0;
        }

        $tenantId = app(CurrentTenant::class)->id();
        $month = $today->copy()->subMonthNoOverflow()->startOfMonth();
        $start = $month->copy()->startOfMonth();
        $end = $month->copy()->endOfMonth();

        $this->seedArcsIfNeeded($tenantId);

        $cards = $awards->creditableCards();
        $snapshots = DB::table('award_snapshots')
            ->where('tenant_id', $tenantId)->whereDate('month', $month->toDateString())
            ->get()->groupBy('award_key');
        $doneAndDusted = $snapshots->get('done_and_dusted', collect())->pluck('value', 'employee_id');
        $chiefFirefighter = $snapshots->get('chief_firefighter', collect())->pluck('value', 'employee_id');
        $walkingWikipedia = $snapshots->get('walking_wikipedia', collect())->pluck('value', 'employee_id');

        $arcsByRule = WrappedArc::where('tenant_id', $tenantId)->where('active', true)->get()->groupBy('rule');

        $built = 0;

        $alreadyHasPersonal = WrappedStory::where('tenant_id', $tenantId)
            ->whereDate('month', $month->toDateString())->whereNotNull('employee_id')->exists();

        if (! $alreadyHasPersonal) {
            foreach (Employee::where('status', 'active')->whereNotNull('user_id')->get() as $employee) {
                $cardsClosed = (int) ($doneAndDusted[$employee->id] ?? 0);
                $highPriority = (int) ($chiefFirefighter[$employee->id] ?? 0);
                $lessonsShared = (int) ($walkingWikipedia[$employee->id] ?? 0);
                [$bestDay, $bestDayCount] = $this->bestDay($cards, $employee->id, $start, $end);
                $helpedPeople = $this->helpedPeople($cards, $employee->id, $start, $end);

                $rule = $this->ruleFor($highPriority, $helpedPeople, $cardsClosed);
                $arcTitle = $this->pickArc($arcsByRule, $rule, $employee->id, $month);

                WrappedStory::create([
                    'tenant_id' => $tenantId,
                    'month' => $month->toDateString(),
                    'employee_id' => $employee->id,
                    'cards' => [
                        'cards_closed' => $cardsClosed,
                        'high_priority' => $highPriority,
                        'helped_people' => $helpedPeople,
                        'lessons_shared' => $lessonsShared,
                        'best_day' => $bestDay,
                        'best_day_count' => $bestDayCount,
                    ],
                    'arc_title' => $arcTitle,
                    'built_at' => Carbon::now(),
                ]);
                $built++;
            }
        }

        $alreadyHasCompany = WrappedStory::where('tenant_id', $tenantId)
            ->whereDate('month', $month->toDateString())->whereNull('employee_id')->exists();

        if (! $alreadyHasCompany) {
            $doneInMonth = $cards->filter(fn ($c) => $c->completed_at?->between($start, $end));
            $createdInMonth = $cards->filter(fn ($c) => $c->created_at->between($start, $end));

            WrappedStory::create([
                'tenant_id' => $tenantId,
                'month' => $month->toDateString(),
                'employee_id' => null,
                'cards' => [
                    'cards_closed' => $doneInMonth->count(),
                    'lessons_shared' => (int) $walkingWikipedia->sum(),
                    'fires' => $doneInMonth->where('priority', 'high')->count(),
                    'urgent' => $createdInMonth->where('priority', 'high')->count(),
                ],
                'arc_title' => null,
                'built_at' => Carbon::now(),
            ]);
            $built++;
        }

        return $built;
    }

    /**
     * Weekday (English name) with the most completions this month, and how many cards
     * landed on it; ties go to the earliest weekday in `$order`.
     *
     * @return array{0: string, 1: int}
     */
    private function bestDay(Collection $cards, int $employeeId, Carbon $start, Carbon $end): array
    {
        $order = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

        $counts = $cards
            ->filter(fn ($c) => $c->employee_id === $employeeId && $c->completed_at?->between($start, $end))
            ->countBy(fn ($c) => $c->completed_at->format('l'));

        if ($counts->isEmpty()) {
            return ['', 0];
        }

        $best = $counts->max();
        $day = collect($order)->first(fn ($day) => ($counts[$day] ?? 0) === $best) ?? '';

        return [$day, $best];
    }

    /** Distinct owners of cards this employee helped on, completed this month. */
    private function helpedPeople(Collection $cards, int $employeeId, Carbon $start, Carbon $end): int
    {
        return $cards
            ->filter(fn ($c) => in_array($employeeId, $c->helpers, true) && $c->completed_at?->between($start, $end))
            ->pluck('employee_id')->filter()->unique()->count();
    }

    private function ruleFor(int $highPriority, int $helpedPeople, int $cardsClosed): string
    {
        return match (true) {
            $highPriority >= 3 => 'firefighter',
            $helpedPeople >= 3 => 'helper',
            $cardsClosed >= 10 => 'closer',
            $cardsClosed === 0 => 'quiet',
            default => 'steady',
        };
    }

    /** Deterministic (not random) pick among the rule's active arcs, stable per employee/month. */
    private function pickArc(Collection $arcsByRule, string $rule, int $employeeId, Carbon $month): ?string
    {
        $titles = $arcsByRule->get($rule, collect())->pluck('title')->values();
        if ($titles->isEmpty()) {
            return null;
        }

        $index = crc32("{$employeeId}-{$month->toDateString()}-{$rule}") % $titles->count();

        return $titles[$index];
    }

    private function seedArcsIfNeeded(int $tenantId): void
    {
        if (WrappedArc::where('tenant_id', $tenantId)->exists()) {
            return;
        }

        $rows = [];
        $now = Carbon::now();
        foreach (self::SEED_ARCS as $rule => $titles) {
            foreach (array_slice($titles, 0, self::SEEDS_PER_RULE) as $title) {
                $rows[] = [
                    'tenant_id' => $tenantId, 'title' => $title, 'rule' => $rule, 'active' => true,
                    'created_at' => $now, 'updated_at' => $now,
                ];
            }
        }

        DB::table('wrapped_arcs')->insert($rows);
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
