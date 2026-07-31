<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AppNotification;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\ExitInterview;
use App\Models\Resignation;
use App\Services\OffboardingService;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

class ResignationController extends Controller
{
    /** Resignation is an HR matter — managers do NOT get the privileged exit-interview view. */
    private const PRIVILEGED_ROLES = ['management', 'hr'];

    /** Statuses a resignation may carry through its lifecycle. */
    private const REASON_CATEGORIES = ['Career Growth', 'Compensation', 'Management', 'Relocation', 'Personal', 'Other'];

    /** The four facets scored in an exit interview (1–5). */
    private const RATING_KEYS = ['management', 'culture', 'growth', 'compensation'];

    /**
     * Build the resignation screen data.
     *
     * Every employee sees their OWN active resignation (if any) or the submit form.
     * Privileged roles (management/HR) additionally receive the full tenant list of
     * resignations with employee + exit-interview eager-loaded so they can acknowledge
     * and conduct the confidential exit interview. The confidential exit-interview
     * content is gated here at the data layer — a non-privileged employee never gets
     * the all-resignations list nor any interview content.
     *
     * @return array<string, mixed>
     */
    public function screenData(Request $request, ?Employee $employee): array
    {
        $privileged = in_array($request->attributes->get('tenantRole', 'employee'), self::PRIVILEGED_ROLES, true);

        // The employee's own resignation — most recent first. Tenant scope is automatic.
        $myResignation = $employee
            ? Resignation::with('exitInterview')->where('employee_id', $employee->id)->latest('id')->first()
            : null;

        $allResignations = $privileged
            ? Resignation::with(['employee', 'exitInterview', 'offboardingCase.clearanceItems'])->latest('id')->get()
            : new Collection;

        return [
            'myResignation' => $myResignation,
            'allResignations' => $allResignations,
            'privileged' => $privileged,
            'reasonCategories' => self::REASON_CATEGORIES,
            'ratingKeys' => self::RATING_KEYS,
        ];
    }

    /** Employee submits their own resignation. One active resignation at a time. */
    public function store(Request $request): RedirectResponse
    {
        $employee = $request->attributes->get('employee');
        abort_unless($employee, 403, 'No employee profile in this workspace.');

        // Block a duplicate while one is still pending/acknowledged (not withdrawn/completed).
        $hasActive = Resignation::where('employee_id', $employee->id)
            ->whereIn('status', ['submitted', 'acknowledged'])
            ->exists();
        abort_if($hasActive, 422, 'You already have an active resignation.');

        $data = $request->validate([
            'last_working_date' => ['required', 'date', 'after_or_equal:today'],
            'notice_days' => ['required', 'integer', 'min:0', 'max:365'],
            'reason' => ['required', 'string', 'max:2000'],
        ]);

        Resignation::create([
            'employee_id' => $employee->id,
            'submitted_at' => now(),
            'last_working_date' => $data['last_working_date'],
            'notice_days' => $data['notice_days'],
            'reason' => $data['reason'],
            'status' => 'submitted',
        ]);

        AuditLog::record('Submitted resignation', $employee->name);

        return back()->with('ok', 'Resignation submitted.');
    }

    /** Privileged-only: acknowledge a pending resignation (submitted -> acknowledged) + open its clearance case. */
    public function acknowledge(Request $request, Resignation $resignation, OffboardingService $offboarding): RedirectResponse
    {
        $this->authorizePrivileged($request, $resignation);
        abort_unless($resignation->status === 'submitted', 422);

        $resignation->update([
            'status' => 'acknowledged',
            'acknowledged_at' => now(),
            'acknowledged_by_id' => $request->attributes->get('employee')?->id,
        ]);

        AuditLog::record('Acknowledged resignation', $resignation->employee?->name);
        AppNotification::send(
            $resignation->employee?->user_id,
            'Resignation acknowledged',
            'Your resignation has been acknowledged by HR.',
            route('app.screen', 'resignation'),
        );

        // Acknowledging = commitment to offboard, so open the linked clearance case immediately.
        if ($resignation->employee) {
            $offboarding->openCase(
                $resignation->employee,
                $resignation->last_working_date,
                'resignation',
                'Auto-opened from resignation.',
                $resignation,
            );
        }

        return back()->with('ok', 'Resignation acknowledged.');
    }

    /** Owner-only: withdraw their own resignation while it is still pending. */
    public function withdraw(Request $request, Resignation $resignation): RedirectResponse
    {
        abort_unless($resignation->tenant_id === app(CurrentTenant::class)->id(), 403);
        $actor = $request->attributes->get('employee');
        abort_unless($actor && $actor->id === $resignation->employee_id, 403, 'You may only withdraw your own resignation.');
        abort_unless($resignation->status === 'submitted', 422);

        $resignation->update(['status' => 'withdrawn']);

        AuditLog::record('Withdrew resignation', $resignation->employee?->name);

        return back()->with('ok', 'Resignation withdrawn.');
    }

    /** Privileged-only: create or update the confidential exit interview for a resignation. */
    public function interview(Request $request, Resignation $resignation): RedirectResponse
    {
        $this->authorizePrivileged($request, $resignation);

        $data = $request->validate([
            'reason_category' => ['required', Rule::in(self::REASON_CATEGORIES)],
            'would_recommend' => ['nullable', 'boolean'],
            'feedback' => ['nullable', 'string', 'max:5000'],
            'ratings' => ['nullable', 'array'],
            'ratings.*' => ['integer', 'min:1', 'max:5'],
        ]);

        // Keep only the known rating facets, clamped to 1–5 by validation above.
        $ratings = collect($data['ratings'] ?? [])
            ->only(self::RATING_KEYS)
            ->map(fn ($v) => (int) $v)
            ->all();

        ExitInterview::updateOrCreate(
            ['resignation_id' => $resignation->id],
            [
                'tenant_id' => $resignation->tenant_id,
                'reason_category' => $data['reason_category'],
                'would_recommend' => (bool) ($data['would_recommend'] ?? false),
                'ratings' => $ratings,
                'feedback' => $data['feedback'] ?? null,
                'conducted_by_id' => $request->attributes->get('employee')?->id,
                'conducted_at' => now(),
            ],
        );

        AuditLog::record('Recorded exit interview', $resignation->employee?->name);

        return back()->with('ok', 'Exit interview saved.');
    }

    /** Gate privileged management/HR actions and assert the record's tenant. */
    private function authorizePrivileged(Request $request, Resignation $resignation): void
    {
        $this->authorizeTenantRole($request, self::PRIVILEGED_ROLES);
        abort_unless($resignation->tenant_id === app(CurrentTenant::class)->id(), 403);
    }
}
