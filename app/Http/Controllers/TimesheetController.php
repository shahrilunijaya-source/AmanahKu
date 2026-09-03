<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Attendance\ReportPeriod;
use App\Console\Commands\TimesheetReminder;
use App\Models\AppNotification;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Project;
use App\Models\SubPillar;
use App\Models\Timesheet;
use App\Models\TimesheetCategory;
use App\Models\TimesheetEntry;
use App\Models\WorkItem;
use App\Services\DataScope;
use App\Services\MandayRateService;
use App\Support\Permissions;
use App\Tenancy\CurrentTenant;
use App\Timesheet\BoardSuggestions;
use App\Timesheet\LockedDays;
use App\Timesheet\TimesheetCompliance;
use App\Timesheet\WeekWriter;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class TimesheetController extends Controller
{
    public function __construct(private WeekWriter $weekWriter) {}

    /**
     * Roles allowed to see salary-derived RM cost: management (directors included, via
     * effectiveRole) and HR. Line managers and plain employees never see money — only
     * time in person-days, their own or their team's.
     */
    private const MONEY_ROLES = ['management', 'hr'];

    /** True when this role may see salary-derived RM. Collapses director → management. */
    private function canSeeCost(string $role): bool
    {
        return in_array(Permissions::effectiveRole($role), self::MONEY_ROLES, true);
    }

    /**
     * How far back a staffer may still edit — kept on WeekWriter now (single source, since
     * it also enforces the window). Six weeks, widened from three: three left no room for a
     * fortnight of sick leave or a stretch of travel, and a week that falls out of the window
     * cannot be recovered — there is no per-week override for HR to grant.
     */
    private const BACKFILL_WEEKS = WeekWriter::BACKFILL_WEEKS;

    /**
     * Build the timesheets screen data. Tenant scope is automatic via BelongsToTenant.
     *
     * Staff allocate each week by PERCENTAGE across a per-day grid; every populated
     * day must total 100% before it can be submitted. The grid targets one selectable
     * week (?week=YYYY-MM-DD, default this week) and prefills from an existing draft.
     *
     * A week ends at 'submitted'. Nothing approves a timesheet, by design: the all-staff
     * view is a report, not an approval queue.
     *
     * Manday RM cost (hours derived from percentage) is layered on for HR & management
     * only, so line managers and staff log their week without seeing salary-derived cost.
     *
     * @return array<string, mixed>
     */
    public function screenData(Request $request, ?Employee $employee): array
    {
        $role = $request->attributes->get('tenantRole', 'employee');
        $canSeeCost = $this->canSeeCost($role);

        $weekStart = $request->filled('week')
            ? Carbon::parse($request->query('week'))->startOfWeek()
            : Carbon::now()->startOfWeek();

        // Per-user switch: off drops BoardSuggestions entirely and the screen falls back
        // to its own Add line button. Defaults true both for a request with no user at
        // all (screenData() is also exercised by callers that never resolve a user) and
        // for a user whose attribute reads null — the column is NOT NULL DEFAULT true at
        // the DB level, but an in-memory model built via create() and handed straight to
        // actingAs() in tests never gets that DB default backfilled onto it.
        $tsFillFromBoard = $request->user() ? (bool) ($request->user()->timesheet_fill_from_board ?? true) : true;

        $lockedDays = app(LockedDays::class);
        $locked = $employee ? $lockedDays->forWeek($employee, $weekStart) : [];

        $with = ['entries.category', 'entries.projectRef', 'entries.subPillar', 'entries.workItem', 'employee.positionBand'];

        $myTimesheets = $employee
            ? Timesheet::with($with)->where('employee_id', $employee->id)->latest('week_start')->get()
            : new Collection;

        // Cost map keyed by timesheet id (null when the owner has no position band).
        // Built only for money roles, so a plain employee's payload never carries RM.
        $timesheetCosts = [];
        if ($canSeeCost) {
            $rates = app(MandayRateService::class);
            foreach ($myTimesheets as $t) {
                $timesheetCosts[$t->id] = $rates->timesheetCost($t);
            }
        }

        // Week-by-week view of the signed-in staff's own entries (Review tab): every
        // week they have a Timesheet row for, draft or submitted, entries grouped by
        // day. No date bound — one person's data, small by construction.
        $myTimesheetsByWeekStart = $myTimesheets->keyBy(fn (Timesheet $t) => $t->week_start->toDateString());
        $myWeeks = $employee ? $this->buildWeekBlocks($myTimesheets->flatMap->entries, $myTimesheetsByWeekStart) : [];

        // The timesheet (if any) for the selected capture week, and its grid prefill.
        $weekTimesheet = $employee
            ? $myTimesheets->first(fn (Timesheet $t) => $t->week_start->isSameDay($weekStart))
            : null;

        $existingGrid = [];
        if ($weekTimesheet) {
            foreach ($weekTimesheet->entries as $e) {
                if ($e->source !== null) {
                    continue;
                }

                $existingGrid[$e->entry_date->toDateString()][] = [
                    'id' => $e->id,
                    // The board card's own name, so the line reads as the work it was
                    // rather than as its category. Derived from the relation, never
                    // stored on the entry: the three places that delete-and-recreate
                    // entries would each have to remember to carry a copy.
                    'title' => (string) ($e->workItem->title ?? ''),
                    'category_id' => $e->category_id,
                    'project_id' => $e->project_id,
                    'sub_pillar_id' => $e->sub_pillar_id,
                    'percentage' => (float) $e->percentage,
                    'description' => $e->description ?? '',
                    'work_item_id' => $e->work_item_id,
                ];
            }
        }

        // Rows proposed from the board's In Progress and In Review cards, one per card
        // per day it was worked. With no Add button on the capture screen, this is the
        // only way work reaches a timesheet: nothing is stored until the staffer gives a
        // row a percentage and saves. A failure here must never take the screen down —
        // an empty map just means the grid opens the way it always did.
        $tsSuggested = [];
        if ($tsFillFromBoard) {
            try {
                $tsSuggested = $employee ? app(BoardSuggestions::class)->forWeek($employee, $weekStart) : [];
            } catch (\Throwable $e) {
                report($e);
                $tsSuggested = [];
            }
        }

        return [
            'myTimesheets' => $myTimesheets,
            'canSeeCost' => $canSeeCost,
            'timesheetCosts' => $timesheetCosts,
            // Prompt HR to assign a band when the signed-in money-role user has none.
            'positionMissing' => $canSeeCost && $employee && ! $employee->position_id,
            // Week-by-week view of the signed-in staff's own entries (Review tab).
            'myWeeks' => $myWeeks,
            // Capture grid inputs.
            'tsCategories' => $this->categoryOptions(
                collect($existingGrid)->flatten(1)->pluck('category_id')->filter()->map(fn ($id) => (int) $id)->unique()->all(),
            ),
            'tsProjects' => $this->projectOptions(),
            'weekStart' => $weekStart->toDateString(),
            'weekLabel' => $weekTimesheet?->week_label ?? '',
            'weekStatus' => $weekTimesheet?->status,
            'weekTimesheet' => $weekTimesheet,
            'existingGrid' => $existingGrid,
            // Day-first capture screen inputs (Tasks 7-8).
            'tsLocked' => $locked,
            'tsSuggested' => $tsSuggested,
            'tsSubPillars' => SubPillar::where('is_active', true)->orderBy('sort')->orderBy('name')->get(['id', 'name']),
            // The struck-off-card list is a board-prefill concept — nothing to strike off
            // when the prefill itself is switched off.
            'tsDismissed' => $tsFillFromBoard ? $this->dismissedRows($weekTimesheet) : [],
            'tsToday' => Carbon::now()->toDateString(),
            'tsEarliestWeek' => Carbon::now()->startOfWeek()->subWeeks(self::BACKFILL_WEEKS)->toDateString(),
            'tsFillFromBoard' => $tsFillFromBoard,
        ];
    }

    /**
     * Save (or refresh) the selected week as a draft from the per-day grid. The grid
     * is authoritative for the whole week, so existing entries are replaced. Optionally
     * submit in the same request (submit_now) once every populated day totals 100%.
     */
    public function store(Request $request): RedirectResponse|JsonResponse
    {
        $employee = $request->attributes->get('employee');
        abort_unless($employee, 403, 'No employee profile in this workspace.');
        $tid = app(CurrentTenant::class)->id();

        // A category a draft already carries stays choosable for THAT line even after
        // it is retired — same reasoning as categoryOptions($keepIds): a resave of an
        // unchanged row must not suddenly fail on a category nobody just picked. A new
        // line may not choose a retired category. Read via WeekWriter's own accessor
        // (whereNull('source')) so this and the actual save agree on what "this draft's
        // rows" means; a bad week_start just yields no kept ids, caught by the 'date'
        // rule below.
        $keepCategoryIds = [];
        try {
            if ($request->filled('week_start')) {
                $keepCategoryIds = collect($this->weekWriter->existingUserEntries($employee, $request->input('week_start')))
                    ->pluck('category_id')->filter()->map(fn ($id) => (int) $id)->unique()->all();
            }
        } catch (\Throwable) {
            // Bad week_start is reported by the 'date' rule right below instead.
        }

        $data = $request->validate([
            'week_start' => ['required', 'date'],
            'week_label' => ['nullable', 'string', 'max:60'],
            'submit_now' => ['nullable', 'boolean'],
            'entries' => ['present', 'array'],
            'entries.*.entry_date' => ['required', 'date'],
            'entries.*.category_id' => ['required', 'integer', Rule::exists('timesheet_categories', 'id')->where(
                fn ($q) => $q->where('tenant_id', $tid)->where(
                    fn ($q2) => $q2->where('is_active', true)->when($keepCategoryIds !== [], fn ($q3) => $q3->orWhereIn('id', $keepCategoryIds))
                )
            )],
            'entries.*.project_id' => ['nullable', 'integer', Rule::exists('projects', 'id')->where('tenant_id', $tid)],
            'entries.*.sub_pillar_id' => ['nullable', 'integer', Rule::exists('sub_pillars', 'id')->where('tenant_id', $tid)],
            // 0 is legal in a DRAFT: a line the staffer has added but not yet costed must
            // survive a reload, or it disappears the moment the page is refreshed and they
            // are never told. assertNoBlankLines() blocks 0 at submit, so a submitted week
            // still never carries a 0% entry into the cost report.
            'entries.*.percentage' => ['required', 'numeric', 'min:0', 'max:100'],
            'entries.*.description' => ['nullable', 'string', 'max:10000'],
            'entries.*.work_item_id' => ['nullable', 'integer'],
            // Board cards the staffer struck off a day, keyed by ISO date. Absent means
            // "this caller knows nothing about dismissals" — see WeekWriter::save().
            'dismissed' => ['nullable', 'array'],
            'dismissed.*' => ['array'],
            'dismissed.*.*' => ['integer'],
        ]);

        $dismissed = $request->has('dismissed')
            ? $this->cleanDismissed($data['dismissed'] ?? [], $data['week_start'], $employee)
            : null;

        $submitNow = $request->boolean('submit_now');

        $result = $this->weekWriter->save($employee, $data['week_start'], $data['entries'], $data['week_label'] ?? null, $submitNow, $dismissed);
        $timesheet = $result['timesheet'];
        $entries = $result['entries'];
        $locked = $result['locked'];

        $message = $submitNow
            ? 'Timesheet submitted.'
            : 'Draft saved — '.count($entries).' '.(count($entries) === 1 ? 'entry' : 'entries').'.';

        if ($submitNow) {
            AuditLog::record('Submitted timesheet', ($timesheet->week_label ?: $timesheet->week_start->toDateString()).' · '.count($entries).' entries');
        }

        // The day-first screen autosaves over fetch(); the plain form POST still redirects.
        if ($request->expectsJson()) {
            return response()->json([
                'ok' => true,
                'status' => $timesheet->status,
                'locked' => $locked,
            ]);
        }

        return back()->with('ok', $message);
    }

    /**
     * The staffer's own switch for the capture screen's board prefill (default true).
     * Off drops BoardSuggestions from screenData() entirely and hands the day-first
     * screen its own Add line button instead. Per-user, not per-tenant: it lives on
     * User, not Employee, the same place dashboard_prefs does.
     */
    public function preferences(Request $request): RedirectResponse|JsonResponse
    {
        $data = $request->validate([
            'fill_from_board' => ['required', 'boolean'],
        ]);

        $request->user()->forceFill(['timesheet_fill_from_board' => $data['fill_from_board']])->save();

        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'fill_from_board' => (bool) $data['fill_from_board']]);
        }

        return back();
    }

    /**
     * The cards struck off each day of this week, with enough of the card left to offer
     * them back: the capture screen shows them greyed under the day with a Restore link,
     * so a mis-tapped remove is one click to undo rather than a trip to the board.
     *
     * @return array<string, array<int, array{work_item_id:int, title:string, category_id:?int, project_id:?int, description:string}>>
     */
    private function dismissedRows(?Timesheet $timesheet): array
    {
        $stored = $timesheet === null ? [] : ($timesheet->dismissed_suggestions ?? []);

        $cards = WorkItem::whereIn('id', collect($stored)->flatten()->unique()->all())
            ->get(['id', 'title', 'project_id', 'timesheet_category_id'])
            ->keyBy('id');

        $categories = app(BoardSuggestions::class)->categoryFor($cards);

        $out = [];
        foreach ($stored as $iso => $ids) {
            foreach ((array) $ids as $id) {
                $card = $cards[(int) $id] ?? null;

                if ($card === null) {
                    continue;
                }

                $out[(string) $iso][] = [
                    'work_item_id' => (int) $card->id,
                    'title' => $card->title,
                    'category_id' => $categories[(int) $card->id] ?? null,
                    'project_id' => $card->project_id ? (int) $card->project_id : null,
                    // The note is the staffer's to write. The card's title names the line
                    // on its own now, and the card's description is spec text, not a note.
                    'description' => '',
                ];
            }
        }

        return $out;
    }

    /**
     * Keep only dismissals this staffer may actually make: dates inside the week being
     * saved, and cards that are theirs. A dismissal hides work from the cost report by
     * keeping it off the grid, so it is a claim like any other and gets the same guard
     * the entries themselves get.
     *
     * @param  array<string, array<int, int>>  $dismissed
     * @return array<string, array<int, int>>
     */
    private function cleanDismissed(array $dismissed, string $weekStart, Employee $employee): array
    {
        $start = Carbon::parse($weekStart)->startOfDay();
        $ids = collect($dismissed)->flatten()->map(fn ($id) => (int) $id)->unique();

        $allowed = $ids->isEmpty() ? [] : WorkItem::whereIn('id', $ids)
            ->where(fn ($q) => $q->where('employee_id', $employee->id)
                ->orWhereHas('participants', fn ($p) => $p->where('employees.id', $employee->id)))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $out = [];
        foreach ($dismissed as $iso => $cardIds) {
            // The keys are dates the browser put there, so garbage is possible: a bad key
            // is dropped, not allowed to 500 the save behind it.
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $iso)) {
                continue;
            }

            $date = Carbon::parse((string) $iso)->startOfDay();

            if ($date->lt($start) || $date->gt($start->copy()->addDays(6))) {
                continue;
            }

            $kept = array_values(array_unique(array_filter(
                array_map(fn ($id) => (int) $id, (array) $cardIds),
                fn (int $id) => in_array($id, $allowed, true),
            )));

            if ($kept !== []) {
                $out[$date->toDateString()] = $kept;
            }
        }

        return $out;
    }

    /**
     * Put a submitted week back into draft so its owner can fix it.
     *
     * Nothing approves timesheets today, so there is no decision to invalidate. This exists
     * because submit was otherwise irreversible: a typo could only be undone in the database.
     */
    public function recall(Request $request, Timesheet $timesheet): RedirectResponse
    {
        $this->authorizeOwner($request, $timesheet);
        abort_unless($timesheet->status === 'submitted', 422, 'Only a submitted week can be recalled.');

        $timesheet->update(['status' => 'draft', 'submitted_at' => null]);
        AuditLog::record('Recalled timesheet', $timesheet->week_label ?: $timesheet->week_start->toDateString());

        return back()->with('ok', 'Week reopened. Fix it and submit again.');
    }

    // ---- Reports ----------------------------------------------------------

    /**
     * Allocation + cost reports for managers, management and HR: where staff time and
     * money went over a period — by category (e.g. Study, Leave), by project, and by
     * person. Only submitted + approved timesheets count. A row's percentage is a share
     * of one day, so percentage/100 = person-days. RM cost = entry hours * the owner's
     * manhour rate; time with no salary band is reported as uncosted, never as zero.
     *
     * Optional ?category= and ?project= filters narrow the whole report to one slice
     * (answering "how much did we spend on Study this month", or "on project X").
     *
     * @return array<string, mixed>
     */
    public function reportData(Request $request, ?Employee $employee): array
    {
        $role = $request->attributes->get('tenantRole', 'employee');
        $canSeeCost = $this->canSeeCost($role);

        $period = $this->periodFromRequest($request);
        $from = Carbon::parse($period->from->toDateString())->startOfDay();
        /* ReportPeriod clamps its end to today, which is right for attendance — a
           future working day with no punch is not an absence. A timesheet period is
           not the same shape: the weeks-in/weeks-missing figures are counted per whole
           week, and a range somebody typed themselves should mean what they typed. So
           the window ends where the granularity says it ends, unclamped. */
        $to = match ($period->gran) {
            'week' => $from->copy()->addDays(4)->endOfDay(),
            'custom' => Carbon::parse((string) $request->query('to', $period->to->toDateString()))->endOfDay(),
            default => $from->copy()->endOfMonth()->endOfDay(),
        };
        if ($to->lt($from)) {
            $to = $from->copy()->endOfDay();
        }

        $categoryId = $request->integer('category') ?: null;
        $projectId = $request->integer('project') ?: null;
        $dept = $request->query('dept') ?: null;
        $q = trim((string) $request->query('q', ''));

        // Data scope: a branch/department-restricted manager only sees their slice of the
        // (money-sensitive) timesheet cost report (AK-AUTHZ-01). null = 'company', no limit.
        $scope = $request->attributes->get('tenantScope', 'company');
        $visibleIds = app(DataScope::class)->visibleEmployeeIds($scope, $employee);

        $entries = TimesheetEntry::with(['category', 'projectRef', 'subPillar', 'workItem', 'timesheet.employee.positionBand'])
            ->whereBetween('entry_date', [$from->toDateString(), $to->toDateString()])
            ->whereHas('timesheet', fn ($t) => $t->where('status', 'submitted')
                // archived owners' entries drop from RM totals
                ->whereHas('employee', fn ($e) => $e->active()
                    ->when($dept, fn ($b) => $b->whereHas('department', fn ($d) => $d->where('name', $dept)))
                    ->when($q !== '', fn ($b) => $b->where('name', 'like', '%'.$q.'%')))
                ->when($visibleIds !== null, fn ($t2) => $t2->whereIn('employee_id', $visibleIds)))
            ->when($categoryId, fn ($q) => $q->where('category_id', $categoryId))
            ->when($projectId, fn ($q) => $q->where('project_id', $projectId))
            ->get()
            ->filter(fn (TimesheetEntry $e) => $e->timesheet && $e->timesheet->employee);

        // Attach per-entry RM cost (null when the owner has no salary band — uncosted).
        $rates = app(MandayRateService::class);
        foreach ($entries as $e) {
            $band = $e->timesheet->employee->positionBand;
            $e->cost = $band ? $rates->entryCost($e, $band) : null;
        }

        $days = fn (Collection $rows) => round($rows->sum(fn ($e) => (float) $e->percentage) / 100, 2);
        $cost = fn (Collection $rows) => round($rows->sum(fn ($e) => (float) ($e->cost ?? 0)), 2);

        $grandDays = $days($entries);
        $grandCost = $cost($entries);
        $uncostedDays = round($entries->filter(fn ($e) => $e->cost === null)->sum(fn ($e) => (float) $e->percentage) / 100, 2);

        $periodWeeks = [];
        $cur = $from->copy()->startOfDay();
        if (! $cur->isMonday()) {
            $cur->next(Carbon::MONDAY);
        }
        while ($cur->lte($to)) {
            $periodWeeks[] = $cur->copy();
            $cur->addWeek();
        }
        $periodWeeksCount = count($periodWeeks);
        $periodWeekStarts = array_map(fn (Carbon $w) => $w->toDateString(), $periodWeeks);

        $visibleEmployees = Employee::active()
            ->when($visibleIds !== null, fn ($b) => $b->whereIn('id', $visibleIds))
            ->when($dept, fn ($b) => $b->whereHas('department', fn ($d) => $d->where('name', $dept)))
            ->when($q !== '', fn ($b) => $b->where('name', 'like', '%'.$q.'%'))
            ->get();

        $submittedWeekStartsByEmployee = [];
        if (! empty($periodWeekStarts) && $visibleEmployees->isNotEmpty()) {
            $submittedTs = Timesheet::whereIn('employee_id', $visibleEmployees->pluck('id'))
                ->whereBetween('week_start', [
                    $from->copy()->startOfWeek()->toDateString(),
                    $to->copy()->endOfWeek()->toDateString(),
                ])
                ->where('status', 'submitted')
                ->get(['employee_id', 'week_start']);

            foreach ($submittedTs as $t) {
                $submittedWeekStartsByEmployee[$t->employee_id][$t->week_start->toDateString()] = true;
            }
        }

        $empWeekStats = function (Employee $emp) use ($periodWeeks, $submittedWeekStartsByEmployee) {
            $weeksIn = 0;
            $missingWeeks = [];
            $joinDateStr = $emp->joined_at?->toDateString();

            foreach ($periodWeeks as $w) {
                $wStr = $w->toDateString();
                if ($joinDateStr !== null && $wStr < $joinDateStr) {
                    continue;
                }

                $weekLabel = 'Week '.$w->isoWeek;
                if (isset($submittedWeekStartsByEmployee[$emp->id][$wStr])) {
                    $weeksIn++;
                } else {
                    $missingWeeks[] = $weekLabel;
                }
            }

            $weeksTotal = $weeksIn + count($missingWeeks);

            return [
                'weeksIn' => $weeksIn,
                'weeksTotal' => $weeksTotal,
                'missingWeeks' => $missingWeeks,
            ];
        };

        $empStatsMap = [];
        $weeksNotIn = 0;
        foreach ($visibleEmployees as $vEmp) {
            $stats = $empWeekStats($vEmp);
            $empStatsMap[$vEmp->id] = $stats;
            $weeksNotIn += count($stats['missingWeeks']);
        }

        // ----- Lens category: category -> days + RM + members -----
        $lensCategory = $entries->groupBy(fn ($e) => $e->category_id ?? 'uncategorised')
            ->map(function (Collection $rows) use ($days, $cost, $grandDays) {
                $first = $rows->first();
                $catId = $first->category ? (int) $first->category->id : 'uncategorised';
                $label = (string) ($first->category?->name ?? 'Uncategorised');
                $sliceDays = $days($rows);

                $members = $rows->groupBy(fn ($e) => $e->timesheet->employee_id)
                    ->map(function (Collection $empRows) use ($sliceDays, $days, $cost) {
                        $emp = $empRows->first()->timesheet->employee;
                        $empDays = $days($empRows);

                        return [
                            'id' => (int) $emp->id,
                            'name' => (string) $emp->display_name,
                            'initials' => (string) $emp->initials,
                            'color' => (string) ($emp->avatar_color ?? config('amanahku.avatar_color')),
                            'days' => $empDays,
                            'cost' => $cost($empRows),
                            'pct' => $sliceDays > 0 ? (int) round($empDays / $sliceDays * 100) : 0,
                        ];
                    })->values()->sortByDesc('days')->values()->all();

                return [
                    'id' => $catId,
                    'label' => $label,
                    'days' => $sliceDays,
                    'cost' => $cost($rows),
                    'pct' => $grandDays > 0 ? (int) round($sliceDays / $grandDays * 100) : 0,
                    'members' => $members,
                ];
            })->values()->sortByDesc('cost')->sortByDesc('days')->values()->all();

        // ----- Lens project: project -> days + RM + members -----
        $lensProject = $entries->filter(fn ($e) => $e->projectRef)
            ->groupBy(fn ($e) => $e->project_id)
            ->map(function (Collection $rows) use ($days, $cost, $grandDays) {
                $proj = $rows->first()->projectRef;
                $sliceDays = $days($rows);

                $members = $rows->groupBy(fn ($e) => $e->timesheet->employee_id)
                    ->map(function (Collection $empRows) use ($sliceDays, $days, $cost) {
                        $emp = $empRows->first()->timesheet->employee;
                        $empDays = $days($empRows);

                        return [
                            'id' => (int) $emp->id,
                            'name' => (string) $emp->display_name,
                            'initials' => (string) $emp->initials,
                            'color' => (string) ($emp->avatar_color ?? config('amanahku.avatar_color')),
                            'days' => $empDays,
                            'cost' => $cost($empRows),
                            'pct' => $sliceDays > 0 ? (int) round($empDays / $sliceDays * 100) : 0,
                        ];
                    })->values()->sortByDesc('days')->values()->all();

                return [
                    'id' => (int) $proj->id,
                    'label' => (string) $proj->name,
                    'days' => $sliceDays,
                    'cost' => $cost($rows),
                    'pct' => $grandDays > 0 ? (int) round($sliceDays / $grandDays * 100) : 0,
                    'members' => $members,
                ];
            })->values()->sortByDesc('cost')->sortByDesc('days')->values()->all();

        // ----- Lens staff: person -> days + RM + rate -----
        $lensStaff = $entries->groupBy(fn ($e) => $e->timesheet->employee_id)
            ->map(function (Collection $rows) use ($days, $cost, $grandDays, $empStatsMap, $empWeekStats) {
                $emp = $rows->first()->timesheet->employee;
                $positionBand = $emp->positionBand;
                $rate = $positionBand?->mandayRate();
                $d = $days($rows);
                $stats = $empStatsMap[$emp->id] ?? $empWeekStats($emp);

                return [
                    'id' => (int) $emp->id,
                    'name' => (string) $emp->display_name,
                    'initials' => (string) $emp->initials,
                    'color' => (string) ($emp->avatar_color ?? config('amanahku.avatar_color')),
                    'title' => (string) ($positionBand?->title ?? ''),
                    'rate' => $rate,
                    'costed' => $rate !== null,
                    'days' => $d,
                    'cost' => $cost($rows),
                    'pct' => $grandDays > 0 ? (int) round($d / $grandDays * 100) : 0,
                    'weeksIn' => $stats['weeksIn'],
                    'weeksTotal' => $stats['weeksTotal'],
                    'missingWeeks' => $stats['missingWeeks'],
                ];
            })->values()->sortByDesc('cost')->sortByDesc('days')->values()->all();

        // ----- Staff weeks: person -> list of week blocks -----
        $staffWeeks = [];
        $entriesByEmployee = $entries->groupBy(fn ($e) => (int) $e->timesheet->employee_id);
        foreach ($entriesByEmployee as $empId => $empEntries) {
            $staffWeeks[$empId] = $this->buildWeekBlocks($empEntries);
        }

        $tsRoster = app(TimesheetCompliance::class)
            ->roster(app(CurrentTenant::class)->get(), Carbon::now()->startOfWeek(), $visibleIds);
        $weekKey = Carbon::now()->startOfWeek()->toDateString();
        $ids = $tsRoster->pluck('employee.id');
        $keys = $ids->map(fn ($id) => "timesheet-nudge:{$id}:{$weekKey}");
        $nudgedDedupeKeys = AppNotification::whereIn('dedupe_key', $keys)->pluck('dedupe_key')->all();
        $tsNudged = $ids->filter(fn ($id) => in_array("timesheet-nudge:{$id}:{$weekKey}", $nudgedDedupeKeys, true))
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            // Period controls, the same shape the attendance ledger's bar reads.
            'gran' => $period->gran,
            'offset' => $period->offset,
            'canPrev' => $period->canPrev,
            'canNext' => $period->canNext,
            'periodLabel' => ['en' => $period->label('en'), 'ms' => $period->label('ms')],
            // People filters. Scoped to this tab: the chase roster below is always the
            // current week for everyone, which is a different question.
            'departments' => Department::orderBy('name')->pluck('name'),
            'dept' => $dept,
            'q' => $q,
            'canSeeCost' => $canSeeCost,
            'lensCategory' => $lensCategory,
            'lensProject' => $lensProject,
            'lensStaff' => $lensStaff,
            'staffWeeks' => $staffWeeks,
            'reportTotals' => [
                'days' => $grandDays,
                'cost' => $grandCost,
                'uncostedDays' => $uncostedDays,
                'weeksTotal' => $periodWeeksCount,
                'weeksNotIn' => $weeksNotIn,
            ],
            'reportEmpty' => $entries->isEmpty(),
            // This-week compliance roster (who still owes a sheet). Lives here on the
            // all-staff oversight surface, not the personal capture screen. Always the
            // current week, independent of the from/to report period above.
            'tsRoster' => $tsRoster,
            'tsDeadline' => app(TimesheetCompliance::class)->deadline(Carbon::now()->startOfWeek()),
            'tsWeekStart' => Carbon::now()->startOfWeek()->toDateString(),
            'tsNudged' => $tsNudged,
            // Filter dropdown options + current selection.
            'filterCategories' => TimesheetCategory::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'filterProjects' => Project::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'selCategory' => $categoryId,
            'selProject' => $projectId,
        ];
    }

    public function nudge(Request $request, Employee $employee): RedirectResponse
    {
        $this->authorizeTenantRole($request, ['management', 'hr']);

        abort_unless($employee->tenant_id === app(CurrentTenant::class)->id(), 403);

        $scope = $request->attributes->get('tenantScope', 'company');
        $actor = $request->attributes->get('employee');
        $visibleIds = app(DataScope::class)->visibleEmployeeIds($scope, $actor);
        if ($visibleIds !== null) {
            abort_unless(in_array($employee->id, $visibleIds, true), 403);
        }

        abort_if($employee->user_id === null, 422, 'Employee has no user account.');

        $weekStart = Carbon::now()->startOfWeek()->toDateString();
        $dedupeKey = "timesheet-nudge:{$employee->id}:{$weekStart}";

        AppNotification::send(
            $employee->user_id,
            TimesheetReminder::TITLE,
            TimesheetReminder::BODY,
            route('app.screen', 'timesheets'),
            $dedupeKey,
            mail: true,
        );

        return back()->with('ok', "Reminded {$employee->name}.");
    }

    /**
     * One staff member's recent weeks, read-only, as an HTML fragment for the report
     * screen's "This week" tab.
     *
     * The chase list is by definition people who have NOT submitted, so this reads
     * drafts as well as submitted sheets — an empty draft is exactly the thing the
     * person chasing needs to see. It carries no RM: chasing a missing sheet is not a
     * money question, and the fragment is one employee wide, so leaving cost out keeps
     * it clear of the salary-band gate entirely.
     */
    public function personWeeks(Request $request, Employee $employee): View
    {
        $this->authorizeTenantRole($request, ['management', 'hr']);

        abort_unless($employee->tenant_id === app(CurrentTenant::class)->id(), 403);

        $scope = $request->attributes->get('tenantScope', 'company');
        $actor = $request->attributes->get('employee');
        $visibleIds = app(DataScope::class)->visibleEmployeeIds($scope, $actor);
        if ($visibleIds !== null) {
            abort_unless(in_array($employee->id, $visibleIds, true), 403);
        }

        $weekStart = $request->filled('week')
            ? Carbon::parse($request->query('week'))->startOfWeek()
            : Carbon::now()->startOfWeek();

        // Eight weeks back, so the viewer's own prev/next has somewhere to step without
        // a round trip per step. buildWeekBlocks() returns them oldest-first and the
        // Alpine component opens on the last one, which is the week asked for.
        $timesheets = Timesheet::with(['entries.category', 'entries.projectRef', 'entries.subPillar', 'entries.workItem'])
            ->where('employee_id', $employee->id)
            // Half-open upper bound, not whereBetween: the date cast stores a 00:00:00
            // time on sqlite, which sorts after the bare date string and drops the very
            // week asked for. Same reason Timesheet::scopeForWeek() avoids whereDate().
            ->where('week_start', '>=', $weekStart->copy()->subWeeks(7)->toDateString())
            ->where('week_start', '<', $weekStart->copy()->addDay()->toDateString())
            ->get();

        /* The week asked for is always a block, even when no sheet was ever started
           for it — a null entry under its key is enough for buildWeekBlocks() to make
           one. Without it the viewer opens on whatever week last had entries, which on
           a chase list is weeks old and reads as if that were the current position. */
        $byWeekStart = $timesheets->keyBy(fn (Timesheet $t) => $t->week_start->toDateString());
        $byWeekStart->put($weekStart->toDateString(), $byWeekStart->get($weekStart->toDateString()));

        return view('partials.timesheet-report.person-weeks', [
            'person' => $employee,
            'weeks' => $this->buildWeekBlocks($timesheets->flatMap->entries, $byWeekStart),
        ]);
    }

    /**
     * Group one person's timesheet entries into week blocks: label, date range, totals,
     * and each day's lines. Shared by the all-staff report (reportData(), one call per
     * employee, no $timesheetsByWeekStart — status stays null, unused there) and the
     * personal Review tab (screenData(), one call for the signed-in employee, with
     * $timesheetsByWeekStart so a week with a Timesheet row but zero entries yet still
     * gets a block).
     *
     * @param  Collection<int, TimesheetEntry>  $entries
     * @param  Collection<string, Timesheet>|null  $timesheetsByWeekStart  keyed by week_start date string
     * @return array<int, array{label: string, dates: string, weekStart: string, status: ?string, days: float, cost: float, lines: array}>
     */
    private function buildWeekBlocks(Collection $entries, ?Collection $timesheetsByWeekStart = null): array
    {
        $byWeek = $entries->groupBy(fn (TimesheetEntry $e) => Carbon::parse($e->entry_date)->startOfWeek()->toDateString());

        $weekStartStrs = $timesheetsByWeekStart
            ? $byWeek->keys()->merge($timesheetsByWeekStart->keys())->unique()->sort()
            : $byWeek->keys()->sort();

        $weekBlocks = [];
        foreach ($weekStartStrs as $weekStartStr) {
            $weekEntries = $byWeek->get($weekStartStr, collect());
            $weekStart = Carbon::parse($weekStartStr);
            $mon = $weekStart->copy();
            $fri = $weekStart->copy()->addDays(4);

            $dates = $mon->month === $fri->month
                ? $mon->format('j').' – '.$fri->format('j M')
                : $mon->format('j M').' – '.$fri->format('j M');

            $sortedLines = $weekEntries->sort(function (TimesheetEntry $a, TimesheetEntry $b) {
                $dateCmp = $a->entry_date->toDateString() <=> $b->entry_date->toDateString();
                if ($dateCmp !== 0) {
                    return $dateCmp;
                }
                $aDays = round((float) $a->percentage / 100, 2);
                $bDays = round((float) $b->percentage / 100, 2);

                return $bDays <=> $aDays;
            });

            $lines = [];
            foreach ($sortedLines as $e) {
                $category = $e->category?->name ?: $e->project;
                $project = implode(' · ', array_filter([$e->projectRef?->name, $e->subPillar?->name]));

                $lines[] = [
                    'id' => $e->id,
                    'day' => $e->entry_date->format('D j M'),
                    // label: pre-joined "category · project · sub-pillar" for the all-staff
                    // report's single-line rows. category/project: the same parts kept
                    // separate, for the Review tab's two-pill rows.
                    'label' => implode(' · ', array_filter([$category, $project])),
                    'category' => $category ?: null,
                    // The category's own colour, so a week reads by category at a glance
                    // and matches the dot in the capture picker and the pill on the
                    // Projects register. Null when the line has no category row (a
                    // free-text project), which renders as the plain neutral pill.
                    'categoryColour' => $e->category?->colour(),
                    'project' => $project ?: null,
                    'card' => (string) ($e->workItem->title ?? ''),
                    'note' => $e->description,
                    'days' => round((float) $e->percentage / 100, 2),
                ];
            }

            $weekDays = round($weekEntries->sum(fn ($e) => (float) $e->percentage) / 100, 2);
            $weekCost = round($weekEntries->sum(fn ($e) => (float) ($e->cost ?? 0)), 2);

            $weekBlocks[] = [
                'label' => 'Week '.$weekStart->isoWeek,
                'dates' => $dates,
                'weekStart' => $weekStartStr,
                'status' => $timesheetsByWeekStart?->get($weekStartStr)?->status,
                'days' => $weekDays,
                'cost' => $weekCost,
                'lines' => $lines,
            ];
        }

        return $weekBlocks;
    }

    /**
     * The reporting window, resolved by the same ReportPeriod the attendance ledger
     * uses — Week/Month with an offset to step back through, or a custom pair of dates.
     *
     * A link from before this screen had granularity carries a bare ?from&?to with no
     * ?gran, so those are read as a custom range rather than silently falling back to
     * this month and showing the wrong period under an old bookmark.
     */
    private function periodFromRequest(Request $request): ReportPeriod
    {
        $query = $request->query();
        if (! isset($query['gran']) && (isset($query['from']) || isset($query['to']))) {
            $query['gran'] = 'custom';
        }

        return ReportPeriod::fromRequest($query, CarbonImmutable::now()->startOfDay());
    }

    // ---- Helpers ----------------------------------------------------------

    /**
     * Categories offered in the capture picker.
     *
     * On Leave and Public Holiday are excluded (D5) only while the leave module is on for
     * this tenant: those rows are then generated from approved leave requests and the
     * holiday calendar, so offering them by hand would let somebody log leave HR never
     * approved straight into the manday cost report. With the leave module off, nothing
     * auto-generates those rows, so staff need the manual option to log leave at all. The
     * categories themselves always stay in the table, because LockedDays files its
     * generated rows under them whenever the module is on.
     *
     * @param  array<int, int>  $keepIds  categories a stored draft already uses, kept even
     *                                    when deactivated: the grid labels its rows from this
     *                                    list, so dropping them would leave a saved line with
     *                                    no name on it. Nothing new can be filed under them —
     *                                    the capture screen has no category picker at all.
     */
    private function categoryOptions(array $keepIds = []): Collection
    {
        $generated = TimesheetCategory::generatedNames();

        return TimesheetCategory::where(fn ($q) => $q->where('is_active', true)->orWhereIn('id', $keepIds))
            ->orderBy('sort')->orderBy('name')->get()
            ->map(fn (TimesheetCategory $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'name_ms' => $c->name_ms ?: $c->name,
                'requires_project' => (bool) $c->requires_project,
                'colour' => $c->colour(),
            ])
            ->reject(fn (array $c) => in_array($c['name'], $generated, true))
            ->values();
    }

    /**
     * Active projects as plain arrays for the grid. `category_ids` drives the
     * picker's filter: a project with no categories at all is uncategorized and
     * stays selectable under every category, so existing projects don't disappear.
     *
     * Every project carries the same `sub_pillars` — one tenant-wide list since
     * sub-pillars stopped belonging to a project. The key stays per-project so the
     * capture JS (which reads `p.sub_pillars`) needs no change.
     */
    private function projectOptions(): Collection
    {
        $subPillars = SubPillar::where('is_active', true)->orderBy('sort')->orderBy('name')
            ->get()->map(fn (SubPillar $s) => ['id' => $s->id, 'name' => $s->name])->values();

        return Project::with('categories')
            ->where('is_active', true)->orderBy('sort')->orderBy('name')->get()
            ->map(fn (Project $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'category_ids' => $p->categories->pluck('id')->values(),
                'sub_pillars' => $subPillars,
            ])->values();
    }

    private function authorizeOwner(Request $request, Timesheet $timesheet): void
    {
        abort_unless($timesheet->tenant_id === app(CurrentTenant::class)->id(), 403);
        $actor = $request->attributes->get('employee');
        abort_unless($actor && $actor->id === $timesheet->employee_id, 403, 'You can only edit your own timesheets.');
    }
}
