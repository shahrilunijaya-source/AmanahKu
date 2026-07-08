<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Ticket;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

class HelpdeskController extends Controller
{
    private const PRIVILEGED_ROLES = ['manager', 'management', 'hr'];

    private const CATEGORIES = ['IT', 'Facilities', 'HR', 'Other'];

    private const PRIORITIES = ['low', 'medium', 'high', 'urgent'];

    private const STATUSES = ['open', 'in_progress', 'resolved', 'closed'];

    /**
     * Build the helpdesk screen data. Tenant scope is automatic via BelongsToTenant.
     *
     * Privileged roles get every ticket grouped by status, an assignee picker, and
     * per-status counts. A plain employee only ever sees the tickets they raised —
     * other people's support tickets are never sent to their template.
     *
     * @return array<string, mixed>
     */
    public function screenData(Request $request, ?Employee $employee): array
    {
        $privileged = $this->hasTenantRole($request, self::PRIVILEGED_ROLES);

        if (! $privileged) {
            $myTickets = $employee
                ? Ticket::with('assignee')->where('employee_id', $employee->id)
                    ->orderByDesc('created_at')->get()
                : new Collection;

            return [
                'privileged' => false,
                'myTickets' => $myTickets,
                'grouped' => new Collection,
                'employees' => new Collection,
                'counts' => $this->emptyCounts(),
                'categories' => self::CATEGORIES,
                'priorities' => self::PRIORITIES,
                'statuses' => self::STATUSES,
            ];
        }

        $tickets = Ticket::with(['employee', 'assignee'])
            ->orderByDesc('created_at')->get();

        // grouped[status] = collection of tickets in that status (every status present).
        $grouped = (new Collection(self::STATUSES))
            ->mapWithKeys(fn (string $s) => [$s => $tickets->where('status', $s)->values()]);

        return [
            'privileged' => true,
            'myTickets' => $employee
                ? $tickets->where('employee_id', $employee->id)->values()
                : new Collection,
            'grouped' => $grouped,
            'employees' => Employee::active()->orderBy('name')->get(['id', 'name', 'initials', 'avatar_color']),
            'counts' => (new Collection(self::STATUSES))
                ->mapWithKeys(fn (string $s) => [$s => $tickets->where('status', $s)->count()])
                ->all(),
            'categories' => self::CATEGORIES,
            'priorities' => self::PRIORITIES,
            'statuses' => self::STATUSES,
        ];
    }

    /** Any employee in the workspace may raise a support ticket. */
    public function store(Request $request): RedirectResponse
    {
        $employee = $request->attributes->get('employee');
        abort_unless($employee, 403, 'No employee profile in this workspace.');

        $data = $request->validate([
            'category' => ['required', Rule::in(self::CATEGORIES)],
            'priority' => ['required', Rule::in(self::PRIORITIES)],
            'subject' => ['required', 'string', 'max:150'],
            'description' => ['required', 'string', 'max:2000'],
        ]);

        // No tickets() relation is defined on Employee (and that model is off-limits),
        // so bind the raiser explicitly. tenant_id is auto-filled by BelongsToTenant.
        Ticket::create([
            'employee_id' => $employee->id,
            'category' => $data['category'],
            'priority' => $data['priority'],
            'subject' => $data['subject'],
            'description' => $data['description'],
            'status' => 'open',
        ]);

        AuditLog::record('Raised ticket', $data['subject'].' · '.$data['category']);

        return back()->with('ok', 'Ticket raised — '.$data['subject'].'.');
    }

    /** Privileged only: assign, move status, and record a resolution. */
    public function update(Request $request, Ticket $ticket): RedirectResponse
    {
        $this->authorizeTenantRole($request, self::PRIVILEGED_ROLES);
        abort_unless($ticket->tenant_id === app(CurrentTenant::class)->id(), 403);

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
