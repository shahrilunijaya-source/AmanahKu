<?php

namespace Tests\Feature;

use App\Http\Controllers\HelpdeskController;
use App\Models\Employee;
use App\Models\Tenant;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\User;
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
            'description' => 'Tapping clock-in on the dashboard has no effect.',
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

    public function test_idea_ticket_allows_missing_description(): void
    {
        $response = $this->actingInTenant()->post('/app/helpdesk', [
            'category' => 'Idea',
            'subject' => 'Dark mode please',
        ]);

        $response->assertRedirect();
        $ticket = Ticket::withoutGlobalScopes()->latest('id')->first();
        $this->assertNotNull($ticket);
        $this->assertSame('Idea', $ticket->category);
        $this->assertSame('medium', $ticket->priority);
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
            'description' => 'See attached.',
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

    // ── Superadmin-only triage for Bug/Idea ───────────────────────────

    public function test_hr_cannot_triage_a_bug_ticket(): void
    {
        $hr = $this->hrActor();
        $ticket = Ticket::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'category' => 'Bug', 'priority' => 'medium', 'subject' => 'Needs superadmin',
            'description' => 'x', 'status' => 'open',
        ]);

        $response = $this->actingAs($hr)->withSession(['current_tenant' => $this->tenant->id])
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
}
