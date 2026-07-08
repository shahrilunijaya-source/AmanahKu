<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\FeedbackItem;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Feature coverage for the Feedback hub (report a bug / suggest an idea).
 * Harness (setUp / actingInTenant) mirrors IdeaTest / CoreWritePathsTest.
 */
class FeedbackTest extends TestCase
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

    // ── Submit ────────────────────────────────────────────────────

    public function test_user_submits_a_bug_report(): void
    {
        // Act
        $response = $this->actingInTenant()->post('/app/feedback', [
            'type' => 'bug',
            'title' => 'Clock-in button does nothing',
            'description' => 'Tapping clock-in on the dashboard has no effect.',
            'page_url' => 'http://localhost/app/dash',
        ]);

        // Assert — redirect back, row scoped to the tenant and bound to the reporter.
        $response->assertRedirect();
        $response->assertSessionHas('ok');
        $this->assertDatabaseHas('feedback_items', [
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->user->id,
            'employee_id' => $this->employee->id,
            'type' => 'bug',
            'title' => 'Clock-in button does nothing',
            'page_url' => 'http://localhost/app/dash',
            'status' => 'open',
        ]);
    }

    public function test_user_submits_an_idea_without_description(): void
    {
        // Act — description is optional.
        $response = $this->actingInTenant()->post('/app/feedback', [
            'type' => 'idea',
            'title' => 'Dark mode please',
        ]);

        // Assert
        $response->assertRedirect();
        $item = FeedbackItem::withoutGlobalScopes()->latest('id')->first();
        $this->assertNotNull($item);
        $this->assertSame('idea', $item->type);
        $this->assertNull($item->description);
        $this->assertSame('open', $item->status);
    }

    // ── Reporter binding ─────────────────────────────────────────

    public function test_reporter_is_bound_from_session_not_input(): void
    {
        // Arrange — a second user we will try to impersonate via input.
        $other = User::create(['name' => 'Mallory', 'email' => 'mallory@example.com', 'password' => Hash::make('password')]);

        // Act — attempt to forge user_id / tenant_id / status in the payload.
        $this->actingInTenant()->post('/app/feedback', [
            'type' => 'bug',
            'title' => 'Forged reporter',
            'user_id' => $other->id,
            'tenant_id' => 999,
            'status' => 'resolved',
        ]);

        // Assert — bound values win; forged input is ignored.
        $item = FeedbackItem::withoutGlobalScopes()->latest('id')->first();
        $this->assertSame($this->user->id, $item->user_id);
        $this->assertSame($this->tenant->id, $item->tenant_id);
        $this->assertSame('open', $item->status);
    }

    // ── Validation ───────────────────────────────────────────────

    public function test_title_is_required(): void
    {
        $response = $this->actingInTenant()->post('/app/feedback', ['type' => 'bug']);

        $response->assertSessionHasErrors('title');
        $this->assertSame(0, FeedbackItem::withoutGlobalScopes()->count());
    }

    public function test_type_must_be_a_known_value(): void
    {
        $response = $this->actingInTenant()->post('/app/feedback', [
            'type' => 'complaint',
            'title' => 'Bad type',
        ]);

        $response->assertSessionHasErrors('type');
        $this->assertSame(0, FeedbackItem::withoutGlobalScopes()->count());
    }

    // ── Auth ──────────────────────────────────────────────────────

    public function test_guest_cannot_submit_feedback(): void
    {
        $response = $this->post('/app/feedback', ['type' => 'bug', 'title' => 'Anon']);

        $response->assertRedirect('/login');
        $this->assertSame(0, FeedbackItem::withoutGlobalScopes()->count());
    }

    // ── Inbox (triage) ────────────────────────────────────────────

    /** A management/HR user + their employee record in the same tenant. */
    private function privilegedActor(string $role = 'management'): User
    {
        $boss = User::create(['name' => 'Boss', 'email' => $role.'@example.com', 'password' => Hash::make('password')]);
        $boss->tenants()->attach($this->tenant->id, ['role' => $role]);
        Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $boss->id,
            'name' => 'Boss', 'status' => 'active', 'workload' => 'green',
        ]);

        return $boss;
    }

    private function seedItem(array $over = []): FeedbackItem
    {
        return FeedbackItem::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->user->id,
            'employee_id' => $this->employee->id,
            'type' => 'bug',
            'title' => 'Sample bug',
            'status' => 'open',
        ], $over));
    }

    public function test_management_sees_the_inbox_with_items(): void
    {
        // Arrange
        $this->seedItem(['title' => 'Broken clock-in', 'type' => 'bug']);
        $this->seedItem(['title' => 'Add dark mode', 'type' => 'idea']);
        $boss = $this->privilegedActor('management');

        // Act
        $response = $this->actingAs($boss)->withSession(['current_tenant' => $this->tenant->id])->get('/app/feedback');

        // Assert
        $response->assertOk();
        $response->assertSee('Broken clock-in');
        $response->assertSee('Add dark mode');
    }

    public function test_employee_cannot_open_the_inbox(): void
    {
        $response = $this->actingInTenant()->get('/app/feedback');

        $response->assertForbidden();
    }

    public function test_manager_views_inbox_but_gets_no_triage_control(): void
    {
        // Arrange
        $item = $this->seedItem(['title' => 'Manager-visible bug']);
        $boss = $this->privilegedActor('manager');

        // Act
        $response = $this->actingAs($boss)->withSession(['current_tenant' => $this->tenant->id])->get('/app/feedback');

        // Assert — manager sees the item (oversight) but the triage form is absent.
        $response->assertOk();
        $response->assertSee('Manager-visible bug');
        $response->assertDontSee('feedback/'.$item->id.'/status');
    }

    public function test_manager_cannot_triage_status(): void
    {
        // Arrange
        $item = $this->seedItem(['status' => 'open']);
        $boss = $this->privilegedActor('manager');

        // Act — a manager may view but not move an item.
        $response = $this->actingAs($boss)->withSession(['current_tenant' => $this->tenant->id])
            ->post('/app/feedback/'.$item->id.'/status', ['status' => 'resolved']);

        // Assert
        $response->assertForbidden();
        $this->assertSame('open', $item->fresh()->status);
    }

    public function test_privileged_user_triages_status(): void
    {
        // Arrange
        $item = $this->seedItem(['status' => 'open']);
        $boss = $this->privilegedActor('hr');

        // Act
        $response = $this->actingAs($boss)->withSession(['current_tenant' => $this->tenant->id])
            ->post('/app/feedback/'.$item->id.'/status', ['status' => 'resolved']);

        // Assert
        $response->assertRedirect();
        $response->assertSessionHas('ok');
        $this->assertSame('resolved', $item->fresh()->status);
    }

    public function test_employee_cannot_triage_status(): void
    {
        // Arrange
        $item = $this->seedItem(['status' => 'open']);

        // Act — a plain employee tries to move it.
        $response = $this->actingInTenant()->post('/app/feedback/'.$item->id.'/status', ['status' => 'resolved']);

        // Assert — 403 and the status is untouched.
        $response->assertForbidden();
        $this->assertSame('open', $item->fresh()->status);
    }

    public function test_status_must_be_a_known_value(): void
    {
        $item = $this->seedItem(['status' => 'open']);
        $boss = $this->privilegedActor('management');

        $response = $this->actingAs($boss)->withSession(['current_tenant' => $this->tenant->id])
            ->post('/app/feedback/'.$item->id.'/status', ['status' => 'archived']);

        $response->assertSessionHasErrors('status');
        $this->assertSame('open', $item->fresh()->status);
    }
}
