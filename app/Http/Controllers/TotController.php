<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AppNotification;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\ExternalTotEvent;
use App\Models\KnowledgeContribution;
use App\Models\TotComment;
use App\Models\TotParticipation;
use App\Models\TotReaction;
use App\Models\TotSession;
use App\Tenancy\CurrentTenant;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

class TotController extends Controller
{
    /** HR and management own the roster; everyone else reads, reacts, comments and rates. */
    private const PRIVILEGED_ROLES = ['management', 'hr'];

    /**
     * Who may post/remove an External TOT entry — one tier wider than the internal
     * roster's PRIVILEGED_ROLES, because a manager is often the one actually forwarded
     * the invite and shouldn't have to route it through HR first.
     */
    private const EXTERNAL_PRIVILEGED_ROLES = ['manager', 'management', 'hr'];

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

        $saved = TotSession::with(['presenter', 'presenters'])
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
            // External tab: everyone reads, only the wider EXTERNAL_PRIVILEGED_ROLES trio posts.
            'externalEvents' => ExternalTotEvent::orderByDesc('event_date')->get(),
            'canPostExternal' => $this->hasTenantRole($request, self::EXTERNAL_PRIVILEGED_ROLES),
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

        $data = $request->validate([
            'emoji' => ['required', 'string', 'in:'.implode(',', TotSession::EMOJI)],
        ]);

        // One emoji per person per session. Whatever they had goes, and only a
        // genuinely different emoji comes back — pressing the one you already
        // left is the undo.
        $had = TotReaction::where('session_id', $session->id)
            ->where('employee_id', $employee->id)
            ->pluck('emoji');

        TotReaction::where('session_id', $session->id)
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
            'comments' => TotComment::where('session_id', $session->id)->count(),
            'myScore' => $participation?->score,
            'myNote' => $participation?->note,
            'score' => $summary === null ? null : [
                'average' => $summary['average'],
                'count' => $summary['count'],
            ],
        ];
    }

    /** The External tab's privileged trio: post an external training/event. */
    public function storeExternal(Request $request): RedirectResponse
    {
        $this->authorizeTenantRole($request, self::EXTERNAL_PRIVILEGED_ROLES);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'host' => ['nullable', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'event_date' => ['required', 'date'],
            'time_label' => ['nullable', 'string', 'max:60'],
            'venue' => ['nullable', 'string', 'max:200'],
            'venue_map_url' => ['nullable', 'url', 'max:2000'],
            'registration_url' => ['nullable', 'url', 'max:2000'],
            'tagged' => ['nullable', 'array', 'max:20'],
            'tagged.*' => ['integer'],
        ]);

        $tagged = $this->taggedFromDescription($data['tagged'] ?? [], $data['description'] ?? null);
        unset($data['tagged']);

        $event = ExternalTotEvent::create([
            ...$data,
            'tagged_employee_ids' => $tagged->pluck('id')->all(),
            'tenant_id' => app(CurrentTenant::class)->id(),
            'posted_by' => $request->attributes->get('employee')?->id,
        ]);

        AppNotification::sendMany(
            $tagged->pluck('user_id')->filter()->all(),
            "You're required to attend: {$event->title}",
            collect([$event->host, $event->event_date->format('D, j M Y'), $event->time_label])->filter()->implode(' · '),
            route('app.screen', 'tot').'?tab=external',
            mail: true,
        );

        AuditLog::record('Posted external TOT event', $event->title);

        return back()->with('ok', 'External event posted.');
    }

    /**
     * The employees a poster actually @mentioned: ids they picked, narrowed to active
     * employees of this tenant (a raw id from the form is never trusted), and narrowed
     * again to the ones whose name is still written in the description — a mention the
     * poster deleted from the text before posting should not send anybody a summons.
     *
     * @param  list<int>  $ids
     * @return Collection<int, Employee>
     */
    private function taggedFromDescription(array $ids, ?string $description): Collection
    {
        if ($ids === [] || $description === null) {
            return collect();
        }

        return Employee::active()->whereIn('id', $ids)->get()
            ->filter(fn (Employee $person) => str_contains($description, '@'.$person->display_name))
            ->values();
    }

    /**
     * Edit an external training/event. Poster-only, unlike post/remove: the wider
     * EXTERNAL_PRIVILEGED_ROLES trio can still delete a bad post, but only the person who
     * wrote it may change what it says.
     */
    public function updateExternal(Request $request, ExternalTotEvent $event): RedirectResponse
    {
        $this->assertSameTenant($event);

        $employee = $request->attributes->get('employee');
        abort_unless(
            $employee && $event->posted_by === $employee->id,
            403,
            'Only the person who posted this event can edit it.'
        );

        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'host' => ['nullable', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'event_date' => ['required', 'date'],
            'time_label' => ['nullable', 'string', 'max:60'],
            'venue' => ['nullable', 'string', 'max:200'],
            'venue_map_url' => ['nullable', 'url', 'max:2000'],
            'registration_url' => ['nullable', 'url', 'max:2000'],
            'tagged' => ['nullable', 'array', 'max:20'],
            'tagged.*' => ['integer'],
        ]);

        $previouslyTagged = $event->taggedIds();
        $tagged = $this->taggedFromDescription($data['tagged'] ?? [], $data['description'] ?? null);
        unset($data['tagged']);

        $event->update([
            ...$data,
            'tagged_employee_ids' => $tagged->pluck('id')->all(),
        ]);

        // Only somebody newly tagged gets a summons — re-saving the same @mentions must
        // not re-mail everyone who was already told.
        $newlyTagged = $tagged->reject(fn (Employee $person) => in_array($person->id, $previouslyTagged, true));

        AppNotification::sendMany(
            $newlyTagged->pluck('user_id')->filter()->all(),
            "You're required to attend: {$event->title}",
            collect([$event->host, $event->event_date->format('D, j M Y'), $event->time_label])->filter()->implode(' · '),
            route('app.screen', 'tot').'?tab=external',
            mail: true,
        );

        AuditLog::record('Updated external TOT event', $event->title);

        return back()->with('ok', 'External event updated.');
    }

    /** The External tab's privileged trio: remove an external training/event. */
    public function destroyExternal(Request $request, ExternalTotEvent $event): RedirectResponse
    {
        $this->assertSameTenant($event);
        $this->authorizeTenantRole($request, self::EXTERNAL_PRIVILEGED_ROLES);

        $title = $event->title;
        $event->delete();

        AuditLog::record('Removed external TOT event', $title);

        return back()->with('ok', 'External event removed.');
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
    private function assertSameTenant(TotSession|TotComment|ExternalTotEvent $record): void
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
