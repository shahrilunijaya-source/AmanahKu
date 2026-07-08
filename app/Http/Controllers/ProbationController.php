<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\ProbationReview;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * Probation tracking — PRIVILEGED HR/management view.
 *
 * Managers, management and HR track new hires through their probation: a review
 * (start/end dates + length), scheduled check-ins (note + rating), and a final
 * decision (confirm | extend | terminate) with sign-off. Confirming a probation
 * flips the employee's status from 'probation' to 'active'.
 *
 * Access is gated at the data layer (screenData returns an empty shape for
 * non-privileged roles — no probation data leaks) AND at every write endpoint
 * (abort 403). Plain employees see only a restricted empty state.
 */
class ProbationController extends Controller
{
    private const PRIVILEGED_ROLES = ['manager', 'management', 'hr'];

    private const MILESTONES = ['30-day', '60-day', '90-day', 'Ad-hoc'];

    private const DECISIONS = ['confirm', 'extend', 'terminate'];

    private const DEFAULT_LENGTH_DAYS = 90;

    /**
     * Build the probation screen data. Tenant scope is automatic via BelongsToTenant.
     *
     * Privileged roles get active reviews (with employee + check-ins), the list of
     * employees currently on probation eligible to start a review, and summary
     * counts. NON-PRIVILEGED roles get a minimal empty payload — probation notes,
     * ratings and decisions are sensitive HR data and are gated here, never merely
     * hidden in the template.
     *
     * @return array<string, mixed>
     */
    public function screenData(Request $request, ?Employee $employee): array
    {
        if (! $this->isPrivileged($request)) {
            return ['privileged' => false];
        }

        // whereHas active() so a review whose subject has been archived drops out of the
        // active list, the counts and the due-for-decision tally — an archived person is no
        // longer a live confirmation decision. The review row survives for restore/history.
        $reviews = ProbationReview::with(['employee', 'checkins', 'decidedBy'])
            ->whereHas('employee', fn ($q) => $q->active())
            ->orderByRaw("status = 'active' desc")
            ->orderByDesc('start_date')
            ->orderByDesc('id')
            ->get();

        $active = $reviews->where('status', 'active')->values();

        // Employees still on probation with no active review yet — eligible to start one.
        $reviewedEmployeeIds = $reviews->where('status', 'active')->pluck('employee_id')->all();
        $eligible = Employee::active()->where('status', 'probation')
            ->whereNotIn('id', $reviewedEmployeeIds)
            ->orderBy('name')
            ->get(['id', 'name', 'position', 'joined_at']);

        $today = Carbon::today();
        $dueForDecision = $active->filter(
            fn (ProbationReview $r) => $r->end_date->lte($today->copy()->addDays(14))
        )->count();

        return [
            'privileged' => true,
            'reviews' => $active,
            'eligible' => $eligible,
            'milestones' => self::MILESTONES,
            'decisions' => self::DECISIONS,
            'defaultLength' => self::DEFAULT_LENGTH_DAYS,
            'counts' => [
                'on_probation' => $active->count(),
                'due_for_decision' => $dueForDecision,
                'confirmed' => $reviews->where('status', 'confirmed')->count(),
            ],
        ];
    }

    /** Privileged only: start a probation review for an employee in this tenant. */
    public function store(Request $request): RedirectResponse
    {
        abort_unless($this->isPrivileged($request), 403);

        $tenantId = app(CurrentTenant::class)->id();

        $data = $request->validate([
            'employee_id' => [
                'required', 'integer',
                Rule::exists('employees', 'id')->where('tenant_id', $tenantId),
            ],
            'start_date' => ['required', 'date'],
            'length_days' => ['required', 'integer', 'min:1', 'max:365'],
        ]);

        $start = Carbon::parse($data['start_date']);
        $end = $start->copy()->addDays((int) $data['length_days']);

        $review = ProbationReview::create([
            'employee_id' => $data['employee_id'],
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
            'length_days' => (int) $data['length_days'],
            'status' => 'active',
        ]);

        AuditLog::record('Started probation review', $review->employee?->name);

        return back()->with('ok', 'Probation review started — '.($review->employee?->name ?? 'employee').'.');
    }

    /** Privileged only: add a check-in (milestone label, note, optional rating). */
    public function checkin(Request $request, ProbationReview $review): RedirectResponse
    {
        abort_unless($this->isPrivileged($request), 403);
        abort_unless($review->tenant_id === app(CurrentTenant::class)->id(), 403);
        abort_unless(in_array($review->status, ['active', 'extended'], true), 422, 'This probation review is closed.');

        $data = $request->validate([
            'milestone' => ['required', Rule::in(self::MILESTONES)],
            'note' => ['required', 'string', 'max:5000'],
            'rating' => ['nullable', 'integer', 'min:1', 'max:5'],
            'checkin_date' => ['nullable', 'date'],
        ]);

        $review->checkins()->create([
            'milestone' => $data['milestone'],
            'note' => $data['note'],
            'rating' => $data['rating'] ?? null,
            'checkin_date' => $data['checkin_date'] ?? Carbon::today()->toDateString(),
        ]);

        AuditLog::record('Recorded probation check-in', ($review->employee?->name ?? 'employee').' · '.$data['milestone']);

        return back()->with('ok', 'Check-in recorded — '.$data['milestone'].'.');
    }

    /**
     * Privileged only: record the final decision + sign-off note.
     * confirm   → status 'confirmed', employee status 'probation' → 'active'
     * extend    → status 'extended', end_date pushed out by extend_days
     * terminate → status 'terminated'
     */
    public function decide(Request $request, ProbationReview $review): RedirectResponse
    {
        abort_unless($this->isPrivileged($request), 403);
        abort_unless($review->tenant_id === app(CurrentTenant::class)->id(), 403);
        abort_unless(in_array($review->status, ['active', 'extended'], true), 422, 'This probation review is already decided.');

        $data = $request->validate([
            'decision' => ['required', Rule::in(self::DECISIONS)],
            'decision_note' => ['nullable', 'string', 'max:5000'],
            'extend_days' => ['required_if:decision,extend', 'nullable', 'integer', 'min:1', 'max:365'],
        ]);

        $decider = $request->attributes->get('employee');

        $update = [
            'decision_note' => $data['decision_note'] ?? null,
            'decided_at' => Carbon::now(),
            'decided_by_id' => $decider?->id,
        ];

        if ($data['decision'] === 'confirm') {
            $update['status'] = 'confirmed';
            $review->update($update);
            // Confirming probation activates the employee.
            $review->employee?->update(['status' => 'active']);
            AuditLog::record('Confirmed probation', $review->employee?->name);

            return back()->with('ok', 'Probation confirmed — '.($review->employee?->name ?? 'employee').' is now active.');
        }

        if ($data['decision'] === 'extend') {
            $update['status'] = 'extended';
            $update['end_date'] = $review->end_date->copy()->addDays((int) $data['extend_days'])->toDateString();
            $review->update($update);
            AuditLog::record('Extended probation', ($review->employee?->name ?? 'employee').' · +'.$data['extend_days'].'d');

            return back()->with('ok', 'Probation extended by '.$data['extend_days'].' days.');
        }

        // terminate
        $update['status'] = 'terminated';
        $review->update($update);
        AuditLog::record('Terminated probation', $review->employee?->name);

        return back()->with('ok', 'Probation ended — '.($review->employee?->name ?? 'employee').'.');
    }

    /** Probation data is sensitive — manager, management and HR only. */
    private function isPrivileged(Request $request): bool
    {
        return in_array($request->attributes->get('tenantRole', 'employee'), self::PRIVILEGED_ROLES, true);
    }
}
