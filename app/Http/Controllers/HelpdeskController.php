<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AppNotification;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class HelpdeskController extends Controller
{
    private const PRIVILEGED_ROLES = ['manager', 'management', 'hr'];

    /** Roles that may view (not necessarily act on) Bug/Idea tickets — narrower than the general privileged tier. */
    private const FEEDBACK_VIEW_ROLES = ['management', 'hr'];

    /** Public so the globally-included ticket-raise modal can build its selects without a screen-local $categories. */
    public const CATEGORIES = ['IT', 'Facilities', 'HR', 'Other', 'Bug', 'Idea'];

    /** Categories that carry Feedback's old submission shape: optional description, forced medium priority, page_url + attachments. */
    public const FEEDBACK_CATEGORIES = ['Bug', 'Idea'];

    /** Public for the same reason as CATEGORIES — read directly by partials/ticket-raise.blade.php. */
    public const PRIORITIES = ['low', 'medium', 'high', 'urgent'];

    private const STATUSES = ['open', 'in_progress', 'resolved', 'closed'];

    /** Private disk ticket screenshots/documents live on — reached only via attachment(). */
    private const ATTACHMENT_DISK = 'local';

    /** Ceiling on files per ticket, and the accepted extensions (images + PDF + Office docs). */
    private const MAX_ATTACHMENTS = 6;

    private const ATTACHMENT_MIMES = 'jpg,jpeg,png,gif,webp,pdf,doc,docx,xls,xlsx,txt,csv';

    /**
     * Build the helpdesk screen data. Tenant scope is automatic via BelongsToTenant.
     *
     * Privileged roles get every non-Bug/Idea ticket grouped by status, an assignee picker,
     * and per-status counts. Bug/Idea tickets are folded into that same board, but only for
     * viewers whose tenant role is management/hr (FEEDBACK_VIEW_ROLES) — manager and everyone
     * else never see them there, mirroring the old Feedback inbox's narrower triage tier. A
     * plain employee only ever sees the tickets they raised (myTickets is never filtered by
     * category — the raiser always sees their own Bug/Idea ticket and its resolution).
     *
     * @return array<string, mixed>
     */
    public function screenData(Request $request, ?Employee $employee): array
    {
        $privileged = $this->hasTenantRole($request, self::PRIVILEGED_ROLES);
        $canSeeFeedbackCategories = $this->hasTenantRole($request, self::FEEDBACK_VIEW_ROLES);
        $isSuperAdmin = (bool) $request->user()?->isSuperAdmin();

        if (! $privileged) {
            $myTickets = $employee
                ? Ticket::with(['assignee', 'attachments'])->where('employee_id', $employee->id)
                    ->orderByDesc('created_at')->get()
                : new Collection;

            return [
                'privileged' => false,
                'isSuperAdmin' => $isSuperAdmin,
                'myTickets' => $myTickets,
                'grouped' => new Collection,
                'employees' => new Collection,
                'counts' => $this->emptyCounts(),
                'categories' => self::CATEGORIES,
                'priorities' => self::PRIORITIES,
                'statuses' => self::STATUSES,
            ];
        }

        $boardQuery = Ticket::with(['employee', 'assignee', 'attachments']);
        if (! $canSeeFeedbackCategories) {
            $boardQuery->whereNotIn('category', self::FEEDBACK_CATEGORIES);
        }
        $tickets = $boardQuery->orderByDesc('created_at')->get();

        // grouped[status] = collection of tickets in that status (every status present).
        $grouped = (new Collection(self::STATUSES))
            ->mapWithKeys(fn (string $s) => [$s => $tickets->where('status', $s)->values()]);

        return [
            'privileged' => true,
            'isSuperAdmin' => $isSuperAdmin,
            // myTickets is built from the SAME set that raised it — not the category-filtered
            // board — so a manager who personally raised a Bug ticket still sees it here.
            'myTickets' => $employee
                ? Ticket::with(['assignee', 'attachments'])->where('employee_id', $employee->id)
                    ->orderByDesc('created_at')->get()
                : new Collection,
            'grouped' => $grouped,
            'employees' => Employee::active()->orderBy('name')->get(['id', 'name', 'nickname', 'initials', 'avatar_color']),
            'counts' => (new Collection(self::STATUSES))
                ->mapWithKeys(fn (string $s) => [$s => $tickets->where('status', $s)->count()])
                ->all(),
            'categories' => self::CATEGORIES,
            'priorities' => self::PRIORITIES,
            'statuses' => self::STATUSES,
        ];
    }

    /** Any employee in the workspace may raise a support ticket, bug report, or idea. */
    public function store(Request $request): RedirectResponse
    {
        $employee = $request->attributes->get('employee');
        abort_unless($employee, 403, 'No employee profile in this workspace.');

        $isFeedback = in_array($request->input('category'), self::FEEDBACK_CATEGORIES, true);

        // Bug/Idea trade the single free-text description for a fixed set of named fields
        // (GitHub-issue-style), JSON-encoded into that same description column below. Every
        // other category keeps the plain textarea.
        $structuredFields = match ($request->input('category')) {
            'Bug' => Ticket::BUG_DESCRIPTION_FIELDS,
            'Idea' => Ticket::IDEA_DESCRIPTION_FIELDS,
            default => null,
        };

        $rules = [
            'category' => ['required', Rule::in(self::CATEGORIES)],
            'priority' => [$isFeedback ? 'nullable' : 'required', Rule::in(self::PRIORITIES)],
            'subject' => ['required', 'string', 'max:150'],
            'page_url' => ['nullable', 'string', 'max:500'],
            // Pasted screenshots + uploaded documents on Bug/Idea tickets. Each capped at
            // 8 MB; whole set capped at MAX_ATTACHMENTS. Mirrors the old Feedback module.
            'attachments' => ['nullable', 'array', 'max:'.self::MAX_ATTACHMENTS],
            'attachments.*' => ['file', 'mimes:'.self::ATTACHMENT_MIMES, 'max:8192'],
        ];

        if ($structuredFields) {
            foreach (array_keys($structuredFields) as $key) {
                $rules[$key] = [$key === 'additional_context' ? 'nullable' : 'required', 'string', 'max:2000'];
            }
        } else {
            $rules['description'] = ['required', 'string', 'max:2000'];
        }

        $data = $request->validate($rules, [
            'attachments.max' => 'You can attach up to '.self::MAX_ATTACHMENTS.' files.',
            'attachments.*.mimes' => 'Attachments must be an image, PDF, or Office document.',
            'attachments.*.max' => 'Each attachment must be 8 MB or smaller.',
        ]);

        if ($structuredFields) {
            $description = json_encode(array_combine(
                array_keys($structuredFields),
                array_map(fn (string $key) => (string) ($data[$key] ?? ''), array_keys($structuredFields)),
            ));
        } else {
            $description = $data['description'];
        }

        // No tickets() relation is defined on Employee (and that model is off-limits),
        // so bind the raiser explicitly. tenant_id is auto-filled by BelongsToTenant.
        $ticket = Ticket::create([
            'employee_id' => $employee->id,
            'category' => $data['category'],
            'priority' => $isFeedback ? 'medium' : $data['priority'],
            'subject' => $data['subject'],
            'description' => $description,
            'page_url' => $isFeedback ? ($data['page_url'] ?? null) : null,
            'status' => 'open',
        ]);

        // Persist each file to the private disk and hang a row off the ticket. Storing
        // after the ticket exists keeps orphan files impossible if validation rejects the batch.
        // Non-Bug/Idea categories never get attachments, mirroring page_url above.
        if ($isFeedback) {
            foreach ((array) $request->file('attachments', []) as $file) {
                if (! $file || ! $file->isValid()) {
                    continue;
                }
                $path = $file->store('ticket-attachments', self::ATTACHMENT_DISK);
                abort_unless($path !== false, 500, 'Attachment could not be stored.');
                $ticket->attachments()->create([
                    'tenant_id' => $ticket->tenant_id,
                    'path' => $path,
                    'name' => $file->getClientOriginalName() ?: 'attachment',
                    'mime' => $file->getClientMimeType(),
                    'size' => $file->getSize() ?? 0,
                ]);
            }
        }

        AuditLog::record('Raised ticket', $data['subject'].' · '.$data['category']);

        return back()->with('ok', 'Ticket raised — '.$data['subject'].'.');
    }

    /**
     * Stream a ticket attachment through an auth-gated action (never a public URL) — inline,
     * so image thumbnails and PDFs render straight in the helpdesk views. The raiser always
     * gets their own attachments; everyone else needs an appropriate view tier: Bug/Idea
     * tickets are gated to FEEDBACK_VIEW_ROLES (management/hr), everything else to the
     * general PRIVILEGED_ROLES. Tenant-scoped model binding already blocks cross-tenant ids;
     * the explicit check is defence in depth.
     */
    public function attachment(Request $request, TicketAttachment $attachment): StreamedResponse
    {
        abort_unless($attachment->tenant_id === app(CurrentTenant::class)->id(), 403);

        $ticket = $attachment->ticket;
        $employee = $request->attributes->get('employee');
        $isOwner = $ticket && $employee && $ticket->employee_id === $employee->id;
        $isFeedbackCategory = $ticket && in_array($ticket->category, self::FEEDBACK_CATEGORIES, true);
        $canView = $isFeedbackCategory
            ? $this->hasTenantRole($request, self::FEEDBACK_VIEW_ROLES)
            : $this->hasTenantRole($request, self::PRIVILEGED_ROLES);

        abort_unless($isOwner || $canView, 403);
        abort_unless(Storage::disk(self::ATTACHMENT_DISK)->exists($attachment->path), 404);

        return Storage::disk(self::ATTACHMENT_DISK)->response($attachment->path, $attachment->name);
    }

    /**
     * Assign, move status, and record a resolution. Same view/act split as attachment():
     * Bug/Idea tickets need FEEDBACK_VIEW_ROLES (management/hr) — matching who can even see
     * them on the board — everything else needs the general PRIVILEGED_ROLES.
     */
    public function update(Request $request, Ticket $ticket): RedirectResponse
    {
        abort_unless($ticket->tenant_id === app(CurrentTenant::class)->id(), 403);

        if (in_array($ticket->category, self::FEEDBACK_CATEGORIES, true)) {
            $this->authorizeTenantRole($request, self::FEEDBACK_VIEW_ROLES);
        } else {
            $this->authorizeTenantRole($request, self::PRIVILEGED_ROLES);
        }

        $tenantId = app(CurrentTenant::class)->id();

        $data = $request->validate([
            'status' => ['required', Rule::in(self::STATUSES)],
            'assignee_employee_id' => [
                'nullable', 'integer',
                Rule::exists('employees', 'id')->where('tenant_id', $tenantId),
            ],
            'resolution' => ['nullable', 'string', 'max:2000'],
        ]);

        $ticket->update([
            'status' => $data['status'],
            'assignee_employee_id' => $data['assignee_employee_id'] ?? null,
            'resolution' => $data['resolution'] ?? null,
        ]);

        AuditLog::record('Updated ticket', $ticket->subject.' · '.$data['status']);
        AppNotification::send(
            $ticket->employee->user_id,
            'Ticket updated',
            $ticket->subject.' · '.$data['status'],
            route('app.screen', 'helpdesk'),
        );

        return back()->with('ok', 'Ticket updated — '.$ticket->subject.'.');
    }

    /**
     * Zeroed per-status counts for the empty (non-privileged) data shape.
     *
     * @return array<string, int>
     */
    private function emptyCounts(): array
    {
        return array_fill_keys(self::STATUSES, 0);
    }
}
