<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\Shift;
use App\Models\Survey;
use App\Models\SurveyResponse;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Feature coverage for three newly-added modules: Roster, Document vault, Pulse surveys.
 * Harness (setUp / actingInTenant / hrActor) copied from CoreWritePathsTest.
 */
class ModulesBatchTest extends TestCase
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
        // Reviewers must have an employee profile in the tenant (authorizeReviewer).
        Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $hr->id,
            'name' => 'Boss', 'status' => 'active', 'workload' => 'green',
        ]);

        return $hr;
    }

    // ── Roster ────────────────────────────────────────────────────

    public function test_privileged_user_schedules_a_shift(): void
    {
        // Arrange
        $hr = $this->hrActor();

        // Act
        $response = $this->actingAs($hr)->withSession(['current_tenant' => $this->tenant->id])
            ->post('/app/roster', [
                'employee_id' => $this->employee->id,
                'date' => now()->toDateString(),
                'start_time' => '09:00',
                'end_time' => '17:00',
                'location' => 'HQ Floor 3',
                'status' => 'scheduled',
            ]);

        // Assert
        $response->assertRedirect();
        $this->assertDatabaseHas('shifts', [
            'tenant_id' => $this->tenant->id,
            'employee_id' => $this->employee->id,
            'location' => 'HQ Floor 3',
            'status' => 'scheduled',
        ]);
    }

    public function test_plain_employee_cannot_schedule_a_shift(): void
    {
        // Act
        $response = $this->actingInTenant()->post('/app/roster', [
            'employee_id' => $this->employee->id,
            'date' => now()->toDateString(),
            'start_time' => '09:00',
            'end_time' => '17:00',
            'location' => 'Sneaky',
            'status' => 'scheduled',
        ]);

        // Assert
        $response->assertForbidden();
        $this->assertDatabaseMissing('shifts', ['location' => 'Sneaky']);
    }

    public function test_privileged_user_cancels_a_shift(): void
    {
        // Arrange
        $hr = $this->hrActor();
        $shift = Shift::create([
            'tenant_id' => $this->tenant->id,
            'employee_id' => $this->employee->id,
            'date' => now()->toDateString(),
            'start_time' => '09:00',
            'end_time' => '17:00',
            'location' => 'HQ',
            'status' => 'scheduled',
        ]);

        // Act
        $response = $this->actingAs($hr)->withSession(['current_tenant' => $this->tenant->id])
            ->post("/app/roster/{$shift->id}/cancel");

        // Assert
        $response->assertRedirect();
        $this->assertSame('cancelled', $shift->fresh()->status);
    }

    // ── Document vault ────────────────────────────────────────────

    public function test_privileged_user_uploads_a_document(): void
    {
        // Arrange
        Storage::fake('local');
        $hr = $this->hrActor();
        $file = UploadedFile::fake()->create('contract.pdf', 64, 'application/pdf');

        // Act
        $response = $this->actingAs($hr)->withSession(['current_tenant' => $this->tenant->id])
            ->post('/app/documents', [
                'title' => 'Employment Contract',
                'category' => 'Contract',
                'employee_id' => $this->employee->id,
                'file' => $file,
            ]);

        // Assert
        $response->assertRedirect();
        $this->assertDatabaseHas('employee_documents', [
            'tenant_id' => $this->tenant->id,
            'employee_id' => $this->employee->id,
            'title' => 'Employment Contract',
            'category' => 'Contract',
        ]);
        $document = EmployeeDocument::where('title', 'Employment Contract')->firstOrFail();
        Storage::disk('local')->assertExists($document->file_path);
    }

    public function test_plain_employee_upload_is_forced_to_own_employee_id(): void
    {
        // Arrange — a second employee in the same tenant the attacker tries to upload "for".
        Storage::fake('local');
        $colleague = Employee::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Colleague', 'status' => 'active', 'workload' => 'green',
        ]);
        $file = UploadedFile::fake()->create('mine.pdf', 16, 'application/pdf');

        // Act — plain employee submits colleague's id as owner.
        $response = $this->actingInTenant()->post('/app/documents', [
            'title' => 'Spoofed Owner',
            'category' => 'Other',
            'employee_id' => $colleague->id,
            'file' => $file,
        ]);

        // Assert — server ignores the submitted owner and binds the document to the uploader.
        $response->assertRedirect();
        $this->assertDatabaseHas('employee_documents', [
            'title' => 'Spoofed Owner',
            'employee_id' => $this->employee->id,
            'uploaded_by_employee_id' => $this->employee->id,
        ]);
        $this->assertDatabaseMissing('employee_documents', [
            'title' => 'Spoofed Owner',
            'employee_id' => $colleague->id,
        ]);
    }

    public function test_employee_can_download_own_document(): void
    {
        // Arrange
        Storage::fake('local');
        $path = UploadedFile::fake()->create('own.pdf', 16, 'application/pdf')->store('employee-documents', 'local');
        $document = EmployeeDocument::create([
            'tenant_id' => $this->tenant->id,
            'employee_id' => $this->employee->id,
            'title' => 'My File',
            'category' => 'Other',
            'file_path' => $path,
            'original_name' => 'own.pdf',
            'mime' => 'application/pdf',
            'size' => 16,
            'uploaded_by_employee_id' => $this->employee->id,
        ]);

        // Act
        $response = $this->actingInTenant()->get("/app/documents/{$document->id}/download");

        // Assert
        $response->assertOk();
    }

    public function test_employee_cannot_download_another_employees_document(): void
    {
        // Arrange
        Storage::fake('local');
        $colleague = Employee::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Colleague', 'status' => 'active', 'workload' => 'green',
        ]);
        $path = UploadedFile::fake()->create('theirs.pdf', 16, 'application/pdf')->store('employee-documents', 'local');
        $document = EmployeeDocument::create([
            'tenant_id' => $this->tenant->id,
            'employee_id' => $colleague->id,
            'title' => 'Their File',
            'category' => 'Other',
            'file_path' => $path,
            'original_name' => 'theirs.pdf',
            'mime' => 'application/pdf',
            'size' => 16,
            'uploaded_by_employee_id' => $colleague->id,
        ]);

        // Act
        $response = $this->actingInTenant()->get("/app/documents/{$document->id}/download");

        // Assert
        $response->assertForbidden();
    }

    public function test_plain_employee_cannot_delete_another_employees_document(): void
    {
        // Arrange
        Storage::fake('local');
        $colleague = Employee::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Colleague', 'status' => 'active', 'workload' => 'green',
        ]);
        $path = UploadedFile::fake()->create('theirs.pdf', 16, 'application/pdf')->store('employee-documents', 'local');
        $document = EmployeeDocument::create([
            'tenant_id' => $this->tenant->id,
            'employee_id' => $colleague->id,
            'title' => 'Their File',
            'category' => 'Other',
            'file_path' => $path,
            'original_name' => 'theirs.pdf',
            'mime' => 'application/pdf',
            'size' => 16,
            'uploaded_by_employee_id' => $colleague->id,
        ]);

        // Act
        $response = $this->actingInTenant()->post("/app/documents/{$document->id}/delete");

        // Assert
        $response->assertForbidden();
        $this->assertDatabaseHas('employee_documents', ['id' => $document->id]);
    }

    // ── Pulse surveys ─────────────────────────────────────────────

    public function test_privileged_user_creates_an_open_survey(): void
    {
        // Arrange
        $hr = $this->hrActor();

        // Act
        $response = $this->actingAs($hr)->withSession(['current_tenant' => $this->tenant->id])
            ->post('/app/surveys', [
                'title' => 'Weekly Pulse',
                'question' => 'How was your week?',
                'type' => 'scale',
            ]);

        // Assert
        $response->assertRedirect();
        $this->assertDatabaseHas('surveys', [
            'tenant_id' => $this->tenant->id,
            'title' => 'Weekly Pulse',
            'type' => 'scale',
            'status' => 'open',
        ]);
    }

    public function test_plain_employee_cannot_create_a_survey(): void
    {
        // Act
        $response = $this->actingInTenant()->post('/app/surveys', [
            'title' => 'Sneaky Survey',
            'question' => 'May I?',
            'type' => 'scale',
        ]);

        // Assert
        $response->assertForbidden();
        $this->assertDatabaseMissing('surveys', ['title' => 'Sneaky Survey']);
    }

    public function test_employee_responds_once_to_an_open_survey(): void
    {
        // Arrange
        $survey = Survey::create([
            'tenant_id' => $this->tenant->id,
            'title' => 'Pulse', 'question' => 'Score it', 'type' => 'scale', 'status' => 'open',
        ]);

        // Act
        $response = $this->actingInTenant()->post("/app/surveys/{$survey->id}/respond", [
            'score' => 4,
            'comment' => 'Solid week',
        ]);

        // Assert
        $response->assertRedirect();
        $this->assertDatabaseHas('survey_responses', [
            'survey_id' => $survey->id,
            'employee_id' => $this->employee->id,
            'score' => 4,
        ]);
    }

    public function test_second_response_by_same_employee_is_rejected_gracefully(): void
    {
        // Arrange — employee already has one response on the survey.
        $survey = Survey::create([
            'tenant_id' => $this->tenant->id,
            'title' => 'Pulse', 'question' => 'Score it', 'type' => 'scale', 'status' => 'open',
        ]);
        SurveyResponse::create([
            'tenant_id' => $this->tenant->id,
            'survey_id' => $survey->id,
            'employee_id' => $this->employee->id,
            'score' => 3,
        ]);

        // Act — same employee tries to respond again.
        $response = $this->actingInTenant()->post("/app/surveys/{$survey->id}/respond", [
            'score' => 5,
            'comment' => 'Trying to overwrite',
        ]);

        // Assert — graceful rejection, unique constraint respected (still exactly one row).
        $response->assertSessionHasErrors('response');
        $this->assertSame(1, SurveyResponse::where('survey_id', $survey->id)
            ->where('employee_id', $this->employee->id)->count());
    }

    public function test_privileged_user_closes_a_survey(): void
    {
        // Arrange
        $hr = $this->hrActor();
        $survey = Survey::create([
            'tenant_id' => $this->tenant->id,
            'title' => 'Pulse', 'question' => 'Score it', 'type' => 'scale', 'status' => 'open',
        ]);

        // Act
        $response = $this->actingAs($hr)->withSession(['current_tenant' => $this->tenant->id])
            ->post("/app/surveys/{$survey->id}/close");

        // Assert
        $response->assertRedirect();
        $this->assertSame('closed', $survey->fresh()->status);
    }
}
