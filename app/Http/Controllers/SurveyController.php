<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Survey;
use App\Models\SurveyResponse;
use App\Tenancy\CurrentTenant;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class SurveyController extends Controller
{
    /** HR/management may create and close pulse surveys and see the results dashboard. */
    private const PRIVILEGED_ROLES = ['management', 'hr'];

    private const TYPES = ['scale', 'enps', 'text'];

    /**
     * Everyone sees open surveys plus which they have answered. Privileged roles
     * additionally receive a results dashboard (count, average, eNPS) per survey
     * and a create-form flag. Aggregates are computed in PHP to stay DB-agnostic
     * and rely on the BelongsToTenant scope for tenant isolation.
     */
    public function screenData(Request $request, ?Employee $employee): array
    {
        $role = $request->attributes->get('tenantRole', 'employee');
        $privileged = in_array($role, self::PRIVILEGED_ROLES, true);

        $open = Survey::where('status', 'open')->latest('id')->get();

        $answeredIds = $employee
            ? SurveyResponse::where('employee_id', $employee->id)->pluck('survey_id')->all()
            : [];

        $dashboard = $privileged
            ? Survey::with('responses')->latest('id')->get()->map(fn (Survey $s) => $this->summarise($s))
            : collect();

        return [
            'privileged' => $privileged,
            'openSurveys' => $open,
            'answeredIds' => $answeredIds,
            'dashboard' => $dashboard,
            'canRespond' => (bool) $employee,
        ];
    }

    /** Privileged-only: create a new open pulse survey. */
    public function store(Request $request): RedirectResponse
    {
        $this->authorizePrivileged($request);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'question' => ['required', 'string', 'max:500'],
            'type' => ['required', 'in:'.implode(',', self::TYPES)],
        ]);

        $survey = Survey::create([
            'tenant_id' => app(CurrentTenant::class)->id(),
            'title' => $data['title'],
            'question' => $data['question'],
            'type' => $data['type'],
            'status' => 'open',
            'created_by_employee_id' => $request->attributes->get('employee')?->id,
        ]);

        AuditLog::record('Created survey', $survey->title);

        return back()->with('ok', 'Survey "'.$survey->title.'" is now open.');
    }

    /** Any employee may respond once to an open survey in their tenant. */
    public function respond(Request $request, Survey $survey): RedirectResponse
    {
        $employee = $request->attributes->get('employee');
        abort_unless($employee, 403, 'No employee profile in this workspace.');
        abort_unless($survey->tenant_id === app(CurrentTenant::class)->id(), 403);
        abort_unless($survey->status === 'open', 422, 'This survey is closed.');

        $data = $this->validateResponse($request, $survey);

        try {
            // The unique (survey_id, employee_id) constraint enforces one response
            // per employee at the DB level; we surface a graceful message on conflict.
            SurveyResponse::create([
                'tenant_id' => $survey->tenant_id,
                'survey_id' => $survey->id,
                'employee_id' => $employee->id,
                'score' => $data['score'] ?? null,
                'comment' => $data['comment'] ?? null,
            ]);
        } catch (QueryException $e) {
            // 23xxx = integrity-constraint violation (the unique duplicate-response guard).
            // Anything else is a real DB error — don't mask it behind a friendly message.
            if (! str_starts_with((string) $e->getCode(), '23')) {
                throw $e;
            }

            return back()->withErrors(['response' => 'You have already responded to this survey.']);
        }

        return back()->with('ok', 'Thanks — your response was recorded.');
    }

    /** Privileged-only: close a survey to new responses. */
    public function close(Request $request, Survey $survey): RedirectResponse
    {
        $this->authorizePrivileged($request);
        abort_unless($survey->tenant_id === app(CurrentTenant::class)->id(), 403);

        $survey->update(['status' => 'closed']);

        AuditLog::record('Closed survey', $survey->title);

        return back()->with('ok', 'Survey "'.$survey->title.'" closed.');
    }

    /** Validate the response payload per survey type. */
    private function validateResponse(Request $request, Survey $survey): array
    {
        return match ($survey->type) {
            'scale' => $request->validate([
                'score' => ['required', 'integer', 'min:1', 'max:5'],
                'comment' => ['nullable', 'string', 'max:1000'],
            ]),
            'enps' => $request->validate([
                'score' => ['required', 'integer', 'min:0', 'max:10'],
                'comment' => ['nullable', 'string', 'max:1000'],
            ]),
            'text' => $request->validate([
                'comment' => ['required', 'string', 'max:1000'],
            ]),
            default => throw ValidationException::withMessages(['type' => 'Unknown survey type.']),
        };
    }

    /** Compute count / average score / eNPS for one survey, tenant-scoped in PHP. */
    private function summarise(Survey $survey): array
    {
        $responses = $survey->responses;
        $count = $responses->count();

        $scored = $responses->whereNotNull('score');
        $avg = $scored->isNotEmpty() ? round((float) $scored->avg('score'), 2) : null;

        $enps = null;
        if ($survey->type === 'enps' && $scored->isNotEmpty()) {
            $promoters = $scored->where('score', '>=', 9)->count();
            $detractors = $scored->where('score', '<=', 6)->count();
            // eNPS = %promoters − %detractors, range −100..100.
            $enps = (int) round(($promoters - $detractors) / $scored->count() * 100);
        }

        return [
            'survey' => $survey,
            'count' => $count,
            'avg' => $avg,
            'enps' => $enps,
            'comments' => $responses->whereNotNull('comment')->filter(fn ($r) => trim((string) $r->comment) !== '')->values(),
        ];
    }

    private function authorizePrivileged(Request $request): void
    {
        abort_unless(
            $this->hasTenantRole($request, self::PRIVILEGED_ROLES),
            403,
            'Only HR and management can manage surveys.'
        );
    }
}
