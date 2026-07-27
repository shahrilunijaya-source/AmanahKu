<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\TotParticipation;
use App\Models\TotReaction;
use App\Models\TotSession;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class TotController extends Controller
{
    /** HR and management own the roster; everyone else reads, reacts, comments and rates. */
    private const PRIVILEGED_ROLES = ['management', 'hr'];

    /**
     * The year lineup. Always twelve slots, even for months nobody has filled: an absent
     * month is information, not a gap, so missing rows are filled with unsaved placeholder
     * models carrying the computed first-Saturday date.
     *
     * @return array<string, mixed>
     */
    public function screenData(Request $request, ?Employee $employee): array
    {
        $privileged = $this->hasTenantRole($request, self::PRIVILEGED_ROLES);
        $year = (int) ($request->query('year') ?: now()->year);

        $saved = TotSession::with(['presenter', 'entry'])
            ->withCount('comments')
            ->where('year', $year)
            ->get()
            ->keyBy('month');

        $sessions = collect(range(1, 12))->map(function (int $month) use ($saved, $year) {
            $session = $saved->get($month) ?? new TotSession([
                'year' => $year,
                'month' => $month,
                'status' => 'planned',
            ]);

            $session->session_date = TotSession::firstSaturday($year, $month);

            return $session;
        })->all();

        $ids = $saved->pluck('id')->all();

        return [
            'year' => $year,
            'years' => $this->availableYears($year),
            'sessions' => $sessions,
            'privileged' => $privileged,
            'canManage' => $privileged,
            'reactionCounts' => $this->reactionCounts($ids),
            'myReactions' => $this->myReactions($ids, $employee),
            'myParticipation' => $this->myParticipation($ids, $employee),
            'watchedCounts' => $this->watchedCounts($ids),
            'scores' => $this->visibleScores($saved, $employee, $privileged),
        ];
    }

    /** Privileged-only: create a slot for a month that has none yet. */
    public function store(Request $request): RedirectResponse
    {
        $this->authorizeTenantRole($request, self::PRIVILEGED_ROLES);

        $data = $request->validate([
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'month' => ['required', 'integer', 'min:1', 'max:12'],
            'presenter_employee_id' => ['nullable', 'integer', 'exists:employees,id'],
            'presenter_name' => ['nullable', 'string', 'max:120'],
            'title' => ['nullable', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:2000'],
            'status' => ['required', 'in:'.implode(',', TotSession::STATUSES)],
        ]);

        $session = TotSession::updateOrCreate(
            ['year' => $data['year'], 'month' => $data['month']],
            [
                'presenter_employee_id' => $data['presenter_employee_id'] ?? null,
                'presenter_name' => $data['presenter_name'] ?? null,
                'title' => $data['title'] ?? null,
                'description' => $data['description'] ?? null,
                'status' => $data['status'],
                'created_by' => $request->attributes->get('employee')?->id,
            ],
        );

        AuditLog::record('Created TOT slot', sprintf('%04d-%02d', $session->year, $session->month));

        return back()->with('ok', 'TOT slot saved.');
    }

    /**
     * Update a slot. HR and management may change everything; the presenter of the slot may
     * only change the material (title, description, links, cross-link). Status is
     * privileged because flipping it to done credits a Knowledge Bank month.
     */
    public function update(Request $request, TotSession $session): RedirectResponse
    {
        $employee = $request->attributes->get('employee');
        $privileged = $this->hasTenantRole($request, self::PRIVILEGED_ROLES);

        $this->authorizeSlotEdit($request, $session, $employee);

        $rules = [
            'title' => ['nullable', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:2000'],
            'links' => ['nullable', 'array', 'max:12'],
            'links.*.label' => ['required_with:links', 'string', 'max:60'],
            'links.*.url' => ['required_with:links', 'url', 'max:2000'],
            'entry_id' => ['nullable', 'integer', 'exists:knowledge_entries,id'],
        ];

        if ($privileged) {
            $rules['presenter_employee_id'] = ['nullable', 'integer', 'exists:employees,id'];
            $rules['presenter_name'] = ['nullable', 'string', 'max:120'];
            $rules['status'] = ['nullable', 'in:'.implode(',', TotSession::STATUSES)];
            $rules['held_on'] = ['nullable', 'date'];
        }

        // validate() returns only the keys it was given rules for. A non-privileged
        // presenter has no status rule, so a hand-crafted POST carrying status never
        // reaches $data and cannot promote the slot.
        $data = $request->validate($rules);

        $session->fill($data)->save();

        AuditLog::record('Updated TOT slot', sprintf('%04d-%02d', $session->year, $session->month));

        return back()->with('ok', 'TOT slot updated.');
    }

    /** Privileged-only: remove a slot entirely. */
    public function destroy(Request $request, TotSession $session): RedirectResponse
    {
        $this->authorizeTenantRole($request, self::PRIVILEGED_ROLES);

        $label = sprintf('%04d-%02d', $session->year, $session->month);
        $session->delete();

        AuditLog::record('Deleted TOT slot', $label);

        return back()->with('ok', 'TOT slot removed.');
    }

    /** 403 unless the actor is privileged or is the presenter of this slot. */
    private function authorizeSlotEdit(Request $request, TotSession $session, ?Employee $employee): void
    {
        if ($this->hasTenantRole($request, self::PRIVILEGED_ROLES)) {
            return;
        }

        abort_unless(
            $employee && $session->presenter_employee_id === $employee->id,
            403,
            'Only HR, management, or the presenter of this session can edit it.'
        );
    }

    /**
     * Every year that has at least one slot, plus the requested year and the current year,
     * newest first. Keeps the switcher useful before any history exists.
     *
     * @return list<int>
     */
    private function availableYears(int $requested): array
    {
        return TotSession::query()
            ->select('year')->distinct()->pluck('year')
            ->push($requested)->push((int) now()->year)
            ->unique()->sortDesc()->values()->all();
    }

    /**
     * Per-session emoji tallies, keyed session id => emoji => count.
     *
     * @param  list<int>  $ids
     * @return array<int, array<string, int>>
     */
    private function reactionCounts(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return TotReaction::whereIn('session_id', $ids)
            ->get()
            ->groupBy('session_id')
            ->map(fn (Collection $rows) => $rows->groupBy('emoji')->map->count()->all())
            ->all();
    }

    /**
     * The acting employee's own reactions, keyed session id => list of emoji, so the view
     * can highlight what they already pressed.
     *
     * @param  list<int>  $ids
     * @return array<int, list<string>>
     */
    private function myReactions(array $ids, ?Employee $employee): array
    {
        if ($ids === [] || ! $employee) {
            return [];
        }

        return TotReaction::whereIn('session_id', $ids)
            ->where('employee_id', $employee->id)
            ->get()
            ->groupBy('session_id')
            ->map(fn (Collection $rows) => $rows->pluck('emoji')->all())
            ->all();
    }

    /**
     * The acting employee's own participation row per session (watched flag plus their own
     * score), keyed by session id.
     *
     * @param  list<int>  $ids
     * @return array<int, TotParticipation>
     */
    private function myParticipation(array $ids, ?Employee $employee): array
    {
        if ($ids === [] || ! $employee) {
            return [];
        }

        return TotParticipation::whereIn('session_id', $ids)
            ->where('employee_id', $employee->id)
            ->get()->keyBy('session_id')->all();
    }

    /**
     * How many people marked each session watched.
     *
     * @param  list<int>  $ids
     * @return array<int, int>
     */
    private function watchedCounts(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return TotParticipation::whereIn('session_id', $ids)
            ->whereNotNull('watched_at')
            ->get()->groupBy('session_id')->map->count()->all();
    }

    /**
     * Score summaries, and only for sessions the viewer is allowed to see them on: their
     * own sessions as the presenter, or every session when privileged. Names never leave
     * this method; only the average, the count, and the anonymous notes do.
     *
     * @param  Collection<int, TotSession>  $saved
     * @return array<int, array{average: float, count: int, notes: list<string>}>
     */
    private function visibleScores(Collection $saved, ?Employee $employee, bool $privileged): array
    {
        $visible = $saved->filter(
            fn (TotSession $s) => $privileged || ($employee && $s->presenter_employee_id === $employee->id)
        );

        if ($visible->isEmpty()) {
            return [];
        }

        return TotParticipation::whereIn('session_id', $visible->pluck('id')->all())
            ->whereNotNull('score')
            ->get()
            ->groupBy('session_id')
            ->map(fn (Collection $rows) => [
                'average' => round($rows->avg('score'), 1),
                'count' => $rows->count(),
                'notes' => $rows->pluck('note')->filter()->values()->all(),
            ])
            ->all();
    }
}
