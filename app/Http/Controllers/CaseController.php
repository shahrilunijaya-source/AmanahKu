<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\DisciplinaryCase;
use App\Models\Employee;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * Disciplinary & grievance cases — CONFIDENTIAL HR case management.
 *
 * Access is restricted to management and HR only. Plain employees (and managers)
 * must NOT see any case data: gating happens at the data layer (screenData returns
 * an empty shape for non-privileged roles) as well as at every write endpoint.
 */
class CaseController extends Controller
{
    private const PRIVILEGED_ROLES = ['management', 'hr'];

    private const TYPES = ['warning', 'grievance', 'investigation'];

    private const SEVERITIES = ['low', 'medium', 'high'];

    private const STATUSES = ['open', 'investigating', 'resolved', 'closed'];

    /**
     * Build the cases screen data. Tenant scope is automatic via BelongsToTenant.
     *
     * Privileged roles (management/hr) get every case grouped by status, an employee
     * picker for the open-case form, and per-status counts. NON-PRIVILEGED roles get
     * an empty shape with NO case data whatsoever — this is sensitive HR information
     * and is gated here at the data layer, never merely hidden in the template.
     *
     * @return array<string, mixed>
     */
    public function screenData(Request $request, ?Employee $employee): array
    {
        if (! $this->isPrivileged($request)) {
            return [
                'privileged' => false,
                'cases' => new Collection,
                'grouped' => new Collection,
                'employees' => new Collection,
                'counts' => $this->emptyCounts(),
                'types' => self::TYPES,
                'severities' => self::SEVERITIES,
                'statuses' => self::STATUSES,
            ];
        }

        $cases = DisciplinaryCase::with(['employee', 'openedBy'])
            ->orderByDesc('created_at')->get();

        // grouped[status] = collection of cases in that status (every status present).
        $grouped = (new Collection(self::STATUSES))
            ->mapWithKeys(fn (string $s) => [$s => $cases->where('status', $s)->values()]);

        return [
            'privileged' => true,
            'cases' => $cases,
            'grouped' => $grouped,
            'employees' => Employee::active()->orderBy('name')->get(['id', 'name', 'initials', 'avatar_color']),
            'counts' => (new Collection(self::STATUSES))
                ->mapWithKeys(fn (string $s) => [$s => $cases->where('status', $s)->count()])
                ->all(),
            'types' => self::TYPES,
            'severities' => self::SEVERITIES,
            'statuses' => self::STATUSES,
        ];
    }

    /** Privileged only: open a new case against/for an employee in this tenant. */
    public function store(Request $request): RedirectResponse
    {
        abort_unless($this->isPrivileged($request), 403);

        $tenantId = app(CurrentTenant::class)->id();

        $data = $request->validate([
            'employee_id' => [
                'required', 'integer',
                Rule::exists('employees', 'id')->where('tenant_id', $tenantId),
            ],
            'type' => ['required', Rule::in(self::TYPES)],
            'severity' => ['required', Rule::in(self::SEVERITIES)],
            'subject' => ['required', 'string', 'max:150'],
            'details' => ['required', 'string', 'max:5000'],
        ]);

        // Bind the opener to the acting employee when available. tenant_id is
        // auto-filled by BelongsToTenant.
        $opener = $request->attributes->get('employee');

        DisciplinaryCase::create([
            'employee_id' => $data['employee_id'],
            'opened_by_employee_id' => $opener?->id,
            'type' => $data['type'],
            'severity' => $data['severity'],
            'subject' => $data['subject'],
            'details' => $data['details'],
            'status' => 'open',
        ]);

        AuditLog::record('Opened case', $data['subject'].' · '.$data['type']);

        return back()->with('ok', 'Case opened — '.$data['subject'].'.');
    }

    /** Privileged only: move the case status and record an outcome note. */
    public function update(Request $request, DisciplinaryCase $case): RedirectResponse
    {
        abort_unless($this->isPrivileged($request), 403);
        abort_unless($case->tenant_id === app(CurrentTenant::class)->id(), 403);

        $data = $request->validate([
            'status' => ['required', Rule::in(self::STATUSES)],
            'outcome' => ['nullable', 'string', 'max:5000'],
        ]);

        $case->update([
            'status' => $data['status'],
            'outcome' => $data['outcome'] ?? null,
        ]);

        AuditLog::record('Updated case', $case->subject.' · '.$data['status']);

        return back()->with('ok', 'Case updated — '.$case->subject.'.');
    }

    /** Cases are confidential — management and HR only. */
    private function isPrivileged(Request $request): bool
    {
        return $this->hasTenantRole($request, self::PRIVILEGED_ROLES);
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
