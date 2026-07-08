<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Feature coverage for the Learning library / LMS module.
 * Harness (setUp / actingInTenant / hrActor) copied from BenefitTest.
 */
class LearningTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Tenant $tenant;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create(['name' => 'Demo', 'email' => 'demo@example.com', 'password' => Hash::make('password')]);
        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        $this->user->tenants()->attach($this->tenant->id, ['role' => 'employee']);
        $this->employee = Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $this->user->id,
            'name' => 'Demo', 'status' => 'active', 'workload' => 'green',
        ]);
    }

    private function actingInTenant(): self
    {
        $this->actingAs($this->user)->withSession(['current_tenant' => $this->tenant->id]);

        return $this;
    }

    private function hrActor(): User
    {
        $hr = User::create(['name' => 'Boss', 'email' => 'boss@example.com', 'password' => Hash::make('password')]);
        $hr->tenants()->attach($this->tenant->id, ['role' => 'hr']);
        Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $hr->id,
            'name' => 'Boss', 'status' => 'active', 'workload' => 'green',
        ]);

        return $hr;
    }

    private function makeCourse(bool $active = true): Course
    {
        return Course::create([
            'tenant_id' => $this->tenant->id,
            'title' => 'Leadership Essentials',
            'category' => 'Leadership',
            'level' => 'Beginner',
            'provider' => 'Unijaya Academy',
            'duration_hours' => 6.0,
            'is_active' => $active,
        ]);
    }

    public function test_employee_enrolls_in_a_course(): void
    {
        // Arrange
        $course = $this->makeCourse();

        // Act
        $response = $this->actingInTenant()->post("/app/learning/{$course->id}/enroll");

        // Assert
        $response->assertRedirect();
        $this->assertDatabaseHas('course_enrollments', [
            'course_id' => $course->id,
            'employee_id' => $this->employee->id,
            'status' => 'enrolled',
            'progress' => 0,
        ]);
    }

    public function test_double_enroll_does_not_duplicate(): void
    {
        // Arrange — employee already enrolled.
        $course = $this->makeCourse();
        $this->actingInTenant()->post("/app/learning/{$course->id}/enroll");

        // Act — same employee enrolls again.
        $response = $this->actingInTenant()->post("/app/learning/{$course->id}/enroll");

        // Assert — the unique constraint held; no duplicate, no 500.
        $response->assertRedirect();
        $this->assertSame(1, CourseEnrollment::where('course_id', $course->id)
            ->where('employee_id', $this->employee->id)->count());
    }

    public function test_progress_update_flips_status_to_in_progress(): void
    {
        // Arrange
        $course = $this->makeCourse();
        $this->actingInTenant()->post("/app/learning/{$course->id}/enroll");

        // Act
        $response = $this->actingInTenant()->post("/app/learning/{$course->id}/progress", [
            'progress' => 50,
        ]);

        // Assert
        $response->assertRedirect();
        $this->assertDatabaseHas('course_enrollments', [
            'course_id' => $course->id,
            'employee_id' => $this->employee->id,
            'status' => 'in_progress',
            'progress' => 50,
        ]);
    }

    public function test_reaching_100_percent_completes_the_course(): void
    {
        // Arrange
        $course = $this->makeCourse();
        $this->actingInTenant()->post("/app/learning/{$course->id}/enroll");

        // Act
        $response = $this->actingInTenant()->post("/app/learning/{$course->id}/progress", [
            'progress' => 100,
        ]);

        // Assert — auto-flip to completed + completed_at stamped.
        $response->assertRedirect();
        $enrollment = CourseEnrollment::where('course_id', $course->id)
            ->where('employee_id', $this->employee->id)->first();
        $this->assertSame('completed', $enrollment->status);
        $this->assertSame(100, $enrollment->progress);
        $this->assertNotNull($enrollment->completed_at);
    }

    public function test_progress_regression_below_100_preserves_completed_at(): void
    {
        // Arrange — course completed first (completed_at stamped).
        $course = $this->makeCourse();
        $this->actingInTenant()->post("/app/learning/{$course->id}/enroll");
        $this->actingInTenant()->post("/app/learning/{$course->id}/progress", ['progress' => 100]);
        $firstCompletedAt = CourseEnrollment::where('course_id', $course->id)
            ->where('employee_id', $this->employee->id)->value('completed_at');
        $this->assertNotNull($firstCompletedAt);

        // Act — progress regresses below 100.
        $this->actingInTenant()->post("/app/learning/{$course->id}/progress", ['progress' => 50])
            ->assertRedirect();

        // Assert — status reverts but the original completion date is NOT erased.
        $enrollment = CourseEnrollment::where('course_id', $course->id)
            ->where('employee_id', $this->employee->id)->first();
        $this->assertSame('in_progress', $enrollment->status);
        $this->assertSame(50, $enrollment->progress);
        $this->assertNotNull($enrollment->completed_at);
    }

    public function test_mark_complete_sets_completed_and_timestamp(): void
    {
        // Arrange
        $course = $this->makeCourse();
        $this->actingInTenant()->post("/app/learning/{$course->id}/enroll");

        // Act
        $response = $this->actingInTenant()->post("/app/learning/{$course->id}/complete");

        // Assert
        $response->assertRedirect();
        $enrollment = CourseEnrollment::where('course_id', $course->id)
            ->where('employee_id', $this->employee->id)->first();
        $this->assertSame('completed', $enrollment->status);
        $this->assertSame(100, $enrollment->progress);
        $this->assertNotNull($enrollment->completed_at);
    }

    public function test_privileged_user_creates_a_course(): void
    {
        // Arrange
        $hr = $this->hrActor();

        // Act
        $response = $this->actingAs($hr)->withSession(['current_tenant' => $this->tenant->id])
            ->post('/app/learning/courses', [
                'title' => 'Excel for Analysts',
                'category' => 'Technical',
                'level' => 'Intermediate',
                'provider' => 'Microsoft',
                'duration_hours' => 8,
            ]);

        // Assert
        $response->assertRedirect();
        $this->assertDatabaseHas('courses', [
            'tenant_id' => $this->tenant->id,
            'title' => 'Excel for Analysts',
            'category' => 'Technical',
            'is_active' => true,
        ]);
    }

    public function test_plain_employee_cannot_create_a_course(): void
    {
        // Act
        $response = $this->actingInTenant()->post('/app/learning/courses', [
            'title' => 'Sneaky Course',
            'category' => 'Technical',
            'level' => 'Beginner',
        ]);

        // Assert
        $response->assertForbidden();
        $this->assertDatabaseMissing('courses', ['title' => 'Sneaky Course']);
    }
}
