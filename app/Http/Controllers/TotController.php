<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\KnowledgeContribution;
use App\Models\TotComment;
use App\Models\TotParticipation;
use App\Models\TotReaction;
use App\Models\TotSession;
use App\Tenancy\CurrentTenant;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

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
            ->where('year', $year)
            ->get()
            ->keyBy('month');

        $sessions = collect(range(1, 12))->map(fn (int $month) => $saved->get($month) ?? new TotSession([
            'year' => $year,
            'month' => $month,
            'status' => 'planned',
        ]))->all();

        $ids = $saved->pluck('id')->all();

        return [
            'year' => $year,
            'years' => $this->availableYears($year),
            'sessions' => $sessions,
            'privileged' => $privileged,
            'canManage' => $privileged,
            'canAssignPresenter' => $this->canAssignPresenter($request),
            'reactionCounts' => $this->reactionCounts($ids),
            'myReactions' => $this->myReactions($ids, $employee),
            'myParticipation' => $this->myParticipation($ids, $employee),
            'watchedCounts' => $this->watchedCounts($ids),
            'scores' => $this->visibleScores($saved, $employee, $privileged),
            'comments' => $this->commentsBySession($ids),
        ];
    }

    /**
     * Create a slot for a month that has none yet. Privileged roles set every field; a
     * tot.assign holder opens the month with only year, month and presenter_employee_id,
     * and the slot lands planned.
     */
    public function store(Request $request): RedirectResponse
    {
        abort_unless($this->canAssignPresenter($request), 403);

        $privileged = $this->hasTenantRole($request, self::PRIVILEGED_ROLES);
        $tenantId = app(CurrentTenant::class)->id();

        $rules = [
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'month' => ['required', 'integer', 'min:1', 'max:12'],
            'presenter_employee_id' => ['nullable', 'integer', Rule::exists('employees', 'id')->where('tenant_id', $tenantId)],
        ];

        if ($privileged) {
            $rules['presenter_name'] = ['nullable', 'string', 'max:120'];
            $rules['title'] = ['nullable', 'string', 'max:200'];
            $rules['description'] = ['nullable', 'string', 'max:2000'];
            $rules['status'] = ['required', 'in:'.implode(',', TotSession::STATUSES)];
        }

        // A holder opens a month and puts a name on it. Everything else about the session,
        // including whether it happened, stays HR's decision, so their new slot is planned.
        $data = $request->validate($rules);
        $data['status'] ??= 'planned';

        $exists = TotSession::where('year', $data['year'])->where('month', $data['month'])->exists();
        abort_if($exists, 422, 'That slot already exists. Edit it instead of creating it again.');

        try {
            $session = TotSession::create([
                'year' => $data['year'],
                'month' => $data['month'],
                'presenter_employee_id' => $data['presenter_employee_id'] ?? null,
                'presenter_name' => $data['presenter_name'] ?? null,
                'title' => $data['title'] ?? null,
                'description' => $data['description'] ?? null,
                'status' => $data['status'],
                'created_by' => $request->attributes->get('employee')?->id,
            ]);
        } catch (QueryException $e) {
            // 23xxx = the unique (tenant_id, year, month) guard raced by a concurrent create,
            // same as the exists() pre-check above but for the request that lost the race.
            // Same 422 either way; a double-click must never fall through to a 500 page.
            if (! str_starts_with((string) $e->getCode(), '23')) {
                throw $e;
            }

            abort(422, 'That slot already exists. Edit it instead of creating it again.');
        }

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
        $this->assertSameTenant($session);

        $employee = $request->attributes->get('employee');
        $privileged = $this->hasTenantRole($request, self::PRIVILEGED_ROLES);
        $isPresenterOfSlot = $employee && $session->presenter_employee_id === $employee->id;

        $this->authorizeSlotEdit($request, $session, $employee);
        $tenantId = app(CurrentTenant::class)->id();

        // The material (title, description, links, cross-link) belongs to the presenter of
        // THIS slot or a privileged role, not to a tot.assign holder: that override buys
        // exactly one field, presenter_employee_id, handled below.
        $rules = [];

        if ($privileged || $isPresenterOfSlot) {
            $rules['title'] = ['nullable', 'string', 'max:200'];
            $rules['description'] = ['nullable', 'string', 'max:2000'];
            $rules['links'] = ['nullable', 'array', 'max:12'];
            $rules['links.*.label'] = ['required_with:links', 'string', 'max:60'];
            $rules['links.*.url'] = ['required_with:links', 'url', 'max:2000'];
            $rules['entry_id'] = ['nullable', 'integer', Rule::exists('knowledge_entries', 'id')->where('tenant_id', $tenantId)];
        }

        if ($this->canAssignPresenter($request)) {
            $rules['presenter_employee_id'] = ['nullable', 'integer', Rule::exists('employees', 'id')->where('tenant_id', $tenantId)];
        }

        if ($privileged) {
            $rules['presenter_name'] = ['nullable', 'string', 'max:120'];
            $rules['status'] = ['nullable', 'in:'.implode(',', TotSession::STATUSES)];
            $rules['held_on'] = ['nullable', 'date'];
        }

        // validate() returns only the keys it was given rules for. A non-privileged
        // presenter has no status rule, so a hand-crafted POST carrying status never
        // reaches $data and cannot promote the slot.
        $data = $request->validate($rules);

        $wasDone = $session->status === 'done';

        $session->fill($data);

        // The two presenter columns are one fact stored two ways: a linked employee, or a
        // free-text name for an imported nickname nobody has matched yet. Whenever this
        // request decides the linked employee, the stale free-text name goes with it,
        // unless the same request explicitly supplies one (privileged callers only).
        if (array_key_exists('presenter_employee_id', $data) && blank($data['presenter_name'] ?? null)) {
            $session->presenter_name = null;
        }

        // Stamp held_on on the transition INTO done, same rule TotHistorySeeder uses for
        // imported rows, so a session marked done through the UI carries a date too. Only
        // fills a still-blank value: HR can override it afterwards and that override sticks.
        if (! $wasDone && $session->status === 'done' && $session->held_on === null) {
            $session->held_on = TotSession::firstSaturday((int) $session->year, (int) $session->month)->toDateString();
        }

        $session->save();

        // Credit only on the transition INTO done, and only once. Presenting is the thing
        // that earns the month; editing the title afterwards is not.
        if (! $wasDone && $session->status === 'done') {
            $this->creditContribution($session);
        }

        AuditLog::record('Updated TOT slot', sprintf('%04d-%02d', $session->year, $session->month));

        return back()->with('ok', 'TOT slot updated.');
    }

    /** Anybody in the workspace may post to a session thread. */
    public function comment(Request $request, TotSession $session): RedirectResponse
    {
        $this->assertSameTenant($session);

        $employee = $request->attributes->get('employee');
        abort_unless($employee, 403, 'No employee profile in this workspace.');

        $data = $request->validate([
            'body' => ['required', 'string', 'max:2000'],
        ]);

        TotComment::create([
            'session_id' => $session->id,
            'employee_id' => $employee->id,
            'body' => $data['body'],
        ]);

        return back()->with('ok', 'Comment posted.');
    }

    /** The author removes their own comment; HR and management may remove any. */
    public function deleteComment(Request $request, TotComment $comment): RedirectResponse
    {
        $this->assertSameTenant($comment);

        $employee = $request->attributes->get('employee');

        abort_unless(
            $this->hasTenantRole($request, self::PRIVILEGED_ROLES)
                || ($employee && $comment->employee_id === $employee->id),
            403,
            'You can only delete your own comment.'
        );

        $comment->delete();

        return back()->with('ok', 'Comment removed.');
    }

    /**
     * Toggle one whitelisted emoji for the acting employee. A repeat POST of the same emoji
     * removes it; different emoji stack, one row each, guarded by the unique key.
     */
    public function react(Request $request, TotSession $session): RedirectResponse
    {
        $this->assertSameTenant($session);

        $employee = $request->attributes->get('employee');
        abort_unless($employee, 403, 'No employee profile in this workspace.');

        $data = $request->validate([
            'emoji' => ['required', 'string', 'in:'.implode(',', TotSession::EMOJI)],
        ]);

        $existing = TotReaction::where('session_id', $session->id)
            ->where('employee_id', $employee->id)
            ->where('emoji', $data['emoji'])
            ->first();

        if ($existing) {
            $existing->delete();

            return back();
        }

        try {
            TotReaction::create([
                'session_id' => $session->id,
                'employee_id' => $employee->id,
                'emoji' => $data['emoji'],
            ]);
        } catch (QueryException $e) {
            // 23xxx = the unique (session_id, employee_id, emoji) duplicate-reaction guard.
            // Anything else is a real DB failure, so do not mask it behind a friendly message.
            if (! str_starts_with((string) $e->getCode(), '23')) {
                throw $e;
            }
        }

        return back();
    }

    /** Mark the session watched for the acting employee. Idempotent. */
    public function watched(Request $request, TotSession $session): RedirectResponse
    {
        $this->assertSameTenant($session);

        $employee = $request->attributes->get('employee');
        abort_unless($employee, 403, 'No employee profile in this workspace.');

        $row = TotParticipation::firstOrNew([
            'session_id' => $session->id,
            'employee_id' => $employee->id,
        ]);

        $row->watched_at ??= now();

        try {
            $row->save();
        } catch (QueryException $e) {
            // 23xxx = the unique (session_id, employee_id) guard raced by a concurrent
            // insert; the other request already recorded this employee as watched, which
            // is the state this call wanted too, so there is nothing left to do.
            if (! str_starts_with((string) $e->getCode(), '23')) {
                throw $e;
            }
        }

        return back()->with('ok', 'Marked as watched.');
    }

    /**
     * Record or replace the acting employee's rating.
     *
     * The row carries employee_id so a person can rate once and edit it later, which makes
     * the rating pseudonymous rather than anonymous. No screen ever renders who scored what,
     * and only the presenter and privileged roles see scores at all. Rating implies watching,
     * so the same call stamps watched_at.
     */
    public function rate(Request $request, TotSession $session): RedirectResponse
    {
        $this->assertSameTenant($session);

        $employee = $request->attributes->get('employee');
        abort_unless($employee, 403, 'No employee profile in this workspace.');

        $data = $request->validate([
            'score' => ['required', 'integer', 'min:1', 'max:5'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $row = TotParticipation::firstOrNew([
            'session_id' => $session->id,
            'employee_id' => $employee->id,
        ]);

        // Only overwrite the note when this submit actually carries one. A score-only
        // resubmit (the rater just changing their mind on the number) must not silently
        // wipe a note they wrote earlier, and because the screen never echoes a rater's
        // own note back into the page (ratings are pseudonymous even to their own author),
        // there is no form field to resubmit it from. Preserving server-side is the only
        // option that keeps both promises at once.
        $row->score = $data['score'];
        if ($request->filled('note')) {
            $row->note = $data['note'];
        }
        $row->watched_at ??= now();

        try {
            $row->save();
        } catch (QueryException $e) {
            if (! str_starts_with((string) $e->getCode(), '23')) {
                throw $e;
            }

            // Unlike react(), a rating carries information that can differ between two
            // racing requests. A concurrent first-time submit already inserted the row,
            // so the plain firstOrNew() above missed it, and this insert lost the unique
            // (session_id, employee_id) race. The user was already told "your rating was
            // saved", so their score must win, not be silently dropped. Re-read the row
            // the winner created and overwrite it with what THIS request submitted.
            //
            // The follow-up save() below is an UPDATE keyed by the row's own id, not an
            // INSERT keyed by the (session_id, employee_id) pair, so it cannot collide
            // with the same unique constraint and does not need its own catch. Two
            // recovery paths racing each other here just resolve to last-write-wins,
            // the same semantics an ordinary edit-your-rating request already has.
            $row = TotParticipation::where('session_id', $session->id)
                ->where('employee_id', $employee->id)
                ->firstOrFail();

            $row->score = $data['score'];
            if ($request->filled('note')) {
                $row->note = $data['note'];
            }
            $row->watched_at ??= now();
            $row->save();
        }

        return back()->with('ok', 'Thanks, your rating was saved.');
    }

    /** Privileged-only: remove a slot entirely. */
    public function destroy(Request $request, TotSession $session): RedirectResponse
    {
        $this->assertSameTenant($session);
        $this->authorizeTenantRole($request, self::PRIVILEGED_ROLES);

        $label = sprintf('%04d-%02d', $session->year, $session->month);
        $session->delete();

        AuditLog::record('Deleted TOT slot', $label);

        return back()->with('ok', 'TOT slot removed.');
    }

    /**
     * May the actor set who presents a month? True for HR and management by role, and for
     * anybody given the tot.assign override on the Roles screen. It buys exactly one field,
     * presenter_employee_id, and nothing else on the slot.
     */
    private function canAssignPresenter(Request $request): bool
    {
        if ($this->hasTenantRole($request, self::PRIVILEGED_ROLES)) {
            return true;
        }

        $tenant = app(CurrentTenant::class)->get();

        return $tenant !== null
            && $request->user()?->canInTenant($tenant, 'tot.assign') === true;
    }

    /**
     * 404 unless the route-bound record belongs to the acting tenant.
     *
     * Route-model binding resolves before the BelongsToTenant global scope is active (the
     * 'tenant' middleware sets the active tenant later in the pipeline), so on its own the
     * scope does not stop an actor in one tenant from reaching another tenant's TOT session
     * or comment by id. Every action that receives one as a route parameter must call this
     * first. 404, not 403: a foreign id must look indistinguishable from one that does not
     * exist at all, and must never leave the record reachable to write through.
     */
    private function assertSameTenant(TotSession|TotComment $record): void
    {
        abort_unless($record->tenant_id === app(CurrentTenant::class)->id(), 404);
    }

    /** 403 unless the actor is privileged, holds tot.assign, or is the presenter of this slot. */
    private function authorizeSlotEdit(Request $request, TotSession $session, ?Employee $employee): void
    {
        if ($this->canAssignPresenter($request)) {
            return;
        }

        abort_unless(
            $employee && $session->presenter_employee_id === $employee->id,
            403,
            'Only HR, management, the TOT organiser, or the presenter of this session can edit it.'
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

    /**
     * Thread contents per session, oldest first, so a question asked before the Saturday and
     * a follow-up posted after it read in the order they happened.
     *
     * @param  list<int>  $ids
     * @return array<int, Collection<int, TotComment>>
     */
    private function commentsBySession(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return TotComment::with('employee')
            ->whereIn('session_id', $ids)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->groupBy('session_id')
            ->all();
    }

    /**
     * Presenting a TOT counts as that month's Knowledge Bank contribution.
     *
     * Credits the SESSION's year and month, never now(), so a slot marked done late still
     * credits the month it was held in. Never revoked when a slot moves back out of done:
     * revoking could silently erase a contribution the person separately earned by writing
     * a real lesson, and that bug would be invisible.
     */
    private function creditContribution(TotSession $session): void
    {
        $presenter = $session->presenter;

        if (! $presenter) {
            return;
        }

        KnowledgeContribution::mark($presenter, (int) $session->year, (int) $session->month);
    }
}
