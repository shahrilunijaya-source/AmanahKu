<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Idea;
use App\Models\IdeaVote;
use App\Tenancy\CurrentTenant;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class IdeaController extends Controller
{
    /** HR/management triage idea status. */
    private const PRIVILEGED_ROLES = ['management', 'hr'];

    private const CATEGORIES = ['Process', 'Workplace', 'Tech', 'Other'];

    private const STATUSES = ['new', 'reviewing', 'accepted', 'done', 'declined'];

    /**
     * Everyone sees the idea list (most-voted first) with their own vote state.
     * Privileged roles additionally receive a triage flag so the template can
     * render status controls. Tenant scope is automatic via BelongsToTenant.
     *
     * @return array<string, mixed>
     */
    public function screenData(Request $request, ?Employee $employee): array
    {
        $privileged = $this->hasTenantRole($request, self::PRIVILEGED_ROLES);

        $ideas = Idea::withCount('votes')
            ->with('employee:id,name,initials,avatar_color')
            ->orderByDesc('votes_count')
            ->orderByDesc('id')
            ->get();

        // IDs the current employee has already voted for — drives the toggled button state.
        $votedIds = $employee
            ? IdeaVote::where('employee_id', $employee->id)->pluck('idea_id')->all()
            : [];

        return [
            'privileged' => $privileged,
            'ideas' => $ideas,
            'votedIds' => $votedIds,
            'canSubmit' => (bool) $employee,
            'categories' => self::CATEGORIES,
            'statuses' => self::STATUSES,
        ];
    }

    /** Any employee in the workspace may submit an idea. */
    public function store(Request $request): RedirectResponse
    {
        $employee = $request->attributes->get('employee');
        abort_unless($employee, 403, 'No employee profile in this workspace.');

        $data = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'body' => ['required', 'string', 'max:2000'],
            'category' => ['nullable', Rule::in(self::CATEGORIES)],
        ]);

        // Author is bound from the session employee — never trusted from input.
        // tenant_id is auto-filled by BelongsToTenant.
        $idea = Idea::create([
            'employee_id' => $employee->id,
            'title' => $data['title'],
            'body' => $data['body'],
            'category' => $data['category'] ?? null,
            'status' => 'new',
        ]);

        AuditLog::record('Submitted idea', $idea->title);

        return back()->with('ok', 'Idea submitted — "'.$idea->title.'".');
    }

    /** Any employee may toggle their single vote on an idea in their tenant. */
    public function vote(Request $request, Idea $idea): RedirectResponse
    {
        $employee = $request->attributes->get('employee');
        abort_unless($employee, 403, 'No employee profile in this workspace.');
        abort_unless($idea->tenant_id === app(CurrentTenant::class)->id(), 403);

        $existing = IdeaVote::where('idea_id', $idea->id)
            ->where('employee_id', $employee->id)
            ->first();

        if ($existing) {
            // Toggle off — remove the existing vote.
            $existing->delete();

            return back()->with('ok', 'Vote removed.');
        }

        try {
            // The unique (idea_id, employee_id) constraint enforces one vote per
            // employee at the DB level; we surface a graceful no-op on a race.
            IdeaVote::create([
                'idea_id' => $idea->id,
                'employee_id' => $employee->id,
            ]);
        } catch (QueryException $e) {
            // 23xxx = the unique (idea_id, employee_id) duplicate-vote guard. Anything else
            // is a real DB failure — never mask it behind a friendly success message.
            if (! str_starts_with((string) $e->getCode(), '23')) {
                throw $e;
            }

            return back()->with('ok', 'You have already voted for this idea.');
        }

        return back()->with('ok', 'Vote added.');
    }

    /** Privileged-only: triage an idea to a new status. */
    public function setStatus(Request $request, Idea $idea): RedirectResponse
    {
        $this->authorizeTenantRole($request, self::PRIVILEGED_ROLES);
        abort_unless($idea->tenant_id === app(CurrentTenant::class)->id(), 403);

        $data = $request->validate([
            'status' => ['required', Rule::in(self::STATUSES)],
        ]);

        $idea->update(['status' => $data['status']]);

        AuditLog::record('Triaged idea', $idea->title.' · '.$data['status']);

        return back()->with('ok', 'Idea status set to '.$data['status'].'.');
    }
}
