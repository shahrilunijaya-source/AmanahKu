<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\FeedbackAttachment;
use App\Models\FeedbackItem;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FeedbackController extends Controller
{
    private const TYPES = ['bug', 'idea'];

    /** Triage lifecycle for a submitted item (migration default is 'open'). */
    private const STATUSES = ['open', 'reviewing', 'resolved', 'declined'];

    /** Only management/HR triage the inbox. */
    private const PRIVILEGED_ROLES = ['management', 'hr'];

    /** Anyone who oversees staff may read the inbox (and its attachments): mirrors AppController::canSeeAll. */
    private const OVERSIGHT_ROLES = ['manager', 'management', 'hr'];

    /** Private disk feedback screenshots/documents live on — reached only via attachment(). */
    private const ATTACHMENT_DISK = 'local';

    /** Ceiling on files per report, and the accepted extensions (images + PDF + Office docs). */
    private const MAX_ATTACHMENTS = 6;

    private const ATTACHMENT_MIMES = 'jpg,jpeg,png,gif,webp,pdf,doc,docx,xls,xlsx,txt,csv';

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
            // Pasted screenshots + uploaded documents. Each capped at 8 MB; whole set capped
            // at MAX_ATTACHMENTS. Same mimes/size discipline as claim receipts + leave docs.
            'attachments' => ['nullable', 'array', 'max:'.self::MAX_ATTACHMENTS],
            'attachments.*' => ['file', 'mimes:'.self::ATTACHMENT_MIMES, 'max:8192'],
        ], [
            'attachments.max' => 'You can attach up to '.self::MAX_ATTACHMENTS.' files.',
            'attachments.*.mimes' => 'Attachments must be an image, PDF, or Office document.',
            'attachments.*.max' => 'Each attachment must be 8 MB or smaller.',
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

        // Persist each file to the private disk and hang a row off the item. Storing after
        // the item exists keeps orphan files impossible if validation above rejects the batch.
        foreach ((array) $request->file('attachments', []) as $file) {
            if (! $file || ! $file->isValid()) {
                continue;
            }
            $path = $file->store('feedback-attachments', self::ATTACHMENT_DISK);
            abort_unless($path !== false, 500, 'Attachment could not be stored.');
            $item->attachments()->create([
                'tenant_id' => $item->tenant_id,
                'path' => $path,
                'name' => $file->getClientOriginalName() ?: 'attachment',
                'mime' => $file->getClientMimeType(),
                'size' => $file->getSize() ?? 0,
            ]);
        }

        $count = $item->attachments()->count();
        AuditLog::record('Submitted feedback', ucfirst($item->type).' · '.$item->title
            .($count ? ' · '.$count.' attachment'.($count === 1 ? '' : 's') : ''));

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

        $items = FeedbackItem::with(['user:id,name', 'employee:id,name,initials,avatar_color', 'attachments'])
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

    /**
     * Stream a feedback attachment through an auth-gated action (never a public URL) —
     * inline, so image thumbnails and PDFs render straight in the inbox. Screenshots can
     * carry whatever the reporter's screen showed, so only the reporter themselves or an
     * inbox viewer (anyone who oversees staff) may fetch one. Tenant-scoped model binding
     * already blocks cross-tenant ids; the explicit check is defence in depth.
     */
    public function attachment(Request $request, FeedbackAttachment $attachment): StreamedResponse
    {
        abort_unless($attachment->tenant_id === app(CurrentTenant::class)->id(), 403);

        $item = $attachment->feedbackItem;
        $isOwner = $item && $item->user_id === $request->user()->id;
        abort_unless($isOwner || $this->canViewInbox($request), 403);
        abort_unless(Storage::disk(self::ATTACHMENT_DISK)->exists($attachment->path), 404);

        return Storage::disk(self::ATTACHMENT_DISK)->response($attachment->path, $attachment->name);
    }

    /**
     * Who may read the inbox and its attachments. Mirrors AppController::canSeeAll: the
     * manager/management/HR roles (director collapses to management via hasTenantRole),
     * plus an employee-role user who is nonetheless a superior by the org chart.
     */
    private function canViewInbox(Request $request): bool
    {
        if ($this->hasTenantRole($request, self::OVERSIGHT_ROLES)) {
            return true;
        }

        $employee = $request->attributes->get('employee');

        return $employee !== null
            && Employee::active()->where('reports_to_id', $employee->id)->exists();
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
