<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\FeedbackItem;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class FeedbackController extends Controller
{
    private const TYPES = ['bug', 'idea'];

    /** Triage lifecycle for a submitted item (migration default is 'open'). */
    private const STATUSES = ['open', 'reviewing', 'resolved', 'declined'];

    /** Only management/HR triage the inbox. */
    private const PRIVILEGED_ROLES = ['management', 'hr'];

    /**
     * Anyone signed into the workspace may report a bug or suggest an idea.
     * Reporter is bound from the session user (never trusted from input);
     * employee_id is attached when the user has an employee profile here.
     * tenant_id is auto-filled by BelongsToTenant.
     */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(self::TYPES)],
            'title' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:2000'],
            'page_url' => ['nullable', 'string', 'max:500'],
        ]);

        $employee = $request->attributes->get('employee');

        $item = FeedbackItem::create([
            'user_id' => $request->user()->id,
            'employee_id' => $employee?->id,
            'type' => $data['type'],
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'page_url' => $data['page_url'] ?? null,
            'status' => 'open',
        ]);

        AuditLog::record('Submitted feedback', ucfirst($item->type).' · '.$item->title);

        $thanks = $item->type === 'bug'
            ? 'Thanks — your bug report reached the team.'
            : 'Thanks — your idea reached the team.';

        return back()->with('ok', $thanks);
    }

    /**
     * Feedback inbox for the triage team (management/HR). Lists every submitted
     * bug/idea most-recent first, filterable by type and status, with the counts
     * that drive the filter chips. Reporter + the page they were on come along so
     * the team can reproduce fast. Tenant scope is automatic via BelongsToTenant.
     *
     * @return array<string, mixed>
     */
    public function screenData(Request $request): array
    {
        $type = $request->query('type');
        $status = $request->query('status');

        $items = FeedbackItem::with(['user:id,name', 'employee:id,name,initials,avatar_color'])
            ->when(in_array($type, self::TYPES, true), fn ($q) => $q->where('type', $type))
            ->when(in_array($status, self::STATUSES, true), fn ($q) => $q->where('status', $status))
            ->latest()
            ->get();

        // Unfiltered counts for the chips — one grouped query each, tenant-scoped.
        $byType = FeedbackItem::query()->selectRaw('type, count(*) as c')->groupBy('type')->pluck('c', 'type');
        $byStatus = FeedbackItem::query()->selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status');

        return [
            'items' => $items,
            // Managers reach this oversight surface read-only; only management/HR triage.
            'canTriage' => $this->hasTenantRole($request, self::PRIVILEGED_ROLES),
            'statuses' => self::STATUSES,
            'types' => self::TYPES,
            'activeType' => in_array($type, self::TYPES, true) ? $type : null,
            'activeStatus' => in_array($status, self::STATUSES, true) ? $status : null,
            'total' => (int) $byType->sum(),
            'openCount' => (int) ($byStatus['open'] ?? 0),
            'bugCount' => (int) ($byType['bug'] ?? 0),
            'ideaCount' => (int) ($byType['idea'] ?? 0),
            'statusCounts' => $byStatus,
        ];
    }

    /** Privileged-only: move a feedback item along its triage lifecycle. */
    public function setStatus(Request $request, FeedbackItem $feedback): RedirectResponse
    {
        $this->authorizeTenantRole($request, self::PRIVILEGED_ROLES);
        abort_unless($feedback->tenant_id === app(CurrentTenant::class)->id(), 403);

        $data = $request->validate([
            'status' => ['required', Rule::in(self::STATUSES)],
        ]);

        $feedback->update(['status' => $data['status']]);

        AuditLog::record('Triaged feedback', ucfirst($feedback->type).' · '.$feedback->title.' · '.$data['status']);

        return back()->with('ok', 'Feedback set to '.$data['status'].'.');
    }
}
