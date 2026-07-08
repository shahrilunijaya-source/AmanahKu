<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\OnboardingProfile;
use App\Models\OnboardingTask;
use App\Services\OnboardingService;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OnboardingController extends Controller
{
    /** Onboarding is manager-driven — managers, management and HR run a hire's checklist. */
    private const PRIVILEGED_ROLES = ['manager', 'management', 'hr'];

    /** Checklist tracks — must match the enum on onboarding_tasks.track. */
    private const TRACKS = ['general', 'position'];

    /**
     * Load the most relevant onboarding profile with its tasks + people. Privileged roles
     * (manager/management/HR) also receive the tenant's employee list and a "start" form
     * flag for onboarding a new hire. A non-privileged onboardee only ever sees their OWN
     * profile — another hire's mentor/manager/day-count is not theirs to view.
     */
    public function screenData(Request $request, ?Employee $employee): array
    {
        $privileged = $this->hasTenantRole($request, self::PRIVILEGED_ROLES);

        $query = OnboardingProfile::with('tasks', 'employee', 'mentor', 'manager')
            ->orderByDesc('start_date')
            ->orderByDesc('id');

        if (! $privileged) {
            $query->where('employee_id', $employee?->id ?? 0);
        }

        return [
            'profile' => $query->first(),
            'privileged' => $privileged,
            'employees' => $privileged ? Employee::active()->orderBy('name')->get(['id', 'name', 'position']) : collect(),
        ];
    }

    /** Privileged-only: open an onboarding profile for a new hire and seed its checklist. */
    public function start(Request $request, OnboardingService $onboarding): RedirectResponse
    {
        $this->authorizePrivileged($request);
        $tenantId = app(CurrentTenant::class)->id();
        $inTenant = fn (string $col = 'id') => Rule::exists('employees', $col)->where('tenant_id', $tenantId);

        $data = $request->validate([
            'employee_id' => ['required', 'integer', $inTenant()],
            'start_date' => ['required', 'date'],
            'mentor_id' => ['nullable', 'integer', $inTenant()],
            'manager_id' => ['nullable', 'integer', $inTenant()],
            'total_days' => ['nullable', 'integer', 'min:1', 'max:365'],
        ]);

        $employee = Employee::findOrFail($data['employee_id']);
        $onboarding->startOnboarding(
            $employee,
            $data['start_date'],
            $data['mentor_id'] ?? null,
            $data['manager_id'] ?? null,
            $data['total_days'] ?? 90,
        );

        return back()->with('ok', 'Onboarding started.');
    }

    /** Tick / untick an onboarding checklist item (the onboardee or a privileged role). */
    public function toggleTask(Request $request, OnboardingTask $task): RedirectResponse
    {
        // The profile resolves through the tenant global scope; assert tenant explicitly too.
        $profile = OnboardingProfile::find($task->onboarding_profile_id);
        abort_unless($profile && $profile->tenant_id === app(CurrentTenant::class)->id(), 403);

        $employee = $request->attributes->get('employee');
        $owns = $employee && $profile->employee_id === $employee->id;
        abort_unless($this->hasTenantRole($request, self::PRIVILEGED_ROLES) || $owns, 403);

        $task->update(['done' => ! $task->done]);

        return back()->with('ok', $task->done ? 'Task marked complete.' : 'Task reopened.');
    }

    /** Privileged-only: append an ad-hoc task to a hire's checklist (general or position). */
    public function addTask(Request $request, OnboardingProfile $profile): RedirectResponse
    {
        abort_unless($profile->tenant_id === app(CurrentTenant::class)->id(), 403);
        $this->authorizePrivileged($request);

        $data = $request->validate([
            'track' => ['required', Rule::in(self::TRACKS)],
            'title' => ['required', 'string', 'max:120'],
        ]);

        $profile->tasks()->create([
            'track' => $data['track'],
            'title' => trim($data['title']),
            'done' => false,
            'sort' => ((int) $profile->tasks()->max('sort')) + 1,
        ]);

        AuditLog::record('Added onboarding task', trim($data['title']));

        return back()->with('ok', 'Onboarding task added.');
    }

    /** Privileged-only: remove a task from a hire's checklist (fat-finger / wrong-track fix). */
    public function removeTask(Request $request, OnboardingTask $task): RedirectResponse
    {
        $profile = OnboardingProfile::find($task->onboarding_profile_id);
        abort_unless($profile && $profile->tenant_id === app(CurrentTenant::class)->id(), 403);
        $this->authorizePrivileged($request);

        $title = $task->title;
        $task->delete();

        AuditLog::record('Removed onboarding task', $title);

        return back()->with('ok', 'Onboarding task removed.');
    }

    private function authorizePrivileged(Request $request): void
    {
        $this->authorizeTenantRole($request, self::PRIVILEGED_ROLES);
    }
}
