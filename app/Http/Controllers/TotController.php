<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AppNotification;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\KnowledgeContribution;
use App\Models\Reaction;
use App\Models\TotAction;
use App\Models\TotAttendance;
use App\Models\TotComment;
use App\Models\TotParticipation;
use App\Models\TotReaction;
use App\Models\TotSession;
use App\Models\TotSlot;
use App\Models\WorkItem;
use App\Tenancy\CurrentTenant;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
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

        $saved = TotSession::with([
            'presenter', 'presenters', 'chair',
            'slots.presenters', 'slots.reactions', 'attendance.employee',
            'actions.owner', 'actions.slot', 'actions.workItem',
        ])
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
            'assignableEmployees' => $this->assignableEmployees(),
            'reactionCounts' => $this->reactionCounts($ids),
            'myReactions' => $this->myReactions($ids, $employee),
            'myParticipation' => $this->myParticipation($ids, $employee),
            // The one slot this viewer presents in the displayed year, if any. A
            // person presents once a year, so they do not need their own route —
            // they need the board to point at their month.
            'myMonth' => $employee
                ? collect($sessions)->first(fn (TotSession $s) => $s->exists && $s->isPresentedBy($employee))
                : null,
            'watchedCounts' => $this->watchedCounts($ids),
            'scores' => $this->visibleScores($saved, $employee, $privileged),
            'commentCounts' => $this->commentCounts($ids),
        ];
    }

    /**
     * The assignment picker. Twelve slots for any year, saved or not, because
     * session_date is computed rather than stored — a year needs no rows to exist.
     *
     * @return array<string, mixed>
     */
    public function rosterData(Request $request, ?Employee $employee): array
    {
        abort_unless($this->canAssignPresenter($request), 403);

        $year = (int) ($request->query('year') ?: now()->year);

        $saved = TotSession::with(['presenter', 'presenters'])->where('year', $year)->get()->keyBy('month');

        $slots = collect(range(1, 12))->map(fn (int $month) => $saved->get($month) ?? new TotSession([
            'year' => $year,
            'month' => $month,
            'status' => 'planned',
        ]))->all();

        return [
            'year' => $year,
            'years' => $this->availableYears($year),
            'slots' => $slots,
            'assignableEmployees' => $this->assignableEmployees(),
        ];
    }

    /**
     * Create a slot for a month that has none yet. Privileged roles set every field; a
     * tot.assign holder opens the month with only year, month and presenter_employee_id,
     * and the slot lands planned.
     */
    public function store(Request $request): RedirectResponse|JsonResponse
    {
        abort_unless($this->canAssignPresenter($request), 403);

        $privileged = $this->hasTenantRole($request, self::PRIVILEGED_ROLES);
        $tenantId = app(CurrentTenant::class)->id();

        $rules = [
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'month' => ['required', 'integer', 'min:1', 'max:12'],
        ] + $this->presenterRules($tenantId);

        if ($privileged) {
            $rules['title'] = ['nullable', 'string', 'max:200'];
            $rules['description'] = ['nullable', 'string', 'max:2000'];
            $rules['status'] = ['required', 'in:'.implode(',', TotSession::STATUSES)];
        }

        // A holder opens a month and puts a name on it. Everything else about the session,
        // including whether it happened, stays HR's decision, so their new slot is planned.
        $data = $request->validate($rules);
        $data['status'] ??= 'planned';
        $presenterIds = $this->presenterIdsFrom($request, $data);

        $exists = TotSession::where('year', $data['year'])->where('month', $data['month'])->exists();
        abort_if($exists, 422, 'That slot already exists. Edit it instead of creating it again.');

        try {
            $session = TotSession::create([
                'year' => $data['year'],
                'month' => $data['month'],
                'presenter_employee_id' => $presenterIds[0] ?? null,
                'presenter_mode' => $this->presenterModeFrom($data, $presenterIds),
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

        $session->presenters()->sync($presenterIds);

        AuditLog::record('Created TOT slot', sprintf('%04d-%02d', $session->year, $session->month));

        $this->announcePresenters($session, []);

        // The roster needs the new id: without it the client still thinks the month is
        // unsaved and re-POSTs here on the next edit, which the duplicate guard rejects.
        return $request->expectsJson()
            ? response()->json(['id' => $session->id])
            : back()->with('ok', 'TOT slot saved.');
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
        $isPresenterOfSlot = $session->isPresentedBy($employee);

        $this->authorizeSlotEdit($request, $session, $employee);
        $tenantId = app(CurrentTenant::class)->id();
        $canAssign = $this->canAssignPresenter($request);

        // The material (title, description, hours) belongs to the presenter of THIS slot or
        // a privileged role, not to a tot.assign holder: that override buys presenter_employee_id
        // and the moderator's link row below, nothing else.
        $rules = [];

        if ($privileged || $isPresenterOfSlot) {
            $rules['title'] = ['nullable', 'string', 'max:200'];
            $rules['description'] = ['nullable', 'string', 'max:2000'];
            // Blank means "the usual hour" (TotSession::DEFAULT_START/END), not midnight,
            // so an empty input nulls the column rather than storing 00:00.
            $rules['starts_at'] = ['nullable', 'date_format:H:i', 'required_with:ends_at'];
            $rules['ends_at'] = ['nullable', 'date_format:H:i', 'required_with:starts_at', 'after:starts_at'];
        }

        // Links are shared between the material owners (title/description above) and a
        // tot.assign holder — the moderator — who edits only the Nota Perbincangan row
        // (TotSession::MODERATOR_LINK_LABEL) and never sees the others (tot-edit-form.blade.php).
        if ($privileged || $isPresenterOfSlot || $canAssign) {
            $rules['links'] = ['nullable', 'array', 'max:12'];
            $rules['links.*.label'] = ['required_with:links', 'string', 'max:60'];
            $rules['links.*.url'] = ['required_with:links', 'url', 'max:2000'];
        }

        if ($canAssign) {
            $rules += $this->presenterRules($tenantId);
        }

        if ($privileged) {
            $rules['status'] = ['nullable', 'in:'.implode(',', TotSession::STATUSES)];
            $rules['held_on'] = ['nullable', 'date'];
        }

        // CR-09: chair, Nota Perbincangan link and next-month agenda belong to whoever may
        // manage the session as a whole (PRIVILEGED_ROLES, a plain manager, or the chair
        // themself) — a narrower set than $privileged and independent of tot.assign/presenter.
        if ($this->canManageSession($request, $session)) {
            $rules['chair_employee_id'] = ['nullable', 'integer', Rule::exists('employees', 'id')->where('tenant_id', $tenantId)];
            $rules['nota_url'] = ['nullable', 'url', 'max:500'];
            $rules['next_agenda'] = ['nullable', 'string', 'max:4000'];
        }

        // The editor opens with rows a slot may never fill (a blank one, and the two
        // pre-labelled Google Meet / Slide rows), so the rules below would reject the whole
        // save over a row nobody touched. Drop the untouched ones before validating. A row
        // with a label somebody typed and no URL still fails, which is the error they want.
        if (is_array($request->input('links'))) {
            $request->merge(['links' => array_values(array_filter(
                $request->input('links'),
                fn ($link) => is_array($link) && ! $this->isUntouchedLinkRow($link),
            ))]);
        }

        // validate() returns only the keys it was given rules for. A non-privileged
        // presenter has no status rule, so a hand-crafted POST carrying status never
        // reaches $data and cannot promote the slot.
        $data = $request->validate($rules);

        // Neither editor renders every row: a material owner without the tot.assign grant
        // never sees the moderator's Nota Perbincangan row, and a moderator with no material
        // access sees only that row. Either save's POST is missing what it never rendered —
        // merge the existing rows it couldn't see back in rather than let it wipe them.
        if (array_key_exists('links', $data)) {
            $seesMaterial = $privileged || $isPresenterOfSlot;

            if ($seesMaterial && ! $canAssign) {
                $data['links'] = array_merge(
                    $data['links'],
                    collect($session->links ?? [])
                        ->filter(fn ($link) => ($link['label'] ?? null) === TotSession::MODERATOR_LINK_LABEL)
                        ->all(),
                );
            } elseif (! $seesMaterial && $canAssign) {
                $data['links'] = array_merge(
                    collect($session->links ?? [])
                        ->reject(fn ($link) => ($link['label'] ?? null) === TotSession::MODERATOR_LINK_LABEL)
                        ->all(),
                    $data['links'],
                );
            }
        }

        $wasDone = $session->status === 'done';
        $previousPresenterIds = $session->presenters()->pluck('employees.id')->all();

        // Only a request that was actually allowed to carry presenters may change them —
        // otherwise a save that never rendered the picker would clear the whole team.
        $presenterIds = $canAssign && $this->carriesPresenters($request)
            ? $this->presenterIdsFrom($request, $data)
            : null;
        $presenterMode = $presenterIds === null ? null : $this->presenterModeFrom($data, $presenterIds);

        unset($data['presenter_employee_id'], $data['presenter_employee_ids'], $data['presenter_mode']);
        $session->fill($data);

        if ($presenterIds !== null) {
            $session->presenter_employee_id = $presenterIds[0] ?? null;
            $session->presenter_mode = $presenterMode;
            // presenter_name is the imported free-text fallback and is never written again;
            // a request that names real employees retires whatever legacy name was there.
            $session->presenter_name = null;
        }

        // Stamp held_on on the transition INTO done, same rule TotHistorySeeder uses for
        // imported rows, so a session marked done through the UI carries a date too. Only
        // fills a still-blank value: HR can override it afterwards and that override sticks.
        if (! $wasDone && $session->status === 'done' && $session->held_on === null) {
            $session->held_on = TotSession::firstSaturday((int) $session->year, (int) $session->month)->toDateString();
        }

        $session->save();

        if ($presenterIds !== null) {
            $session->presenters()->sync($presenterIds);
            $session->unsetRelation('presenters');
        }

        $this->announcePresenters($session, $previousPresenterIds);

        // Credit only on the transition INTO done, and only once. Presenting is the thing
        // that earns the month; editing the title afterwards is not.
        if (! $wasDone && $session->status === 'done') {
            $this->creditContribution($session);
        }

        AuditLog::record('Updated TOT slot', sprintf('%04d-%02d', $session->year, $session->month));

        return back()->with('ok', 'TOT slot updated.');
    }

    // ── CR-09: ordered slots inside a session ───────────────────────

    /** Append a new slot at the next position. Gated to canManageSession(). */
    public function storeSlot(Request $request, TotSession $session): RedirectResponse|JsonResponse
    {
        $this->assertSameTenant($session);
        abort_unless($this->canManageSession($request, $session), 403);

        $data = $request->validate($this->slotRules(app(CurrentTenant::class)->id()));

        $slot = TotSlot::create([
            'tenant_id' => $session->tenant_id,
            'session_id' => $session->id,
            'position' => (int) $session->slots()->max('position') + 1,
            'title' => $data['title'],
            'kind' => $data['kind'],
            'format' => $data['format'] ?? null,
            'status' => $data['status'] ?? null,
            'summary' => $data['summary'] ?? null,
        ]);

        $this->applySlotPresenters($slot, $data);

        AuditLog::record('Added TOT slot', sprintf('%04d-%02d: %s', $session->year, $session->month, $slot->title));

        return $request->expectsJson()
            ? response()->json(['id' => $slot->id])
            : back()->with('ok', 'Slot added.');
    }

    /** Edit a slot's fields, presenters and/or position. 404 when it is not this session's. */
    public function updateSlot(Request $request, TotSession $session, TotSlot $slot): RedirectResponse|JsonResponse
    {
        $this->assertSameTenant($session);
        $this->assertSlotInSession($session, $slot);
        abort_unless($this->canManageSession($request, $session), 403);

        $rules = $this->slotRules(app(CurrentTenant::class)->id());
        $rules['title'][0] = 'sometimes';
        $rules['kind'][0] = 'sometimes';
        $rules['position'] = ['nullable', 'integer', 'min:1'];

        $data = $request->validate($rules);

        // Fill only what this request actually carried — a position-only reorder must not
        // null out title/format/status just because Validator::validated() reports those
        // nullable keys too.
        $fillable = collect(['title', 'kind', 'format', 'status', 'summary'])
            ->filter(fn ($key) => $request->has($key))
            ->mapWithKeys(fn ($key) => [$key => $data[$key] ?? null]);
        $slot->fill($fillable->all());

        if ($request->filled('position')) {
            $slot->position = (int) $data['position'];
        }
        $slot->save();

        // Only a request that actually carries presenter fields may change the team —
        // otherwise a position-only reorder would silently wipe it (same rule the session
        // save uses, see carriesPresenters()).
        if ($request->has('presenters') || $request->has('support')) {
            $this->applySlotPresenters($slot, $data);
        }

        AuditLog::record('Updated TOT slot', sprintf('%04d-%02d: %s', $session->year, $session->month, $slot->title));

        return $request->expectsJson()
            ? response()->json(['id' => $slot->id])
            : back()->with('ok', 'Slot updated.');
    }

    /** Remove a slot entirely. Gated to canManageSession(). */
    public function destroySlot(Request $request, TotSession $session, TotSlot $slot): RedirectResponse|JsonResponse
    {
        $this->assertSameTenant($session);
        $this->assertSlotInSession($session, $slot);
        abort_unless($this->canManageSession($request, $session), 403);

        $label = sprintf('%04d-%02d: %s', $session->year, $session->month, $slot->title);
        $slot->delete();

        AuditLog::record('Deleted TOT slot', $label);

        return $request->expectsJson()
            ? response()->json(['ok' => true])
            : back()->with('ok', 'Slot removed.');
    }

    /** Anybody in the workspace may post to a slot's own discussion thread. */
    public function slotComment(Request $request, TotSession $session, TotSlot $slot): RedirectResponse|JsonResponse
    {
        $this->assertSameTenant($session);
        $this->assertSlotInSession($session, $slot);

        $employee = $request->attributes->get('employee');
        abort_unless($employee, 403, 'No employee profile in this workspace.');

        $data = $request->validate([
            'body' => ['required', 'string', 'max:2000'],
        ]);

        TotComment::create([
            'session_id' => $session->id,
            'slot_id' => $slot->id,
            'employee_id' => $employee->id,
            'body' => $data['body'],
        ]);

        return $request->expectsJson()
            ? response()->json(['comments' => $this->slotCommentRows($request, $slot)])
            : back()->with('ok', 'Comment posted.');
    }

    /** One slot's thread, oldest first, same row shape as the session thread. */
    public function slotComments(Request $request, TotSession $session, TotSlot $slot): JsonResponse
    {
        $this->assertSameTenant($session);
        $this->assertSlotInSession($session, $slot);

        return response()->json(['comments' => $this->slotCommentRows($request, $slot)]);
    }

    /** One person's reaction on one slot, same toggle rule as the session-level react(). */
    public function slotReact(Request $request, TotSession $session, TotSlot $slot): RedirectResponse|JsonResponse
    {
        $this->assertSameTenant($session);
        $this->assertSlotInSession($session, $slot);

        $employee = $request->attributes->get('employee');
        abort_unless($employee, 403, 'No employee profile in this workspace.');

        $data = $request->validate([
            'emoji' => ['required', 'string', 'in:'.implode(',', Reaction::activeKeys())],
        ]);

        $had = TotReaction::where('slot_id', $slot->id)->where('employee_id', $employee->id)->pluck('emoji');
        TotReaction::where('slot_id', $slot->id)->where('employee_id', $employee->id)->delete();

        if (! $had->contains($data['emoji'])) {
            try {
                TotReaction::create([
                    'session_id' => $session->id,
                    'slot_id' => $slot->id,
                    'employee_id' => $employee->id,
                    'emoji' => $data['emoji'],
                ]);
            } catch (QueryException $e) {
                if (! str_starts_with((string) $e->getCode(), '23')) {
                    throw $e;
                }
            }
        }

        $counts = TotReaction::where('slot_id', $slot->id)->get()->groupBy('emoji')->map->count();

        return $request->expectsJson()
            ? response()->json(['reactions' => $counts])
            : back();
    }

    // ── CR-09: attendance ────────────────────────────────────────────

    /**
     * Replaces the whole attendance list for this session. Validation runs (and can fail)
     * before anything is deleted, so a rejected save leaves the previous list untouched.
     */
    public function storeAttendance(Request $request, TotSession $session): RedirectResponse|JsonResponse
    {
        $this->assertSameTenant($session);
        abort_unless($this->canManageSession($request, $session), 403);

        $belongsToTenant = Rule::exists('employees', 'id')->where('tenant_id', $session->tenant_id);

        $data = $request->validate([
            'present' => ['present', 'array'],
            'present.*' => ['integer', $belongsToTenant],
            'absent' => ['present', 'array'],
            'absent.*.employee_id' => ['required', 'integer', $belongsToTenant],
            'absent.*.reason' => ['required', 'string', 'max:300'],
        ]);

        $presentIds = collect($data['present'])->map(fn ($id) => (int) $id)->unique()->values();
        $absentRows = collect($data['absent'])->map(fn ($row) => [
            'employee_id' => (int) $row['employee_id'], 'reason' => $row['reason'],
        ]);

        abort_if(
            $presentIds->intersect($absentRows->pluck('employee_id'))->isNotEmpty(),
            422,
            'An attendee cannot be marked both present and absent.'
        );

        DB::transaction(function () use ($session, $presentIds, $absentRows): void {
            TotAttendance::where('session_id', $session->id)->delete();

            $now = now();
            $rows = $presentIds->map(fn ($id) => [
                'tenant_id' => $session->tenant_id, 'session_id' => $session->id,
                'employee_id' => $id, 'present' => true, 'reason' => null,
                'created_at' => $now, 'updated_at' => $now,
            ])->concat($absentRows->map(fn ($row) => [
                'tenant_id' => $session->tenant_id, 'session_id' => $session->id,
                'employee_id' => $row['employee_id'], 'present' => false, 'reason' => $row['reason'],
                'created_at' => $now, 'updated_at' => $now,
            ]));

            if ($rows->isNotEmpty()) {
                DB::table('tot_attendance')->insert($rows->all());
            }
        });

        AuditLog::record(
            'Recorded TOT attendance',
            sprintf('%04d-%02d: %d hadir, %d tidak hadir', $session->year, $session->month, $presentIds->count(), $absentRows->count())
        );

        return $request->expectsJson()
            ? response()->json(['ok' => true])
            : back()->with('ok', 'Attendance saved.');
    }

    // ── CR-09: Keputusan/Tindakan Susulan ────────────────────────────

    /** Add one tindakan row. Gated to canManageSession(). */
    public function storeAction(Request $request, TotSession $session): RedirectResponse|JsonResponse
    {
        $this->assertSameTenant($session);
        abort_unless($this->canManageSession($request, $session), 403);

        $tenantId = app(CurrentTenant::class)->id();
        $belongsToTenant = Rule::exists('employees', 'id')->where('tenant_id', $tenantId);

        $data = $request->validate([
            'action' => ['required', 'string', 'max:300'],
            'owner_employee_id' => ['nullable', 'integer', $belongsToTenant],
            'target_date' => ['nullable', 'date'],
            'slot_id' => ['nullable', 'integer', Rule::exists('tot_slots', 'id')->where('session_id', $session->id)],
        ]);

        $action = TotAction::create([
            'tenant_id' => $tenantId,
            'session_id' => $session->id,
            'slot_id' => $data['slot_id'] ?? null,
            'position' => (int) $session->actions()->max('position') + 1,
            'action' => $data['action'],
            'owner_employee_id' => $data['owner_employee_id'] ?? null,
            'target_date' => $data['target_date'] ?? null,
        ]);

        AuditLog::record('Added TOT tindakan', sprintf('%04d-%02d: %s', $session->year, $session->month, $action->action));

        return $request->expectsJson()
            ? response()->json(['id' => $action->id])
            : back()->with('ok', 'Tindakan added.');
    }

    /**
     * "Create T.A.A. task": makes one work_items row for the tindakan's owner. Dates
     * contract Rule 4 — no target date means the next TOT Saturday, and the card's due
     * date is locked from here on by the model's own saving() guard, same as every card.
     */
    public function createActionCard(Request $request, TotSession $session, TotAction $action): JsonResponse
    {
        $this->assertSameTenant($session);
        abort_unless($action->session_id === $session->id, 404);
        abort_unless($this->canCreateTaaCard($request, $action), 403);
        abort_if($action->work_item_id !== null, 422, 'This tindakan already has a T.A.A. card.');
        abort_if($action->owner_employee_id === null, 422, 'This tindakan has no owner to create a card for.');

        $hasDate = $action->target_date !== null;
        $dueAt = $hasDate ? $action->target_date : $this->nextTotSaturdayAfter($session);

        $card = WorkItem::create([
            'tenant_id' => $session->tenant_id,
            'employee_id' => $action->owner_employee_id,
            'title' => $action->action,
            'type' => 'task',
            'status' => 'todo',
            'priority' => 'medium',
            'progress' => 0,
            'due_at' => $dueAt,
            'due_label' => $hasDate ? null : 'Bulan hadapan',
            'sort_order' => (int) WorkItem::where('employee_id', $action->owner_employee_id)->where('status', 'todo')->max('sort_order') + 1,
        ]);

        $action->work_item_id = $card->id;
        $action->save();

        AuditLog::record(
            'Created T.A.A. task from TOT tindakan',
            sprintf('%04d-%02d: %s', $session->year, $session->month, $action->action)
        );

        return response()->json([
            'ok' => true,
            'work_item' => ['id' => $card->id, 'due_at' => $card->due_at->format('Y-m-d'), 'due_text' => $card->due_at->format('j M Y')],
        ], 201);
    }

    /** Anybody in the workspace may post to a session thread. */
    public function comment(Request $request, TotSession $session): RedirectResponse|JsonResponse
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

        return $request->expectsJson()
            ? response()->json($this->sessionState($request, $session))
            : back()->with('ok', 'Comment posted.');
    }

    /**
     * One session's thread, oldest first. Loaded when the comment modal opens rather than
     * with the screen, so twelve months of discussion no longer ride along with every page
     * view of the year lineup.
     */
    public function comments(Request $request, TotSession $session): JsonResponse
    {
        $this->assertSameTenant($session);

        $employee = $request->attributes->get('employee');
        $privileged = $this->hasTenantRole($request, self::PRIVILEGED_ROLES);
        $presenterIds = $session->presenterList()->pluck('id');

        $rows = TotComment::with('employee')
            ->where('session_id', $session->id)
            ->whereNull('slot_id')
            ->orderBy('created_at')
            ->get()
            ->map(fn (TotComment $c) => [
                'id' => $c->id,
                'name' => $c->employee->display_name,
                'initials' => $c->employee->initials ?? '',
                'color' => $c->employee->avatar_color ?? '#3a6ea5',
                'presenter' => $presenterIds->contains($c->employee_id),
                'body' => $c->body,
                'at' => $c->created_at?->format('j M') ?? '',
                'canDelete' => $privileged || ($employee && $c->employee_id === $employee->id),
            ])
            ->all();

        $summary = $this->visibleScores(collect([$session->id => $session]), $employee, $privileged);

        return response()->json([
            'comments' => $rows,
            'notes' => $summary[$session->id]['notes'] ?? [],
        ]);
    }

    /** The author removes their own comment; HR and management may remove any. */
    public function deleteComment(Request $request, TotComment $comment): RedirectResponse|JsonResponse
    {
        $this->assertSameTenant($comment);

        $employee = $request->attributes->get('employee');

        abort_unless(
            $this->hasTenantRole($request, self::PRIVILEGED_ROLES)
                || ($employee && $comment->employee_id === $employee->id),
            403,
            'You can only delete your own comment.'
        );

        $session = $comment->session;
        $comment->delete();

        return $request->expectsJson()
            ? response()->json($this->sessionState($request, $session))
            : back()->with('ok', 'Comment removed.');
    }

    /**
     * Record or replace the acting employee's reaction (one emoji per person per session).
     * Pressing the same emoji removes it; a different emoji replaces the existing one.
     */
    public function react(Request $request, TotSession $session): RedirectResponse|JsonResponse
    {
        $this->assertSameTenant($session);

        $employee = $request->attributes->get('employee');
        abort_unless($employee, 403, 'No employee profile in this workspace.');

        // CR-30: a key from the tenant's reaction set, stored in the emoji column.
        $data = $request->validate([
            'reaction' => ['required', 'string', 'in:'.implode(',', Reaction::activeKeys())],
        ]);
        $data['emoji'] = $data['reaction'];

        // One emoji per person per session. Whatever they had goes, and only a
        // genuinely different emoji comes back — pressing the one you already
        // left is the undo.
        $had = TotReaction::where('session_id', $session->id)
            ->whereNull('slot_id')
            ->where('employee_id', $employee->id)
            ->pluck('emoji');

        TotReaction::where('session_id', $session->id)
            ->whereNull('slot_id')
            ->where('employee_id', $employee->id)
            ->delete();

        if (! $had->contains($data['emoji'])) {
            try {
                TotReaction::create([
                    'session_id' => $session->id,
                    'employee_id' => $employee->id,
                    'emoji' => $data['emoji'],
                ]);
            } catch (QueryException $e) {
                // 23xxx = the unique (session_id, employee_id, emoji) guard raced by a
                // concurrent insert of the same emoji. The end state is what this
                // request wanted, so there is nothing to do.
                if (! str_starts_with((string) $e->getCode(), '23')) {
                    throw $e;
                }
            }
        }

        return $request->expectsJson()
            ? response()->json($this->sessionState($request, $session))
            : back();
    }

    /** Mark the session watched for the acting employee. Idempotent. */
    public function watched(Request $request, TotSession $session): RedirectResponse|JsonResponse
    {
        $this->assertSameTenant($session);

        $employee = $request->attributes->get('employee');
        abort_unless($employee, 403, 'No employee profile in this workspace.');

        $row = TotParticipation::firstOrNew([
            'session_id' => $session->id,
            'employee_id' => $employee->id,
        ]);

        // A toggle, not a latch. Pressing a lit eye takes the mark back.
        // Any score on this row survives: they are separate facts, and silently
        // dropping somebody's rating because they un-marked watched would be a
        // worse surprise than the mild inconsistency of keeping it.
        $row->watched_at = $row->watched_at ? null : now();

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

        return $request->expectsJson()
            ? response()->json($this->sessionState($request, $session))
            : back()->with('ok', 'Marked as watched.');
    }

    /**
     * Record or replace the acting employee's rating.
     *
     * The row carries employee_id so a person can rate once and edit it later, which makes
     * the rating pseudonymous rather than anonymous. No screen ever renders who scored what,
     * and only the presenter and privileged roles see scores at all. Rating implies watching,
     * so the same call stamps watched_at.
     */
    public function rate(Request $request, TotSession $session): RedirectResponse|JsonResponse
    {
        $this->assertSameTenant($session);

        $employee = $request->attributes->get('employee');
        abort_unless($employee, 403, 'No employee profile in this workspace.');

        $data = $request->validate([
            // present + nullable, not nullable alone: clearing a rating must be an
            // explicit "score": null, so a request that merely forgets the key cannot
            // silently wipe somebody's score.
            'score' => ['present', 'nullable', 'integer', 'min:1', 'max:5'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $row = TotParticipation::firstOrNew([
            'session_id' => $session->id,
            'employee_id' => $employee->id,
        ]);

        if ($data['score'] === null) {
            // Nothing to clear, and creating the row here would mark the caller
            // watched as a side effect of a no-op.
            if (! $row->exists) {
                return $request->expectsJson()
                    ? response()->json($this->sessionState($request, $session))
                    : back();
            }

            // The note goes with the score. A note with no score is orphaned, and
            // the presenter would read it with nothing to read it against.
            $row->score = null;
            $row->note = null;
        } else {
            $row->score = $data['score'];
            // The box is prefilled from the rater's own note, so a blank box now means
            // clear it, while a score-only submit from the flyout carries no note key
            // at all and leaves the note alone.
            if ($request->has('note')) {
                $row->note = $request->input('note') === '' ? null : $data['note'];
            }
            $row->watched_at ??= now();
        }

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
            if ($request->has('note')) {
                $row->note = $request->input('note') === '' ? null : $data['note'];
            }
            $row->watched_at ??= now();
            $row->save();
        }

        return $request->expectsJson()
            ? response()->json($this->sessionState($request, $session))
            : back()->with('ok', 'Thanks, your rating was saved.');
    }

    /**
     * Everything a session card draws, for the acting viewer.
     *
     * One method so the JSON a live action returns and the data the screen renders can never
     * drift apart, and so the privacy rule has exactly one home: the score summary is present
     * only for a viewer visibleScores() would show it to, and it carries an average and a
     * count, never a name and never the anonymous notes.
     *
     * @return array<string, mixed>
     */
    private function sessionState(Request $request, TotSession $session): array
    {
        $employee = $request->attributes->get('employee');
        $privileged = $this->hasTenantRole($request, self::PRIVILEGED_ROLES);

        $mine = $employee
            ? TotReaction::where('session_id', $session->id)
                ->whereNull('slot_id')
                ->where('employee_id', $employee->id)
                ->pluck('emoji')->all()
            : [];

        $participation = $employee
            ? TotParticipation::where('session_id', $session->id)
                ->where('employee_id', $employee->id)
                ->first()
            : null;

        $summary = $this->visibleScores(
            collect([$session->id => $session]),
            $employee,
            $privileged
        )[$session->id] ?? null;

        return [
            'id' => $session->id,
            'reactions' => $this->reactionCounts([$session->id])[$session->id] ?? [],
            'mine' => $mine,
            'watched' => $this->watchedCounts([$session->id])[$session->id] ?? 0,
            'iWatched' => $participation?->watched_at !== null,
            'comments' => TotComment::where('session_id', $session->id)->whereNull('slot_id')->count(),
            'myScore' => $participation?->score,
            'myNote' => $participation?->note,
            'score' => $summary === null ? null : [
                'average' => $summary['average'],
                'count' => $summary['count'],
            ],
        ];
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
     * A link row nobody filled in: no URL, and either no label or one of the labels the
     * editor puts there itself. Dropped on save rather than rejected.
     *
     * @param  array<string, mixed>  $link
     */
    private function isUntouchedLinkRow(array $link): bool
    {
        if (filled($link['url'] ?? null)) {
            return false;
        }

        return blank($link['label'] ?? null)
            || in_array($link['label'], TotSession::DEFAULT_LINK_LABELS, true)
            || $link['label'] === TotSession::MODERATOR_LINK_LABEL;
    }

    /**
     * Tell everybody newly added to this slot's presenters, and record who decided it.
     *
     * Only the people added by this save are told, so re-saving the same team is silent and
     * adding a second presenter does not re-notify the first. Somebody removed is told
     * nothing: they see it on the screen, and a "you are no longer presenting" message is
     * noise.
     *
     * @param  array<int, int>  $previousEmployeeIds
     */
    private function announcePresenters(TotSession $session, array $previousEmployeeIds): void
    {
        // load(), not loadMissing(): the pivot changed a moment ago, so a relation loaded
        // before that write would still list the people being replaced.
        $session->load('presenters');
        $current = $session->presenters->pluck('id')->all();

        if (array_values(array_diff($current, $previousEmployeeIds)) === []
            && array_values(array_diff($previousEmployeeIds, $current)) === []) {
            return;
        }

        AuditLog::record(
            'Assigned TOT presenter',
            sprintf('%04d-%02d', $session->year, $session->month)
        );

        $added = array_diff($current, $previousEmployeeIds);

        foreach ($session->presenters->whereIn('id', $added) as $presenter) {
            AppNotification::send(
                $presenter->user_id,
                'You are presenting TOT on '.$session->session_date->format('j F Y'),
                'Pick your topic and upload your slides on the TOT board.',
                route('app.screen', 'tot').'?year='.$session->year,
                "tot:{$session->id}:assigned:{$presenter->id}",
                mail: true,
            );
        }
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

    /**
     * 403 unless the actor is privileged, holds tot.assign, is the presenter of this slot,
     * or (CR-09) may manage the session as a whole AND the request only touches the
     * session-management fields (chair_employee_id/nota_url/next_agenda) that
     * canManageSession() gates below — a plain manager or the session's chair gets no
     * wider access than that through this same endpoint; a request that also carries the
     * legacy material/presenter/status fields still needs canAssignPresenter or to be the
     * presenter, exactly as before CR-09.
     */
    private function authorizeSlotEdit(Request $request, TotSession $session, ?Employee $employee): void
    {
        if ($this->canAssignPresenter($request)) {
            return;
        }

        $sessionOnlyFields = ['chair_employee_id', 'nota_url', 'next_agenda', 'year', 'month', '_token'];
        if ($this->canManageSession($request, $session) && ! array_diff(array_keys($request->all()), $sessionOnlyFields)) {
            return;
        }

        abort_unless(
            $session->isPresentedBy($employee),
            403,
            'Only HR, management, the TOT organiser, or the presenter of this session can edit it.'
        );
    }

    /**
     * The presenter picker accepts either shape: `presenter_employee_ids` (the drawer's
     * solo/team picker) or the singular `presenter_employee_id` the roster screen posts on
     * every click. Both validate each id against the acting tenant.
     *
     * @return array<string, mixed>
     */
    private function presenterRules(int $tenantId): array
    {
        $belongsToTenant = Rule::exists('employees', 'id')->where('tenant_id', $tenantId);

        return [
            'presenter_employee_id' => ['nullable', 'integer', $belongsToTenant],
            'presenter_employee_ids' => ['nullable', 'array', 'max:12'],
            'presenter_employee_ids.*' => ['integer', $belongsToTenant],
            'presenter_mode' => ['nullable', 'in:'.implode(',', TotSession::PRESENTER_MODES)],
        ];
    }

    /**
     * True when this request set out to decide the presenters at all.
     *
     * The picker posts no id inputs when the team is empty, so it also sends a marker —
     * without it, clearing every presenter would be indistinguishable from a save that
     * never rendered the picker, and the team would silently survive.
     */
    private function carriesPresenters(Request $request): bool
    {
        return $request->has('presenter_employee_ids')
            || $request->has('presenter_employee_id')
            || $request->boolean('presenters_submitted');
    }

    /**
     * The presenters this request decided, as a de-duplicated list of ids. An empty list
     * means "nobody presents this slot", which is a valid state.
     *
     * @param  array<string, mixed>  $data
     * @return array<int, int>
     */
    private function presenterIdsFrom(Request $request, array $data): array
    {
        $ids = $request->has('presenter_employee_ids')
            ? ($data['presenter_employee_ids'] ?? [])
            : array_filter([$data['presenter_employee_id'] ?? null]);

        return collect($ids)->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    /**
     * The mode this request decided. The roster screen posts a single presenter and no mode
     * at all, so that path stays solo; more than one person is a team whatever was posted.
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, int>  $presenterIds
     */
    private function presenterModeFrom(array $data, array $presenterIds): string
    {
        if (count($presenterIds) > 1) {
            return 'team';
        }

        return in_array($data['presenter_mode'] ?? null, TotSession::PRESENTER_MODES, true)
            ? $data['presenter_mode']
            : 'solo';
    }

    // ── CR-09 helpers ────────────────────────────────────────────────

    /** Who may edit this session, its slots, attendance and tindakan (see TotSession::isManagedBy). */
    private function canManageSession(Request $request, TotSession $session): bool
    {
        return $session->isManagedBy($this->tenantRole($request), $request->attributes->get('employee'));
    }

    /** Who may click "Create T.A.A. task" on this tindakan (see TotAction::canCreateCardBy). */
    private function canCreateTaaCard(Request $request, TotAction $action): bool
    {
        return $action->canCreateCardBy($this->tenantRole($request), $request->attributes->get('employee'));
    }

    /** 404 unless the route-bound slot actually belongs to the route-bound session. */
    private function assertSlotInSession(TotSession $session, TotSlot $slot): void
    {
        abort_unless($slot->session_id === $session->id, 404);
    }

    /** Validation rules shared by storeSlot() and updateSlot(). */
    private function slotRules(int $tenantId): array
    {
        $belongsToTenant = Rule::exists('employees', 'id')->where('tenant_id', $tenantId);

        return [
            'title' => ['required', 'string', 'max:200'],
            'kind' => ['required', Rule::in(TotSlot::KINDS)],
            'format' => ['nullable', Rule::in(TotSlot::FORMATS)],
            'status' => ['nullable', Rule::in(TotSlot::STATUSES)],
            'presenter_mode' => ['nullable', Rule::in(TotSlot::PRESENTER_MODES)],
            'presenters' => ['nullable', 'array'],
            'presenters.*' => ['integer', $belongsToTenant],
            'support' => ['nullable', 'array'],
            'support.*' => ['integer', $belongsToTenant],
            'summary' => ['nullable', 'string', 'max:4000'],
        ];
    }

    /**
     * The presenter team for one slot: the union of `presenters` and `support`, with a
     * `support` (sokongan) row's pivot flag set. Mirrors TotSession's own solo/team pivot
     * pattern (presenterIdsFrom/presenterModeFrom) but keyed on the slot's own presenter_mode
     * column rather than a derived count, same reasoning as add_presenter_mode_to_tot_sessions.
     *
     * @param  array<string, mixed>  $data
     */
    private function applySlotPresenters(TotSlot $slot, array $data): void
    {
        $presenters = collect($data['presenters'] ?? [])->map(fn ($id) => (int) $id);
        $support = collect($data['support'] ?? [])->map(fn ($id) => (int) $id);
        $union = $presenters->merge($support)->unique()->values();

        $slot->presenters()->sync(
            $union->mapWithKeys(fn ($id) => [$id => ['support' => $support->contains($id)]])->all()
        );

        $slot->presenter_mode = in_array($data['presenter_mode'] ?? null, TotSlot::PRESENTER_MODES, true)
            ? $data['presenter_mode']
            : ($union->count() > 1 ? 'team' : 'solo');
        $slot->save();
        $slot->unsetRelation('presenters');
    }

    /**
     * One slot's discussion thread, oldest first, same row shape as the session-level
     * thread (comments()) but "presenter" means presenter of THIS slot.
     *
     * @return list<array<string, mixed>>
     */
    private function slotCommentRows(Request $request, TotSlot $slot): array
    {
        $employee = $request->attributes->get('employee');
        $privileged = $this->hasTenantRole($request, self::PRIVILEGED_ROLES);
        $presenterIds = $slot->presenters()->pluck('employees.id');

        return TotComment::with('employee')
            ->where('slot_id', $slot->id)
            ->orderBy('created_at')
            ->get()
            ->map(fn (TotComment $c) => [
                'id' => $c->id,
                'name' => $c->employee->display_name,
                'initials' => $c->employee->initials ?? '',
                'color' => $c->employee->avatar_color ?? '#3a6ea5',
                'presenter' => $presenterIds->contains($c->employee_id),
                'body' => $c->body,
                'at' => $c->created_at?->format('j M') ?? '',
                'canDelete' => $privileged || ($employee && $c->employee_id === $employee->id),
            ])
            ->all();
    }

    /** The first Saturday of the month after the session's own month/year. */
    private function nextTotSaturdayAfter(TotSession $session): Carbon
    {
        $month = (int) $session->month + 1;
        $year = (int) $session->year;
        if ($month > 12) {
            $month = 1;
            $year++;
        }

        return TotSession::firstSaturday($year, $month);
    }

    /**
     * The people the presenter picker offers, by name rather than by database id: the person
     * who runs the roster is not HR and has no reason to know anybody's numeric id.
     *
     * Employee::active() (not status = 'active'), because archiving is the separate
     * archived_at column. Filtering on the status column would drop probation and on-leave
     * staff, who present TOT sessions like everybody else.
     *
     * @return Collection<int, Employee>
     */
    private function assignableEmployees(): Collection
    {
        return Employee::active()->orderBy('name')->get(['id', 'name', 'nickname']);
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
            ->whereNull('slot_id')
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
            ->whereNull('slot_id')
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
            fn (TotSession $s) => $privileged || $s->isPresentedBy($employee)
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
     * How many comments each session has. The card shows a number; the thread itself loads
     * only when somebody opens the modal.
     *
     * @param  list<int>  $ids
     * @return array<int, int>
     */
    private function commentCounts(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return TotComment::whereIn('session_id', $ids)
            ->whereNull('slot_id')
            ->selectRaw('session_id, count(*) as aggregate')
            ->groupBy('session_id')
            ->pluck('aggregate', 'session_id')
            ->map(fn ($n) => (int) $n)
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
        // A team presents together, so the month counts for every one of them.
        foreach ($session->presenterList() as $presenter) {
            KnowledgeContribution::mark($presenter, (int) $session->year, (int) $session->month);
        }
    }
}
