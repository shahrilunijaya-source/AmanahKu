<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Project;
use App\Models\ProjectSubPillar;
use App\Models\Timesheet;
use App\Models\TimesheetCategory;
use App\Models\TimesheetEntry;
use App\Models\TimesheetTemplate;
use App\Services\DataScope;
use App\Services\MandayRateService;
use App\Support\HtmlSanitizer;
use App\Tenancy\CurrentTenant;
use App\Timesheet\LockedDays;
use App\Timesheet\TimesheetCompliance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TimesheetController extends Controller
{
    /**
     * Roles allowed to see salary-derived RM cost (managers, management/directors, HR).
     * Plain employees never see money — only their own time in person-days.
     */
    private const MONEY_ROLES = ['manager', 'management', 'hr'];

    /**
     * How far back a staffer may still edit. The current week plus this many earlier weeks.
     *
     * Blocking past days outright is not an option: a forgotten Monday could never reach
     * 100%, so the week could never be submitted. An unbounded window is not either, because
     * it lets somebody backfill months the night before an audit.
     */
    private const BACKFILL_WEEKS = 3;

    /**
     * Build the timesheets screen data. Tenant scope is automatic via BelongsToTenant.
     *
     * Staff allocate each week by PERCENTAGE across a per-day grid; every populated
     * day must total 100% before it can be submitted. The grid targets one selectable
     * week (?week=YYYY-MM-DD, default this week) and prefills from an existing draft.
     *
     * Manday RM cost (hours derived from percentage) is layered on for HR & management
     * only, so line managers and staff approve/log without seeing salary-derived cost.
     *
     * @return array<string, mixed>
     */
    public function screenData(Request $request, ?Employee $employee): array
    {
        $role = $request->attributes->get('tenantRole', 'employee');
        $canSeeCost = in_array($role, self::MONEY_ROLES, true);

        $weekStart = $request->filled('week')
            ? Carbon::parse($request->query('week'))->startOfWeek()
            : Carbon::now()->startOfWeek();

        $with = ['entries.category', 'entries.projectRef', 'entries.subPillar', 'employee.positionBand'];

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

        // Personal time breakdown (person-days, never RM) for the signed-in staff: where
        // their own recorded time went, by category and by project, over a chosen period.
        [$pbFrom, $pbTo] = $this->periodFromRequest($request);
        $myBreakdown = $employee ? $this->personalBreakdown($employee, $pbFrom, $pbTo) : null;

        // The timesheet (if any) for the selected capture week, and its grid prefill.
        $weekTimesheet = $employee
            ? $myTimesheets->first(fn (Timesheet $t) => $t->week_start->isSameDay($weekStart))
            : null;

        $existingGrid = [];
        if ($weekTimesheet) {
            foreach ($weekTimesheet->entries as $e) {
                $existingGrid[$e->entry_date->toDateString()][] = [
                    'category_id' => $e->category_id,
                    'project_id' => $e->project_id,
                    'sub_pillar_id' => $e->sub_pillar_id,
                    'percentage' => (float) $e->percentage,
                    'description' => $e->description ?? '',
                ];
            }
        }

        return [
            'myTimesheets' => $myTimesheets,
            'canSeeCost' => $canSeeCost,
            'timesheetCosts' => $timesheetCosts,
            // Prompt HR to assign a band when the signed-in money-role user has none.
            'positionMissing' => $canSeeCost && $employee && ! $employee->position_id,
            // Personal time breakdown (days only) + its period.
            'myBreakdown' => $myBreakdown,
            'breakdownFrom' => $pbFrom->toDateString(),
            'breakdownTo' => $pbTo->toDateString(),
            // Capture grid inputs.
            'tsCategories' => $this->categoryOptions(),
            'tsProjects' => $this->projectOptions(),
            'tsTemplates' => $this->templateOptions($employee),
            'weekStart' => $weekStart->toDateString(),
            'weekLabel' => $weekTimesheet?->week_label ?? '',
            'weekStatus' => $weekTimesheet?->status,
            'weekTimesheet' => $weekTimesheet,
            'existingGrid' => $existingGrid,
            // All-staff weekly compliance board (names + status only, no cost).
            'tsRoster' => app(TimesheetCompliance::class)
                ->roster(app(CurrentTenant::class)->get(), $weekStart),
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

        $data = $request->validate([
            'week_start' => ['required', 'date'],
            'week_label' => ['nullable', 'string', 'max:60'],
            'submit_now' => ['nullable', 'boolean'],
            'entries' => ['present', 'array'],
            'entries.*.entry_date' => ['required', 'date'],
            'entries.*.category_id' => ['required', 'integer', Rule::exists('timesheet_categories', 'id')->where('tenant_id', $tid)],
            'entries.*.project_id' => ['nullable', 'integer', Rule::exists('projects', 'id')->where('tenant_id', $tid)],
            'entries.*.sub_pillar_id' => ['nullable', 'integer', Rule::exists('project_sub_pillars', 'id')->where('tenant_id', $tid)],
            'entries.*.percentage' => ['required', 'numeric', 'min:0.01', 'max:100'],
            'entries.*.description' => ['nullable', 'string', 'max:10000'],
        ]);

        $lockedDays = app(LockedDays::class);
        $locked = $lockedDays->forWeek($employee, Carbon::parse($data['week_start'])->startOfDay());

        // D4: an approved leave day or public holiday is a fact HR owns. Anything the staffer
        // typed against that day is wrong by definition, so it is dropped rather than merged.
        $userEntries = array_filter(
            $data['entries'],
            fn (array $e) => ! isset($locked[Carbon::parse($e['entry_date'])->toDateString()])
        );

        $this->assertDatesInWindow($userEntries);

        $entries = $this->normaliseEntries($userEntries);
        $entries = array_merge($entries, $lockedDays->entryRows($employee, $data['week_start']));

        $submitNow = $request->boolean('submit_now');
        // A fully-locked week may submit with no user rows, but a genuinely empty week
        // must not: mirror submit()'s invariant so store()'s submit_now path can't create
        // a submitted timesheet with zero entries (which would land in the cost report).
        abort_if($submitNow && count($entries) === 0, 422, 'Cannot submit an empty timesheet.');
        if ($submitNow) {
            $this->assertDayTotals($entries);
        }

        $weekStart = Carbon::parse($data['week_start'])->startOfDay();

        $timesheet = Timesheet::firstOrNew([
            'employee_id' => $employee->id,
            'week_start' => $weekStart,
        ]);
        abort_if(
            $timesheet->exists && $timesheet->status !== 'draft',
            422,
            'This week has already been submitted and cannot be edited.'
        );

        DB::transaction(function () use ($timesheet, $data, $entries, $submitNow) {
            $timesheet->fill(['week_label' => $data['week_label'] ?? null, 'status' => 'draft'])->save();
            // The grid represents the entire week — replace, don't append.
            $timesheet->entries()->delete();
            foreach ($entries as $entry) {
                $timesheet->entries()->create($entry);
            }
            $timesheet->recomputeTotal();

            if ($submitNow) {
                $timesheet->update(['status' => 'submitted', 'submitted_at' => now()]);
            }
        });

        $message = $submitNow
            ? 'Timesheet submitted for approval.'
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

    public function submit(Request $request, Timesheet $timesheet): RedirectResponse
    {
        $this->authorizeOwner($request, $timesheet);
        abort_unless($timesheet->status === 'draft', 422);
        abort_if($timesheet->entries()->count() === 0, 422, 'Cannot submit an empty timesheet.');

        // A draft may be partial, but a submission must have every populated day at 100%.
        $this->assertDayTotals($timesheet->entries()->get()->map(fn (TimesheetEntry $e) => [
            'entry_date' => $e->entry_date->toDateString(),
            'percentage' => (float) $e->percentage,
        ])->all());

        $timesheet->recomputeTotal();
        $timesheet->update(['status' => 'submitted', 'submitted_at' => now()]);
        AuditLog::record('Submitted timesheet', $timesheet->week_label ?: $timesheet->week_start->toDateString());

        return back()->with('ok', 'Timesheet submitted for approval.');
    }

    // ---- Per-staff templates ---------------------------------------------

    public function storeTemplate(Request $request): RedirectResponse
    {
        $employee = $request->attributes->get('employee');
        abort_unless($employee, 403, 'No employee profile in this workspace.');
        $tid = app(CurrentTenant::class)->id();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'category_id' => ['required', 'integer', Rule::exists('timesheet_categories', 'id')->where('tenant_id', $tid)],
            'project_id' => ['nullable', 'integer', Rule::exists('projects', 'id')->where('tenant_id', $tid)],
            'sub_pillar_id' => ['nullable', 'integer', Rule::exists('project_sub_pillars', 'id')->where('tenant_id', $tid)],
            'percentage' => ['nullable', 'numeric', 'min:0.01', 'max:100'],
            'description' => ['nullable', 'string', 'max:10000'],
        ]);

        TimesheetTemplate::updateOrCreate(
            ['employee_id' => $employee->id, 'name' => $data['name']],
            [
                'category_id' => $data['category_id'],
                'project_id' => $data['project_id'] ?? null,
                'sub_pillar_id' => $data['sub_pillar_id'] ?? null,
                'percentage' => $data['percentage'] ?? null,
                'description' => HtmlSanitizer::clean($data['description'] ?? null),
            ],
        );

        return back()->with('ok', 'Template "'.$data['name'].'" saved.');
    }

    public function deleteTemplate(Request $request, TimesheetTemplate $template): RedirectResponse
    {
        $employee = $request->attributes->get('employee');
        abort_unless($employee && $template->employee_id === $employee->id, 403, 'You can only remove your own templates.');

        $name = $template->name;
        $template->delete();

        return back()->with('ok', 'Template "'.$name.'" removed.');
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
        $canSeeCost = in_array($role, self::MONEY_ROLES, true);

        [$from, $to] = $this->periodFromRequest($request);

        $categoryId = $request->integer('category') ?: null;
        $projectId = $request->integer('project') ?: null;

        // Data scope: a branch/department-restricted manager only sees their slice of the
        // (money-sensitive) timesheet cost report (AK-AUTHZ-01). null = 'company', no limit.
        $scope = $request->attributes->get('tenantScope', 'company');
        $visibleIds = app(DataScope::class)->visibleEmployeeIds($scope, $employee);

        $entries = TimesheetEntry::with(['category', 'projectRef', 'timesheet.employee.positionBand'])
            ->whereBetween('entry_date', [$from->toDateString(), $to->toDateString()])
            ->whereHas('timesheet', fn ($q) => $q->whereIn('status', ['submitted', 'approved'])
                ->whereHas('employee', fn ($e) => $e->active()) // archived owners' entries drop from RM totals
                ->when($visibleIds !== null, fn ($t) => $t->whereIn('employee_id', $visibleIds)))
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

        // ----- By category: category -> days + RM (answers "how much on Study") -----
        $byCategory = $entries->groupBy(fn ($e) => $e->category?->name ?? 'Uncategorised')
            ->map(function (Collection $rows, string $label) use ($days, $cost, $grandDays) {
                $d = $days($rows);

                return [
                    'label' => $label,
                    'days' => $d,
                    'cost' => $cost($rows),
                    'people' => $rows->pluck('timesheet.employee_id')->unique()->count(),
                    'pct' => $grandDays > 0 ? (int) round($d / $grandDays * 100) : 0,
                ];
            })->values()->sortByDesc('cost')->sortByDesc('days')->values()->all();

        // ----- By project: project -> employees, in person-days + RM -----
        $byProject = $entries->filter(fn ($e) => $e->projectRef)
            ->groupBy(fn ($e) => $e->projectRef->name)
            ->map(function (Collection $rows, string $projectName) use ($days, $cost) {
                $total = $days($rows);
                $employees = $rows->groupBy(fn ($e) => $e->timesheet->employee->name)
                    ->map(function (Collection $empRows) use ($total, $days, $cost) {
                        $emp = $empRows->first()->timesheet->employee;
                        $d = $days($empRows);

                        return [
                            'name' => $emp->name,
                            'initials' => $emp->initials,
                            'color' => $emp->avatar_color ?? config('amanahku.avatar_color'),
                            'days' => $d,
                            'cost' => $cost($empRows),
                            'pct' => $total > 0 ? (int) round($d / $total * 100) : 0,
                        ];
                    })->values()->sortByDesc('days')->values()->all();

                return ['project' => $projectName, 'days' => $total, 'cost' => $cost($rows), 'employees' => $employees];
            })->values()->sortByDesc('cost')->sortByDesc('days')->values()->all();

        // ----- By staff: person -> project/category breakdown, in person-days + RM -----
        $byStaff = $entries->groupBy(fn ($e) => $e->timesheet->employee->name)
            ->map(function (Collection $rows) use ($days, $cost) {
                $emp = $rows->first()->timesheet->employee;
                $total = $days($rows);
                $breakdown = $rows->groupBy(fn ($e) => $e->projectRef?->name ?: ($e->category?->name ?? 'Uncategorised'))
                    ->map(function (Collection $g, string $label) use ($total, $days, $cost) {
                        $d = $days($g);

                        return [
                            'label' => $label,
                            'days' => $d,
                            'cost' => $cost($g),
                            'pct' => $total > 0 ? (int) round($d / $total * 100) : 0,
                        ];
                    })->values()->sortByDesc('days')->values()->all();

                return [
                    'name' => $emp->name,
                    'initials' => $emp->initials,
                    'color' => $emp->avatar_color ?? config('amanahku.avatar_color'),
                    'days' => $total,
                    'cost' => $cost($rows),
                    'rows' => $breakdown,
                ];
            })->values()->sortByDesc('cost')->sortByDesc('days')->values()->all();

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'canSeeCost' => $canSeeCost,
            'byCategory' => $byCategory,
            'byProject' => $byProject,
            'byStaff' => $byStaff,
            'reportTotals' => ['days' => $grandDays, 'cost' => $grandCost, 'uncostedDays' => $uncostedDays],
            'reportEmpty' => $entries->isEmpty(),
            // Filter dropdown options + current selection.
            'filterCategories' => TimesheetCategory::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'filterProjects' => Project::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'selCategory' => $categoryId,
            'selProject' => $projectId,
        ];
    }

    /**
     * One employee's own recorded time (person-days, never RM) over a period, grouped by
     * category (Study, Leave, …) and by project. Submitted + approved weeks only — this
     * is what staff see about themselves, without any salary-derived figures.
     *
     * @return array<string, mixed>
     */
    private function personalBreakdown(Employee $employee, Carbon $from, Carbon $to): array
    {
        $entries = TimesheetEntry::with(['category', 'projectRef'])
            ->whereBetween('entry_date', [$from->toDateString(), $to->toDateString()])
            ->whereHas('timesheet', fn ($q) => $q->where('employee_id', $employee->id)->whereIn('status', ['submitted', 'approved']))
            ->get();

        $total = round($entries->sum(fn ($e) => (float) $e->percentage) / 100, 2);
        $slice = fn (Collection $rows, string $label) => [
            'label' => $label,
            'days' => round($rows->sum(fn ($e) => (float) $e->percentage) / 100, 2),
            'pct' => $total > 0 ? (int) round($rows->sum(fn ($e) => (float) $e->percentage) / 100 / $total * 100) : 0,
        ];

        $byCategory = $entries->groupBy(fn ($e) => $e->category?->name ?? 'Uncategorised')
            ->map(fn (Collection $rows, string $label) => $slice($rows, $label))
            ->values()->sortByDesc('days')->values()->all();

        $byProject = $entries->filter(fn ($e) => $e->projectRef)
            ->groupBy(fn ($e) => $e->projectRef->name)
            ->map(fn (Collection $rows, string $label) => $slice($rows, $label))
            ->values()->sortByDesc('days')->values()->all();

        return [
            'totalDays' => $total,
            'byCategory' => $byCategory,
            'byProject' => $byProject,
            'empty' => $entries->isEmpty(),
        ];
    }

    /**
     * Resolve a [from, to] reporting window from ?from / ?to (inclusive day bounds),
     * defaulting to the current calendar month and swapping if the two are reversed.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function periodFromRequest(Request $request): array
    {
        $from = $request->filled('from') ? Carbon::parse($request->query('from'))->startOfDay() : Carbon::now()->startOfMonth();
        $to = $request->filled('to') ? Carbon::parse($request->query('to'))->endOfDay() : Carbon::now()->endOfMonth();
        if ($to->lt($from)) {
            [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
        }

        return [$from, $to];
    }

    // ---- Helpers ----------------------------------------------------------

    /** Active categories as plain arrays for the Alpine capture grid. */
    private function categoryOptions(): Collection
    {
        return TimesheetCategory::where('is_active', true)->orderBy('sort')->orderBy('name')->get()
            ->map(fn (TimesheetCategory $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'name_ms' => $c->name_ms ?: $c->name,
                'requires_project' => (bool) $c->requires_project,
            ])->values();
    }

    /** Active projects (with active sub-pillars) as plain arrays for the grid. */
    private function projectOptions(): Collection
    {
        return Project::with(['subPillars' => fn ($q) => $q->where('is_active', true)->orderBy('sort')->orderBy('name')])
            ->where('is_active', true)->orderBy('sort')->orderBy('name')->get()
            ->map(fn (Project $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'sub_pillars' => $p->subPillars->map(fn (ProjectSubPillar $s) => ['id' => $s->id, 'name' => $s->name])->values(),
            ])->values();
    }

    /** The acting employee's saved allocation templates as plain arrays. */
    private function templateOptions(?Employee $employee): Collection
    {
        if (! $employee) {
            return new Collection;
        }

        return TimesheetTemplate::where('employee_id', $employee->id)->orderBy('name')->get()
            ->map(fn (TimesheetTemplate $t) => [
                'id' => $t->id,
                'name' => $t->name,
                'category_id' => $t->category_id,
                'project_id' => $t->project_id,
                'sub_pillar_id' => $t->sub_pillar_id,
                'percentage' => $t->percentage !== null ? (float) $t->percentage : null,
                'description' => $t->description ?? '',
            ])->values();
    }

    /**
     * Apply business rules to raw validated entries and shape them for persistence:
     * enforce requires_project, ensure a sub-pillar belongs to its project, sanitise
     * the description, set the legacy `project` string, and derive `hours` from the
     * percentage so manday RM costing (hours * rate) keeps working — one full day at
     * 100% equals one manday (config('manday.hours_per_day') hours).
     *
     * @param  array<int, array<string, mixed>>  $raw
     * @return array<int, array<string, mixed>>
     */
    private function normaliseEntries(array $raw): array
    {
        $hoursPerDay = (float) config('manday.hours_per_day', 8);

        $categories = TimesheetCategory::whereIn('id', collect($raw)->pluck('category_id')->filter()->unique())->get()->keyBy('id');
        $projects = Project::whereIn('id', collect($raw)->pluck('project_id')->filter()->unique())->get()->keyBy('id');
        $subPillars = ProjectSubPillar::whereIn('id', collect($raw)->pluck('sub_pillar_id')->filter()->unique())->get()->keyBy('id');

        $out = [];
        foreach ($raw as $i => $e) {
            $category = $categories->get($e['category_id']);
            if (! $category) {
                throw ValidationException::withMessages(["entries.$i.category_id" => 'Unknown category.']);
            }

            $projectId = $e['project_id'] ?? null;
            $subId = $e['sub_pillar_id'] ?? null;

            if ($category->requires_project) {
                if (! $projectId || ! $projects->has($projectId)) {
                    throw ValidationException::withMessages(["entries.$i.project_id" => 'Choose a project for '.$category->name.'.']);
                }
            } else {
                // Standalone categories never carry a project or sub-pillar.
                $projectId = null;
                $subId = null;
            }

            if ($subId) {
                $sub = $subPillars->get($subId);
                if (! $sub || (int) $sub->project_id !== (int) $projectId) {
                    throw ValidationException::withMessages(["entries.$i.sub_pillar_id" => 'That sub-pillar does not belong to the chosen project.']);
                }
            }

            $percentage = round((float) $e['percentage'], 2);
            $projectName = $projectId ? ($projects->get($projectId)->name ?? null) : null;

            $out[] = [
                'entry_date' => Carbon::parse($e['entry_date'])->toDateString(),
                'category_id' => $category->id,
                'project_id' => $projectId,
                'sub_pillar_id' => $subId,
                'percentage' => $percentage,
                'description' => HtmlSanitizer::clean($e['description'] ?? null),
                // Legacy readable fallback for any code still reading the string column.
                'project' => trim($category->name.($projectName ? ' — '.$projectName : '')),
                // Hours derived from percentage so manday RM costing keeps working.
                'hours' => round($percentage / 100 * $hoursPerDay, 2),
            ];
        }

        return $out;
    }

    /**
     * Every day that has entries must total exactly 100% (float tolerance). Empty days
     * are allowed. Throws a ValidationException keyed by the offending date.
     *
     * @param  array<int, array{entry_date:string, percentage:float}>  $entries
     */
    private function assertDayTotals(array $entries): void
    {
        $byDay = [];
        foreach ($entries as $e) {
            $byDay[$e['entry_date']] = ($byDay[$e['entry_date']] ?? 0) + (float) $e['percentage'];
        }

        foreach ($byDay as $date => $total) {
            if (abs($total - 100) >= 0.01) {
                $shown = rtrim(rtrim(number_format($total, 2), '0'), '.');
                throw ValidationException::withMessages([
                    'submit' => Carbon::parse($date)->format('D, j M').' totals '.$shown.'% — each day must add up to 100% before submitting.',
                ]);
            }
        }
    }

    /**
     * Entry dates must be today or earlier (D2 — you cannot have spent time you have not
     * spent), and no earlier than the backfill window (D3). Generated leave and holiday rows
     * bypass this: they are approved facts, not claims, and may legitimately sit in the future.
     *
     * @param  array<int, array<string, mixed>>  $entries
     */
    private function assertDatesInWindow(array $entries): void
    {
        $today = Carbon::now()->startOfDay();
        $earliest = Carbon::now()->startOfWeek()->subWeeks(self::BACKFILL_WEEKS);

        foreach ($entries as $i => $e) {
            $date = Carbon::parse($e['entry_date'])->startOfDay();

            if ($date->greaterThan($today)) {
                throw ValidationException::withMessages([
                    "entries.$i.entry_date" => $date->format('D, j M').' has not happened yet.',
                ]);
            }

            if ($date->lessThan($earliest)) {
                throw ValidationException::withMessages([
                    "entries.$i.entry_date" => $date->format('D, j M').' is too far back to edit. Ask HR to reopen it.',
                ]);
            }
        }
    }

    private function authorizeOwner(Request $request, Timesheet $timesheet): void
    {
        abort_unless($timesheet->tenant_id === app(CurrentTenant::class)->id(), 403);
        $actor = $request->attributes->get('employee');
        abort_unless($actor && $actor->id === $timesheet->employee_id, 403, 'You can only edit your own timesheets.');
    }
}
