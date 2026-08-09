<?php

namespace Tests\Feature;

use App\Http\Controllers\HelpdeskController;
use App\Models\AppNotification;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\Tenant;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\User;
use App\Services\FeatureManager;
use App\Support\Features;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Feature coverage for the Helpdesk / IT tickets module.
 * Harness (setUp / actingInTenant / hrActor) copied from CoreWritePathsTest.
 */
class HelpdeskTest extends TestCase
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

    // ── Raising tickets ───────────────────────────────────────────

    public function test_employee_raises_a_ticket(): void
    {
        $this->actingInTenant()->post('/app/helpdesk', [
            'category' => 'IT',
            'priority' => 'high',
            'subject' => 'VPN keeps dropping',
            'description' => 'My VPN disconnects every few minutes.',
        ])->assertRedirect();

        $this->assertDatabaseHas('tickets', [
            'tenant_id' => $this->tenant->id,
            'employee_id' => $this->employee->id,
            'subject' => 'VPN keeps dropping',
            'category' => 'IT',
            'priority' => 'high',
            'status' => 'open',
        ]);
    }

    public function test_raising_a_ticket_requires_a_subject(): void
    {
        $this->actingInTenant()->post('/app/helpdesk', [
            'category' => 'IT', 'priority' => 'low', 'subject' => '', 'description' => 'x',
        ])->assertSessionHasErrors('subject');
    }

    public function test_ticket_category_accepts_bug_and_idea_and_stores_page_url(): void
    {
        $ticket = Ticket::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'category' => 'Bug', 'priority' => 'medium', 'subject' => 'Clock-in broken',
            'description' => 'Nothing happens on tap.', 'status' => 'open',
            'page_url' => 'http://localhost/app/dash',
        ]);

        $this->assertSame('Bug', $ticket->fresh()->category);
        $this->assertSame('http://localhost/app/dash', $ticket->fresh()->page_url);
    }

    /** Task 12 copies feedback rows whose employee_id may be null; the column has to accept them. */
    public function test_ticket_accepts_a_null_employee_id(): void
    {
        $ticket = Ticket::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => null,
            'category' => 'Idea', 'priority' => 'medium', 'subject' => 'Orphaned report',
            'description' => 'Author has no employee record.', 'status' => 'open',
        ]);

        $this->assertNull($ticket->fresh()->employee_id);
    }

    // ── Privileged updates ────────────────────────────────────────

    public function test_privileged_user_updates_status_assignee_and_resolution(): void
    {
        $hr = $this->hrActor();
        $assignee = Employee::create([
            'tenant_id' => $this->tenant->id, 'name' => 'IT Tech', 'status' => 'active', 'workload' => 'green',
        ]);
        $ticket = Ticket::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'category' => 'IT', 'priority' => 'high', 'subject' => 'Broken laptop',
            'description' => 'Will not boot.', 'status' => 'open',
        ]);

        $this->actingAs($hr)->withSession(['current_tenant' => $this->tenant->id])
            ->post("/app/helpdesk/{$ticket->id}", [
                'status' => 'resolved',
                'assignee_employee_id' => $assignee->id,
                'resolution' => 'Replaced the SSD; boots fine.',
            ])->assertRedirect();

        $fresh = $ticket->fresh();
        $this->assertSame('resolved', $fresh->status);
        $this->assertSame($assignee->id, $fresh->assignee_employee_id);
        $this->assertSame('Replaced the SSD; boots fine.', $fresh->resolution);

        $this->assertDatabaseHas('app_notifications', [
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->user->id,
            'title' => 'Ticket updated',
        ]);

        // A second status change on the SAME ticket must notify again too.
        $this->actingAs($hr)->withSession(['current_tenant' => $this->tenant->id])
            ->post("/app/helpdesk/{$ticket->id}", [
                'status' => 'open',
                'assignee_employee_id' => $assignee->id,
            ])->assertRedirect();

        $this->assertSame(2, AppNotification::where('user_id', $this->user->id)->count());
    }

    public function test_plain_employee_cannot_update_a_ticket(): void
    {
        $ticket = Ticket::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'category' => 'IT', 'priority' => 'high', 'subject' => 'Broken laptop',
            'description' => 'Will not boot.', 'status' => 'open',
        ]);

        $this->actingInTenant()->post("/app/helpdesk/{$ticket->id}", [
            'status' => 'closed',
            'assignee_employee_id' => $this->employee->id,
            'resolution' => 'I fixed it myself.',
        ])->assertForbidden();

        $fresh = $ticket->fresh();
        $this->assertSame('open', $fresh->status);
        $this->assertNull($fresh->assignee_employee_id);
        $this->assertNull($fresh->resolution);
    }

    public function test_ticket_has_many_attachments_oldest_first(): void
    {
        $ticket = Ticket::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'category' => 'Bug', 'priority' => 'medium', 'subject' => 'Broken export',
            'description' => 'CSV export 500s.', 'status' => 'open',
        ]);

        $first = $ticket->attachments()->create([
            'tenant_id' => $this->tenant->id, 'path' => 'ticket-attachments/a.png',
            'name' => 'a.png', 'mime' => 'image/png', 'size' => 100,
        ]);
        $second = $ticket->attachments()->create([
            'tenant_id' => $this->tenant->id, 'path' => 'ticket-attachments/b.png',
            'name' => 'b.png', 'mime' => 'image/png', 'size' => 200,
        ]);

        $ordered = $ticket->attachments()->pluck('id')->all();
        $this->assertSame([$first->id, $second->id], $ordered);
        $this->assertTrue($first->isImage());
    }

    public function test_bug_ticket_ignores_posted_priority_and_defaults_to_medium(): void
    {
        $this->actingInTenant()->post('/app/helpdesk', [
            'category' => 'Bug',
            'priority' => 'urgent',
            'subject' => 'Clock-in button does nothing',
            'steps_to_reproduce' => 'Tap clock-in on the dashboard.',
            'expected_behavior' => 'Attendance is recorded.',
            'actual_behavior' => 'Nothing happens.',
            'page_url' => 'http://localhost/app/dash',
        ])->assertRedirect();

        $this->assertDatabaseHas('tickets', [
            'tenant_id' => $this->tenant->id,
            'employee_id' => $this->employee->id,
            'category' => 'Bug',
            'priority' => 'medium',
            'page_url' => 'http://localhost/app/dash',
            'status' => 'open',
        ]);
    }

    public function test_bug_ticket_stores_a_structured_description_as_json(): void
    {
        $this->actingInTenant()->post('/app/helpdesk', [
            'category' => 'Bug',
            'subject' => 'Clock-in button does nothing',
            'steps_to_reproduce' => 'Tap clock-in on the dashboard.',
            'expected_behavior' => 'Attendance is recorded.',
            'actual_behavior' => 'Nothing happens.',
            'additional_context' => 'Only on Safari.',
        ])->assertRedirect();

        $ticket = Ticket::withoutGlobalScopes()->latest('id')->first();
        $this->assertNotNull($ticket);
        $decoded = json_decode($ticket->description, true);
        $this->assertSame([
            'steps_to_reproduce' => 'Tap clock-in on the dashboard.',
            'expected_behavior' => 'Attendance is recorded.',
            'actual_behavior' => 'Nothing happens.',
            'additional_context' => 'Only on Safari.',
        ], $decoded);

        $structured = $ticket->structuredDescription();
        $this->assertSame('Tap clock-in on the dashboard.', $structured['steps_to_reproduce']['value']);
    }

    public function test_bug_ticket_requires_steps_expected_and_actual_but_not_context(): void
    {
        $response = $this->actingInTenant()->post('/app/helpdesk', [
            'category' => 'Bug',
            'subject' => 'Missing fields',
        ]);

        $response->assertSessionHasErrors(['steps_to_reproduce', 'expected_behavior', 'actual_behavior']);
        $response->assertSessionDoesntHaveErrors('additional_context');
        $this->assertSame(0, Ticket::withoutGlobalScopes()->count());
    }

    public function test_idea_ticket_requires_problem_and_proposed_solution_but_not_context(): void
    {
        $response = $this->actingInTenant()->post('/app/helpdesk', [
            'category' => 'Idea',
            'subject' => 'Dark mode please',
        ]);

        $response->assertSessionHasErrors(['problem', 'proposed_solution']);
        $response->assertSessionDoesntHaveErrors('additional_context');
        $this->assertSame(0, Ticket::withoutGlobalScopes()->count());
    }

    public function test_idea_ticket_stores_a_structured_description_as_json(): void
    {
        $response = $this->actingInTenant()->post('/app/helpdesk', [
            'category' => 'Idea',
            'subject' => 'Dark mode please',
            'problem' => 'Bright UI at night hurts the eyes.',
            'proposed_solution' => 'Add a dark theme toggle.',
        ]);

        $response->assertRedirect();
        $ticket = Ticket::withoutGlobalScopes()->latest('id')->first();
        $this->assertNotNull($ticket);
        $this->assertSame('Idea', $ticket->category);
        $this->assertSame('medium', $ticket->priority);
        $decoded = json_decode($ticket->description, true);
        $this->assertSame('Bright UI at night hurts the eyes.', $decoded['problem']);
        $this->assertSame('Add a dark theme toggle.', $decoded['proposed_solution']);
        $this->assertSame('', $decoded['additional_context']);
    }

    public function test_it_ticket_still_requires_description_and_priority(): void
    {
        $response = $this->actingInTenant()->post('/app/helpdesk', [
            'category' => 'IT',
            'subject' => 'No description supplied',
        ]);

        $response->assertSessionHasErrors(['priority', 'description']);
    }

    public function test_submit_stores_pasted_screenshot_and_uploaded_document_on_a_bug_ticket(): void
    {
        Storage::fake('local');

        $response = $this->actingInTenant()->post('/app/helpdesk', [
            'category' => 'Bug',
            'subject' => 'Layout broken with proof',
            'steps_to_reproduce' => 'Open the dashboard.',
            'expected_behavior' => 'Cards align in a grid.',
            'actual_behavior' => 'Cards overlap.',
            'attachments' => [
                UploadedFile::fake()->image('screenshot-1.png'),
                UploadedFile::fake()->create('trace.pdf', 40, 'application/pdf'),
            ],
        ]);

        $response->assertRedirect();
        $ticket = Ticket::withoutGlobalScopes()->latest('id')->first();
        $this->assertSame(2, $ticket->attachments()->count());
        foreach ($ticket->attachments as $att) {
            $this->assertSame($this->tenant->id, $att->tenant_id);
            Storage::disk('local')->assertExists($att->path);
        }
    }

    public function test_submit_rejects_a_disallowed_file_type(): void
    {
        Storage::fake('local');

        $response = $this->actingInTenant()->post('/app/helpdesk', [
            'category' => 'Bug',
            'subject' => 'Sneaky upload',
            'description' => 'x',
            'attachments' => [UploadedFile::fake()->create('malware.exe', 10)],
        ]);

        $response->assertSessionHasErrors('attachments.0');
        $this->assertSame(0, Ticket::withoutGlobalScopes()->count());
    }

    public function test_submit_rejects_more_than_the_attachment_cap(): void
    {
        Storage::fake('local');

        $files = array_map(fn ($i) => UploadedFile::fake()->image("s{$i}.png"), range(1, 7));
        $response = $this->actingInTenant()->post('/app/helpdesk', [
            'category' => 'Idea',
            'subject' => 'Too many pics',
            'attachments' => $files,
        ]);

        $response->assertSessionHasErrors('attachments');
        $this->assertSame(0, Ticket::withoutGlobalScopes()->count());
    }

    public function test_non_feedback_category_ignores_posted_attachments(): void
    {
        Storage::fake('local');

        $response = $this->actingInTenant()->post('/app/helpdesk', [
            'category' => 'IT',
            'priority' => 'high',
            'subject' => 'VPN keeps dropping',
            'description' => 'My VPN disconnects every few minutes.',
            'attachments' => [UploadedFile::fake()->image('screenshot.png')],
        ]);

        $response->assertRedirect();
        $ticket = Ticket::withoutGlobalScopes()->latest('id')->first();
        $this->assertNotNull($ticket);
        $this->assertSame(0, $ticket->attachments()->count());
    }

    private function seedAttachment(Ticket $ticket): TicketAttachment
    {
        $path = UploadedFile::fake()->image('shot.png')->store('ticket-attachments', 'local');

        return TicketAttachment::create([
            'tenant_id' => $ticket->tenant_id,
            'ticket_id' => $ticket->id,
            'path' => $path,
            'name' => 'shot.png',
            'mime' => 'image/png',
            'size' => 1024,
        ]);
    }

    public function test_raiser_can_download_own_attachment(): void
    {
        Storage::fake('local');
        $ticket = Ticket::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'category' => 'Bug', 'priority' => 'medium', 'subject' => 'x',
            'description' => 'x', 'status' => 'open',
        ]);
        $att = $this->seedAttachment($ticket);

        $response = $this->actingInTenant()->get('/app/helpdesk/attachments/'.$att->id);

        $response->assertOk();
    }

    public function test_hr_can_download_bug_ticket_attachment(): void
    {
        Storage::fake('local');
        $ticket = Ticket::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'category' => 'Bug', 'priority' => 'medium', 'subject' => 'x',
            'description' => 'x', 'status' => 'open',
        ]);
        $att = $this->seedAttachment($ticket);
        $hr = $this->hrActor();

        $response = $this->actingAs($hr)->withSession(['current_tenant' => $this->tenant->id])
            ->get('/app/helpdesk/attachments/'.$att->id);

        $response->assertOk();
    }

    public function test_manager_cannot_download_bug_ticket_attachment(): void
    {
        // manager is PRIVILEGED_ROLES but NOT FEEDBACK_VIEW_ROLES — the Bug/Idea
        // attachment gate must be narrower than the general privileged tier.
        Storage::fake('local');
        $ticket = Ticket::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'category' => 'Bug', 'priority' => 'medium', 'subject' => 'x',
            'description' => 'x', 'status' => 'open',
        ]);
        $att = $this->seedAttachment($ticket);

        $manager = User::create(['name' => 'Mgr', 'email' => 'mgr-att@example.com', 'password' => Hash::make('password')]);
        $manager->tenants()->attach($this->tenant->id, ['role' => 'manager']);
        Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $manager->id,
            'name' => 'Mgr', 'status' => 'active', 'workload' => 'green',
        ]);

        $response = $this->actingAs($manager)->withSession(['current_tenant' => $this->tenant->id])
            ->get('/app/helpdesk/attachments/'.$att->id);

        $response->assertForbidden();
    }

    public function test_unrelated_employee_cannot_download_ticket_attachment(): void
    {
        Storage::fake('local');
        $ticket = Ticket::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'category' => 'Bug', 'priority' => 'medium', 'subject' => 'x',
            'description' => 'x', 'status' => 'open',
        ]);
        $att = $this->seedAttachment($ticket);

        $stranger = User::create(['name' => 'Nosy', 'email' => 'nosy@example.com', 'password' => Hash::make('password')]);
        $stranger->tenants()->attach($this->tenant->id, ['role' => 'employee']);
        Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $stranger->id,
            'name' => 'Nosy', 'status' => 'active', 'workload' => 'green',
        ]);

        $response = $this->actingAs($stranger)->withSession(['current_tenant' => $this->tenant->id])
            ->get('/app/helpdesk/attachments/'.$att->id);

        $response->assertForbidden();
    }

    /**
     * Build the request/tenant context ResolveTenant would have left behind, then call
     * screenData() the way AppController does.
     *
     * @return array<string, mixed>
     */
    private function screenDataAs(User $user, string $role, ?Employee $employee): array
    {
        $request = Request::create('/app/helpdesk', 'GET');
        $request->setUserResolver(fn () => $user);
        $request->attributes->set('tenantRole', $role);
        app(CurrentTenant::class)->set($this->tenant);

        return app(HelpdeskController::class)->screenData($request, $employee);
    }

    /** Flatten the status-keyed board into a subject list, so assertions read plainly. */
    private function boardSubjects(array $data): array
    {
        return $data['grouped']->flatten()->pluck('subject')->all();
    }

    public function test_manager_does_not_see_bug_tickets_in_the_board(): void
    {
        Ticket::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'category' => 'Bug', 'priority' => 'medium', 'subject' => 'Manager-hidden bug',
            'description' => 'x', 'status' => 'open',
        ]);
        Ticket::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'category' => 'IT', 'priority' => 'medium', 'subject' => 'Manager-visible IT',
            'description' => 'x', 'status' => 'open',
        ]);
        $manager = User::create(['name' => 'Mgr', 'email' => 'mgr@example.com', 'password' => Hash::make('password')]);
        $manager->tenants()->attach($this->tenant->id, ['role' => 'manager']);
        $managerEmployee = Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $manager->id,
            'name' => 'Mgr', 'status' => 'active', 'workload' => 'green',
        ]);

        $subjects = $this->boardSubjects($this->screenDataAs($manager, 'manager', $managerEmployee));

        // The IT ticket proves the board is populated at all — without it, a screenData()
        // that returned an empty board for every role would still pass the Bug assertion.
        $this->assertContains('Manager-visible IT', $subjects);
        $this->assertNotContains('Manager-hidden bug', $subjects);
    }

    public function test_hr_and_director_see_bug_tickets_in_the_board(): void
    {
        Ticket::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'category' => 'Bug', 'priority' => 'medium', 'subject' => 'HR-visible bug',
            'description' => 'x', 'status' => 'open',
        ]);
        $hr = $this->hrActor();
        $hrEmployee = Employee::where('user_id', $hr->id)->firstOrFail();

        $this->assertContains('HR-visible bug', $this->boardSubjects($this->screenDataAs($hr, 'hr', $hrEmployee)));
        // director collapses to management through Permissions::effectiveRole (spec line 160).
        $this->assertContains('HR-visible bug', $this->boardSubjects($this->screenDataAs($hr, 'director', $hrEmployee)));
    }

    public function test_superadmin_observer_sees_bug_tickets_in_the_board(): void
    {
        Ticket::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'category' => 'Bug', 'priority' => 'medium', 'subject' => 'Observer-visible bug',
            'description' => 'x', 'status' => 'open',
        ]);
        $superadmin = User::create(['name' => 'Root', 'email' => 'root@example.com', 'password' => Hash::make('password')]);
        $superadmin->forceFill(['is_super_admin' => true])->save();

        // No membership row, so ResolveTenant hands screenData the roleIn() collapse: 'management'.
        $data = $this->screenDataAs($superadmin, $superadmin->roleIn($this->tenant), null);

        $this->assertContains('Observer-visible bug', $this->boardSubjects($data));
        $this->assertTrue($data['isSuperAdmin']);
    }

    public function test_raiser_sees_own_bug_ticket_in_my_tickets_as_a_plain_employee(): void
    {
        Ticket::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'category' => 'Bug', 'priority' => 'medium', 'subject' => 'My own bug',
            'description' => 'x', 'status' => 'resolved', 'resolution' => 'Fixed in 1.2.3.',
        ]);

        $data = $this->screenDataAs($this->user, 'employee', $this->employee);

        $this->assertFalse($data['privileged']);
        $this->assertSame(['My own bug'], $data['myTickets']->pluck('subject')->all());
        $this->assertSame('Fixed in 1.2.3.', $data['myTickets']->first()->resolution);
    }

    public function test_manager_still_sees_a_bug_ticket_they_raised_in_my_tickets(): void
    {
        $manager = User::create(['name' => 'Mgr', 'email' => 'mgr@example.com', 'password' => Hash::make('password')]);
        $manager->tenants()->attach($this->tenant->id, ['role' => 'manager']);
        $managerEmployee = Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $manager->id,
            'name' => 'Mgr', 'status' => 'active', 'workload' => 'green',
        ]);
        Ticket::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $managerEmployee->id,
            'category' => 'Bug', 'priority' => 'medium', 'subject' => 'Bug I raised myself',
            'description' => 'x', 'status' => 'resolved', 'resolution' => 'Done.',
        ]);

        $data = $this->screenDataAs($manager, 'manager', $managerEmployee);

        // Filtered OUT of the board (manager is not in FEEDBACK_VIEW_ROLES) but still present
        // in myTickets — the raiser always tracks their own report. This is the whole feature.
        $this->assertNotContains('Bug I raised myself', $this->boardSubjects($data));
        $this->assertSame(['Bug I raised myself'], $data['myTickets']->pluck('subject')->all());
    }

    // ── Bug/Idea triage: FEEDBACK_VIEW_ROLES only (same tier that can see them) ──

    public function test_hr_can_triage_a_bug_ticket(): void
    {
        $hr = $this->hrActor();
        $ticket = Ticket::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'category' => 'Bug', 'priority' => 'medium', 'subject' => 'Clock-in broken',
            'description' => 'x', 'status' => 'open',
        ]);

        $response = $this->actingAs($hr)->withSession(['current_tenant' => $this->tenant->id])
            ->post("/app/helpdesk/{$ticket->id}", [
                'status' => 'resolved', 'resolution' => 'Fixed in 1.2.3.',
            ]);

        $response->assertRedirect();
        $fresh = $ticket->fresh();
        $this->assertSame('resolved', $fresh->status);
        $this->assertSame('Fixed in 1.2.3.', $fresh->resolution);
    }

    public function test_manager_cannot_triage_a_bug_ticket(): void
    {
        // manager is PRIVILEGED_ROLES but NOT FEEDBACK_VIEW_ROLES — same narrower gate
        // as the Bug/Idea attachment download (test_manager_cannot_download_bug_ticket_attachment).
        $ticket = Ticket::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'category' => 'Bug', 'priority' => 'medium', 'subject' => 'Needs FEEDBACK_VIEW_ROLES',
            'description' => 'x', 'status' => 'open',
        ]);

        $manager = User::create(['name' => 'Mgr', 'email' => 'mgr-triage@example.com', 'password' => Hash::make('password')]);
        $manager->tenants()->attach($this->tenant->id, ['role' => 'manager']);
        Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $manager->id,
            'name' => 'Mgr', 'status' => 'active', 'workload' => 'green',
        ]);

        $response = $this->actingAs($manager)->withSession(['current_tenant' => $this->tenant->id])
            ->post("/app/helpdesk/{$ticket->id}", [
                'status' => 'resolved', 'resolution' => 'Nice try.',
            ]);

        $response->assertForbidden();
        $this->assertSame('open', $ticket->fresh()->status);
    }

    public function test_superadmin_can_triage_a_bug_ticket(): void
    {
        $superadmin = User::create(['name' => 'Root', 'email' => 'root@example.com', 'password' => Hash::make('password')]);
        $superadmin->forceFill(['is_super_admin' => true])->save();
        $ticket = Ticket::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'category' => 'Idea', 'priority' => 'medium', 'subject' => 'Dark mode',
            'description' => 'x', 'status' => 'open',
        ]);

        $response = $this->actingAs($superadmin)->withSession(['current_tenant' => $this->tenant->id])
            ->post("/app/helpdesk/{$ticket->id}", [
                'status' => 'resolved', 'resolution' => 'Shipped in 2.0.',
            ]);

        $response->assertRedirect();
        $fresh = $ticket->fresh();
        $this->assertSame('resolved', $fresh->status);
        $this->assertSame('Shipped in 2.0.', $fresh->resolution);
    }

    public function test_hr_still_triages_non_feedback_tickets(): void
    {
        $hr = $this->hrActor();
        $ticket = Ticket::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'category' => 'IT', 'priority' => 'high', 'subject' => 'Broken laptop',
            'description' => 'x', 'status' => 'open',
        ]);

        $response = $this->actingAs($hr)->withSession(['current_tenant' => $this->tenant->id])
            ->post("/app/helpdesk/{$ticket->id}", [
                'status' => 'resolved', 'resolution' => 'Replaced.',
            ]);

        $response->assertRedirect();
        $this->assertSame('resolved', $ticket->fresh()->status);
    }

    public function test_helpdesk_module_defaults_on(): void
    {
        $this->assertTrue(Features::default('module.helpdesk'));
    }

    public function test_helpdesk_screen_renders_the_board_and_my_tickets(): void
    {
        Ticket::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'category' => 'Bug', 'priority' => 'medium', 'subject' => 'Rendered bug',
            'description' => 'x', 'status' => 'resolved', 'resolution' => 'Fixed in 1.2.3.',
        ]);

        // The raiser (plain employee) sees their own ticket and its resolution note.
        $response = $this->actingInTenant()->get('/app/helpdesk');
        $response->assertOk();
        $response->assertSee('Rendered bug');
        $response->assertSee('Fixed in 1.2.3.');

        // HR sees it on the board and gets a Manage control on a Bug ticket too — HR is
        // in FEEDBACK_VIEW_ROLES, the same tier that can see it there in the first place.
        $hr = $this->hrActor();
        $hrResponse = $this->actingAs($hr)->withSession(['current_tenant' => $this->tenant->id])->get('/app/helpdesk');
        $hrResponse->assertOk();
        $hrResponse->assertSee('Rendered bug');
        $hrResponse->assertSee(route('helpdesk.update', 1), false);
    }

    public function test_bug_ticket_renders_structured_description_as_labeled_sections(): void
    {
        Ticket::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'category' => 'Bug', 'priority' => 'medium', 'subject' => 'Structured bug',
            'description' => json_encode([
                'steps_to_reproduce' => 'Tap clock-in.',
                'expected_behavior' => 'Attendance recorded.',
                'actual_behavior' => 'Nothing happens.',
                'additional_context' => '',
            ]), 'status' => 'open',
        ]);

        $response = $this->actingInTenant()->get('/app/helpdesk');
        $response->assertOk();
        $response->assertSee('Steps to reproduce');
        $response->assertSee('Tap clock-in.');
        $response->assertSee('Expected behavior');
        $response->assertSee('Attendance recorded.');
    }

    public function test_ticket_description_renders_markdown(): void
    {
        Ticket::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'category' => 'Bug', 'priority' => 'medium', 'subject' => 'Markdown bug',
            'description' => json_encode([
                'steps_to_reproduce' => "1. Open the app\n2. Click **Submit**",
                'expected_behavior' => 'x', 'actual_behavior' => 'x', 'additional_context' => '',
            ]), 'status' => 'open',
        ]);

        $response = $this->actingInTenant()->get('/app/helpdesk');
        $response->assertOk();
        $response->assertSee('<strong>Submit</strong>', false);
        $response->assertSee('<ol>', false);
    }

    public function test_ticket_description_renders_bulleted_list_with_visible_markers(): void
    {
        Ticket::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'category' => 'Bug', 'priority' => 'medium', 'subject' => 'Bulleted bug',
            'description' => json_encode([
                'steps_to_reproduce' => "- First bullet\n- Second bullet",
                'expected_behavior' => 'x', 'actual_behavior' => 'x', 'additional_context' => '',
            ]), 'status' => 'open',
        ]);

        $response = $this->actingInTenant()->get('/app/helpdesk');
        $response->assertOk();
        $response->assertSee('<ul>', false);

        // A global reset strips list-style on every ul/ol app-wide, so the <ul>/<ol> markup
        // above renders with invisible bullets/numbers unless .uj-markdown restores markers
        // explicitly. A feature test can't see computed CSS — guard the source rule directly.
        $css = file_get_contents(resource_path('css/app.css'));
        $this->assertStringContainsString('.uj-markdown ul { list-style-type: disc; }', $css);
        $this->assertStringContainsString('.uj-markdown ol { list-style-type: decimal; }', $css);
    }

    public function test_ticket_description_escapes_raw_html_instead_of_rendering_it(): void
    {
        Ticket::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'category' => 'Bug', 'priority' => 'medium', 'subject' => 'XSS attempt',
            'description' => json_encode([
                'steps_to_reproduce' => '<script>alert(1)</script>',
                'expected_behavior' => 'x', 'actual_behavior' => 'x', 'additional_context' => '',
            ]), 'status' => 'open',
        ]);

        $response = $this->actingInTenant()->get('/app/helpdesk');
        $response->assertOk();
        $response->assertDontSee('<script>alert(1)</script>', false);
        $response->assertSee('&lt;script&gt;', false);
    }

    public function test_structured_description_omits_empty_additional_context(): void
    {
        $ticket = Ticket::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'category' => 'Bug', 'priority' => 'medium', 'subject' => 'x',
            'description' => json_encode([
                'steps_to_reproduce' => 'Tap clock-in.',
                'expected_behavior' => 'Attendance recorded.',
                'actual_behavior' => 'Nothing happens.',
                'additional_context' => '',
            ]), 'status' => 'open',
        ]);

        $structured = $ticket->structuredDescription();
        $this->assertNotNull($structured);
        $this->assertArrayHasKey('steps_to_reproduce', $structured);
        $this->assertArrayNotHasKey('additional_context', $structured);
    }

    public function test_structured_description_returns_null_for_legacy_plain_text_bug_ticket(): void
    {
        $ticket = Ticket::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'category' => 'Bug', 'priority' => 'medium', 'subject' => 'x',
            'description' => 'Old free-text bug report.', 'status' => 'open',
        ]);

        $this->assertNull($ticket->structuredDescription());
    }

    // ── Ticket-raise modal scoping (final-review finding 1) ─────────

    public function test_unrelated_forms_validation_error_does_not_open_ticket_raise_modal(): void
    {
        // DocumentController::store validates a 'category' field too — the exact overlap
        // that used to false-open this global modal. A failed /app/documents submit must
        // redirect back to a page where the modal is closed, not one where it pops open
        // on top of the (unrelated) Document Vault error. followingRedirects() (not two
        // separate calls) matches how the rest of this suite proves post-redirect content —
        // flashed old()/errors only round-trip correctly within one chained call.
        $response = $this->actingInTenant()->from('/app/dash')->followingRedirects()->post('/app/documents', []);

        $response->assertOk();
        // Confirms the empty payload really did fail Document Vault's own validation —
        // this is a genuine unrelated-form failure, not a vacuous request.
        $this->assertSame(0, EmployeeDocument::count());
        $this->assertSame('false', $this->ticketRaiseModalShowValue($response->getContent()));
    }

    public function test_own_failed_submit_still_reopens_the_ticket_raise_modal(): void
    {
        // Sanity check the other side of the fix: a genuinely failed Report-tab submit
        // (its _ticket_raise marker present in old()) must still reopen the modal.
        $response = $this->actingInTenant()->from('/app/dash')->followingRedirects()
            ->post('/app/helpdesk', ['_ticket_raise' => '1', 'category' => 'IT']);

        $response->assertOk();
        // Confirms validation genuinely failed (subject/priority/description missing).
        $this->assertSame(0, Ticket::withoutGlobalScopes()->count());
        $this->assertSame('true', $this->ticketRaiseModalShowValue($response->getContent()));
    }

    // ── Module gating (final-review finding 2) ───────────────────────

    public function test_raise_ticket_button_and_modal_hidden_when_helpdesk_module_disabled(): void
    {
        app(FeatureManager::class)->setTenant($this->tenant, 'module.helpdesk', false);

        $response = $this->actingInTenant()->get('/app/dash');

        $response->assertOk();
        $response->assertDontSee('Raise a ticket');
        // Unique to the ticket-raise modal markup — proves it did not render at all,
        // not merely that it is visually hidden.
        $response->assertDontSee('tr-page-url', false);
    }

    public function test_raise_ticket_button_and_modal_present_when_helpdesk_module_enabled(): void
    {
        $response = $this->actingInTenant()->get('/app/dash');

        $response->assertOk();
        $response->assertSee('Raise a ticket');
        $response->assertSee('tr-page-url', false);
    }

    /**
     * Reads the ticket-raise modal's own Alpine "show" value out of the response body.
     * A bare assertSee/assertDontSee('show: true') collides with unrelated x-data="{
     * show: true }" blocks elsewhere on the page (e.g. the overdue-timesheet banner in
     * layouts/app.blade.php), so this scopes the check to id="ticket-raise-modal".
     */
    private function ticketRaiseModalShowValue(string $html): ?string
    {
        preg_match('/id="ticket-raise-modal"[^>]*x-data="\{ show: (true|false)/', $html, $matches);

        return $matches[1] ?? null;
    }
}
