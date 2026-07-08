<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\SharedResource;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Feature coverage for the Shared Resources module — the company's shared accounts
 * and tools. All staff may view; managers/management/HR maintain the list. The
 * password is stored encrypted at rest but shown to staff in the UI.
 *
 * Harness (setUp / actingInTenant / privileged actors) mirrors HelpdeskTest.
 */
class SharedResourceTest extends TestCase
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

    private function privilegedActor(string $role = 'hr'): User
    {
        $boss = User::create(['name' => 'Boss', 'email' => $role.'@example.com', 'password' => Hash::make('password')]);
        $boss->tenants()->attach($this->tenant->id, ['role' => $role]);
        Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $boss->id,
            'name' => 'Boss', 'status' => 'active', 'workload' => 'green',
        ]);

        return $boss;
    }

    private function actingAsRole(User $actor): self
    {
        $this->actingAs($actor)->withSession(['current_tenant' => $this->tenant->id]);

        return $this;
    }

    // ── Creating ──────────────────────────────────────────────────

    public function test_privileged_user_adds_a_resource(): void
    {
        $this->actingAsRole($this->privilegedActor('hr'))->post('/app/shared-resources', [
            'name' => 'Company Gmail',
            'category' => 'email',
            'url' => 'https://mail.google.com',
            'username' => 'team@unijaya.example',
            'password' => 'sup3r-secret',
            'notes' => 'Ask Yati for the 2FA code.',
        ])->assertRedirect();

        $this->assertDatabaseHas('shared_resources', [
            'tenant_id' => $this->tenant->id,
            'name' => 'Company Gmail',
            'category' => 'email',
            'username' => 'team@unijaya.example',
        ]);
    }

    public function test_manager_can_also_add_a_resource(): void
    {
        $this->actingAsRole($this->privilegedActor('manager'))->post('/app/shared-resources', [
            'name' => 'Canva',
            'category' => 'design',
        ])->assertRedirect();

        $this->assertDatabaseHas('shared_resources', ['name' => 'Canva', 'category' => 'design']);
    }

    public function test_adding_a_resource_requires_a_name(): void
    {
        $this->actingAsRole($this->privilegedActor('hr'))->post('/app/shared-resources', [
            'name' => '', 'category' => 'email',
        ])->assertSessionHasErrors('name');
    }

    public function test_adding_a_resource_rejects_an_unknown_category(): void
    {
        $this->actingAsRole($this->privilegedActor('hr'))->post('/app/shared-resources', [
            'name' => 'Mystery', 'category' => 'not-a-category',
        ])->assertSessionHasErrors('category');
    }

    public function test_plain_employee_cannot_add_a_resource(): void
    {
        $this->actingInTenant()->post('/app/shared-resources', [
            'name' => 'Sneaky', 'category' => 'other',
        ])->assertForbidden();

        $this->assertDatabaseMissing('shared_resources', ['name' => 'Sneaky']);
    }

    // ── Editing ───────────────────────────────────────────────────

    public function test_privileged_user_updates_a_resource(): void
    {
        $resource = SharedResource::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Canva', 'category' => 'design',
        ]);

        $this->actingAsRole($this->privilegedActor('management'))
            ->post("/app/shared-resources/{$resource->id}", [
                'name' => 'Canva Pro',
                'category' => 'design',
                'username' => 'design@unijaya.example',
            ])->assertRedirect();

        $fresh = $resource->fresh();
        $this->assertSame('Canva Pro', $fresh->name);
        $this->assertSame('design@unijaya.example', $fresh->username);
    }

    public function test_plain_employee_cannot_update_a_resource(): void
    {
        $resource = SharedResource::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Canva', 'category' => 'design',
        ]);

        $this->actingInTenant()->post("/app/shared-resources/{$resource->id}", [
            'name' => 'Hijacked', 'category' => 'design',
        ])->assertForbidden();

        $this->assertSame('Canva', $resource->fresh()->name);
    }

    // ── Deleting ──────────────────────────────────────────────────

    public function test_privileged_user_deletes_a_resource(): void
    {
        $resource = SharedResource::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Old Tool', 'category' => 'other',
        ]);

        $this->actingAsRole($this->privilegedActor('hr'))
            ->post("/app/shared-resources/{$resource->id}/delete")
            ->assertRedirect();

        $this->assertDatabaseMissing('shared_resources', ['id' => $resource->id]);
    }

    public function test_plain_employee_cannot_delete_a_resource(): void
    {
        $resource = SharedResource::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Keep Me', 'category' => 'other',
        ]);

        $this->actingInTenant()
            ->post("/app/shared-resources/{$resource->id}/delete")
            ->assertForbidden();

        $this->assertDatabaseHas('shared_resources', ['id' => $resource->id]);
    }

    // ── Tenant isolation ──────────────────────────────────────────

    public function test_cannot_edit_a_resource_from_another_tenant(): void
    {
        $other = Tenant::create(['slug' => 'other', 'name' => 'Other Co', 'initials' => 'OC']);
        $foreign = SharedResource::create([
            'tenant_id' => $other->id, 'name' => 'Foreign', 'category' => 'other',
        ]);

        // Acting in Acme, the controller's explicit tenant-ownership guard rejects a write
        // against Other Co's record with 403 — it never mutates across the tenant boundary.
        $this->actingAsRole($this->privilegedActor('hr'))
            ->post("/app/shared-resources/{$foreign->id}", [
                'name' => 'Stolen', 'category' => 'other',
            ])->assertForbidden();

        $this->assertSame('Foreign', $foreign->fresh()->name);
    }

    // ── Encryption at rest ────────────────────────────────────────

    public function test_password_is_encrypted_at_rest_but_readable_through_the_model(): void
    {
        $resource = SharedResource::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Vault', 'category' => 'system',
            'password' => 'plaintext-pw',
        ]);

        $raw = DB::table('shared_resources')->where('id', $resource->id)->value('password');
        $this->assertNotSame('plaintext-pw', $raw);
        $this->assertSame('plaintext-pw', $resource->fresh()->password);
    }

    // ── Read access ───────────────────────────────────────────────

    public function test_plain_employee_can_view_the_screen(): void
    {
        SharedResource::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Company WhatsApp', 'category' => 'comms',
        ]);

        $this->actingInTenant()->get('/app/shared-resources')
            ->assertOk()
            ->assertSee('Company WhatsApp');
    }
}
