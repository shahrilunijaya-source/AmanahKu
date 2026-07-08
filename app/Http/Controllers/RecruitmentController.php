<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Candidate;
use App\Models\Department;
use App\Models\Employee;
use App\Models\JobRequisition;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

class RecruitmentController extends Controller
{
    private const PRIVILEGED_ROLES = ['manager', 'management', 'hr'];

    private const REQ_STATUSES = ['open', 'on_hold', 'filled', 'closed'];

    private const STAGES = ['applied', 'screening', 'interview', 'offer', 'hired', 'rejected'];

    /**
     * Build the recruitment screen data. Tenant scope is automatic via BelongsToTenant.
     *
     * Everyone sees the read-only requisition list (with open candidate counts). A
     * selected requisition (`?req=`) loads its candidates grouped by pipeline stage.
     * Privileged roles additionally receive create/manage flags plus a department list
     * for the new-requisition form. Recruitment is HR-focused: there is no per-employee
     * personal view beyond the shared list.
     *
     * @return array<string, mixed>
     */
    public function screenData(Request $request, ?Employee $employee): array
    {
        $privileged = $this->hasTenantRole($request, self::PRIVILEGED_ROLES);

        $requisitions = JobRequisition::with('department')
            ->withCount([
                'candidates',
                'candidates as hired_count' => fn ($q) => $q->where('stage', 'hired'),
            ])
            ->orderByDesc('created_at')
            ->get();

        // Resolve the selected requisition (defaults to the first), then group its
        // candidates into a column per stage so every stage is always present.
        $selected = null;
        if ($request->filled('req')) {
            $selected = $requisitions->firstWhere('id', (int) $request->query('req'));
        }
        $selected ??= $requisitions->first();

        // Candidate data (name/email/phone/notes) is PII — only privileged roles load the
        // pipeline. Non-privileged callers get an empty pipeline regardless of ?req=.
        $pipeline = new Collection;
        if ($selected && $privileged) {
            $candidates = Candidate::where('job_requisition_id', $selected->id)
                ->orderByDesc('created_at')->get();
            $pipeline = (new Collection(self::STAGES))
                ->mapWithKeys(fn (string $s) => [$s => $candidates->where('stage', $s)->values()]);
        }

        return [
            'privileged' => $privileged,
            'requisitions' => $requisitions,
            'selected' => $selected,
            'pipeline' => $pipeline,
            'stages' => self::STAGES,
            'reqStatuses' => self::REQ_STATUSES,
            'departments' => $privileged
                ? Department::orderBy('name')->get(['id', 'name'])
                : new Collection,
        ];
    }

    /** Privileged only: open a new job requisition. */
    public function storeRequisition(Request $request): RedirectResponse
    {
        $this->authorizeTenantRole($request, self::PRIVILEGED_ROLES);

        $tenantId = app(CurrentTenant::class)->id();

        $data = $request->validate([
            'title' => ['required', 'string', 'max:150'],
            'department_id' => [
                'nullable', 'integer',
                Rule::exists('departments', 'id')->where('tenant_id', $tenantId),
            ],
            'openings' => ['required', 'integer', 'min:1', 'max:999'],
            'location' => ['nullable', 'string', 'max:120'],
        ]);

        $employee = $request->attributes->get('employee');

        // tenant_id is auto-filled by BelongsToTenant.
        JobRequisition::create([
            'title' => $data['title'],
            'department_id' => $data['department_id'] ?? null,
            'openings' => $data['openings'],
            'location' => $data['location'] ?? null,
            'status' => 'open',
            'created_by_employee_id' => $employee?->id,
        ]);

        AuditLog::record('Opened requisition', $data['title']);

        return back()->with('ok', 'Requisition opened — '.$data['title'].'.');
    }

    /** Privileged only: add a candidate to a requisition's pipeline at the applied stage. */
    public function storeCandidate(Request $request, JobRequisition $requisition): RedirectResponse
    {
        $this->authorizeTenantRole($request, self::PRIVILEGED_ROLES);
        abort_unless($requisition->tenant_id === app(CurrentTenant::class)->id(), 403);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['nullable', 'email', 'max:150'],
            'phone' => ['nullable', 'string', 'max:40'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        // Bind to the requisition explicitly; tenant_id is auto-filled by BelongsToTenant.
        $requisition->candidates()->create([
            'name' => $data['name'],
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
            'notes' => $data['notes'] ?? null,
            'stage' => 'applied',
        ]);

        AuditLog::record('Added candidate', $data['name'].' · '.$requisition->title);

        return back()->with('ok', 'Candidate added — '.$data['name'].'.');
    }

    /** Privileged only: move a candidate to another pipeline stage (with optional notes). */
    public function moveCandidate(Request $request, Candidate $candidate): RedirectResponse
    {
        $this->authorizeTenantRole($request, self::PRIVILEGED_ROLES);
        abort_unless($candidate->tenant_id === app(CurrentTenant::class)->id(), 403);

        $data = $request->validate([
            'stage' => ['required', Rule::in(self::STAGES)],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $candidate->update([
            'stage' => $data['stage'],
            'notes' => $data['notes'] ?? $candidate->notes,
        ]);

        AuditLog::record('Moved candidate', $candidate->name.' → '.$data['stage']);

        return back()->with('ok', 'Candidate updated — '.$candidate->name.'.');
    }
}
