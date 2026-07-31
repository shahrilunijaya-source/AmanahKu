<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\Employee;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class LearningController extends Controller
{
    /** HR/management curate the course catalogue; everyone else browses + self-enrolls. */
    private const PRIVILEGED_ROLES = ['management', 'hr'];

    private const CATEGORIES = ['Technical', 'Leadership', 'Compliance', 'Soft Skills'];

    private const LEVELS = ['Beginner', 'Intermediate', 'Advanced'];

    /**
     * Everyone sees the active course catalogue plus their own enrollment state per
     * course (keyed by course id so the view knows what they are enrolled in and at
     * what progress). Privileged roles additionally receive a catalogue-management
     * form flag and an enrolled-count per course. Tenant scope is automatic via
     * BelongsToTenant; counts are computed in PHP to stay DB-agnostic.
     *
     * @return array<string, mixed>
     */
    public function screenData(Request $request, ?Employee $employee): array
    {
        $privileged = $this->hasTenantRole($request, self::PRIVILEGED_ROLES);

        $courses = Course::where('is_active', true)->orderBy('category')->orderBy('title')->get();

        // The employee's own enrollment per course, keyed by course id for O(1) lookup.
        $myEnrollments = $employee
            ? CourseEnrollment::where('employee_id', $employee->id)->get()->keyBy('course_id')
            : new Collection;

        $allCourses = $privileged
            ? Course::withCount('enrollments as enrolled_count')
                ->orderByDesc('is_active')->orderBy('category')->orderBy('title')->get()
            : new Collection;

        return [
            'privileged' => $privileged,
            'courses' => $courses,
            'myEnrollments' => $myEnrollments,
            'allCourses' => $allCourses,
            'canEnroll' => (bool) $employee,
        ];
    }

    /** Any employee may self-enroll in an active course — one record per course. */
    public function enroll(Request $request, Course $course): RedirectResponse
    {
        $employee = $this->resolveEmployee($request, $course);
        abort_unless((bool) $course->is_active, 422, 'This course is no longer available.');

        // The unique (course_id, employee_id) constraint guarantees a single
        // enrollment per employee per course; updateOrCreate upserts it so a
        // double-enroll is a no-op update rather than a duplicate or a 500.
        $enrollment = CourseEnrollment::firstOrNew([
            'course_id' => $course->id,
            'employee_id' => $employee->id,
        ]);

        // Only initialise on first enrollment — never reset existing progress.
        if (! $enrollment->exists) {
            $enrollment->fill([
                'tenant_id' => $course->tenant_id,
                'status' => 'enrolled',
                'progress' => 0,
                'enrolled_at' => now()->toDateString(),
            ])->save();

            AuditLog::record('Enrolled in course', $course->title);
        }

        return back()->with('ok', 'Enrolled in '.$course->title.'.');
    }

    /** Owner updates their own enrollment progress (0–100); auto-flip to completed at 100. */
    public function updateProgress(Request $request, Course $course): RedirectResponse
    {
        $employee = $this->resolveEmployee($request, $course);

        $data = $request->validate([
            'progress' => ['required', 'integer', 'min:0', 'max:100'],
        ]);

        // Resolve the actor's OWN enrollment for this course — never another employee's.
        $enrollment = CourseEnrollment::where('course_id', $course->id)
            ->where('employee_id', $employee->id)
            ->firstOrFail();

        $complete = $data['progress'] >= 100;

        $enrollment->update([
            'progress' => $data['progress'],
            'status' => $complete ? 'completed' : ($data['progress'] > 0 ? 'in_progress' : 'enrolled'),
            // Preserve the original completion date — regressing below 100 must not
            // erase the audit record of when the course was first completed.
            'completed_at' => $complete ? ($enrollment->completed_at ?? now()->toDateString()) : $enrollment->completed_at,
        ]);

        if ($complete) {
            AuditLog::record('Completed course', $course->title);
        }

        return back()->with('ok', 'Progress updated for '.$course->title.'.');
    }

    /** Owner marks their own enrollment complete. */
    public function complete(Request $request, Course $course): RedirectResponse
    {
        $employee = $this->resolveEmployee($request, $course);

        $enrollment = CourseEnrollment::where('course_id', $course->id)
            ->where('employee_id', $employee->id)
            ->firstOrFail();

        $enrollment->update([
            'status' => 'completed',
            'progress' => 100,
            'completed_at' => $enrollment->completed_at ?? now()->toDateString(),
        ]);

        AuditLog::record('Completed course', $course->title);

        return back()->with('ok', $course->title.' marked complete.');
    }

    /** Privileged-only: add a new course to the learning catalogue. */
    public function storeCourse(Request $request): RedirectResponse
    {
        abort_unless(
            $this->hasTenantRole($request, self::PRIVILEGED_ROLES),
            403,
            'Only HR and management can manage the course catalogue.'
        );

        $data = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'category' => ['required', 'in:'.implode(',', self::CATEGORIES)],
            'level' => ['required', 'in:'.implode(',', self::LEVELS)],
            'provider' => ['nullable', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:1000'],
            'duration_hours' => ['nullable', 'numeric', 'min:0', 'max:1000'],
        ]);

        $course = Course::create([
            'tenant_id' => app(CurrentTenant::class)->id(),
            'title' => $data['title'],
            'category' => $data['category'],
            'level' => $data['level'],
            'provider' => $data['provider'] ?? null,
            'description' => $data['description'] ?? null,
            'duration_hours' => $data['duration_hours'] ?? null,
            'is_active' => true,
        ]);

        AuditLog::record('Added course', $course->title);

        return back()->with('ok', $course->title.' added to the learning library.');
    }

    /**
     * Resolve the acting employee and confirm the course belongs to the active
     * tenant. Centralises the guards shared by every enrollment action.
     */
    private function resolveEmployee(Request $request, Course $course): Employee
    {
        $employee = $request->attributes->get('employee');
        abort_unless($employee, 403, 'No employee profile in this workspace.');
        abort_unless($course->tenant_id === app(CurrentTenant::class)->id(), 403);

        return $employee;
    }
}
