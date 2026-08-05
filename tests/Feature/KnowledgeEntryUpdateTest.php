<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\KnowledgeEntry;
use App\Models\KnowledgeSegment;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** The author may edit their own Knowledge Bank lesson; nobody else may. */
class KnowledgeEntryUpdateTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $author;

    private Employee $authorEmployee;

    private User $other;

    private KnowledgeEntry $entry;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        app(CurrentTenant::class)->set($this->tenant);

        $this->author = User::create(['name' => 'Author', 'email' => 'author@example.com', 'password' => Hash::make('password')]);
        $this->author->tenants()->attach($this->tenant->id, ['role' => 'employee']);
        $this->authorEmployee = Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $this->author->id,
            'name' => 'Author', 'status' => 'active', 'workload' => 'green',
        ]);

        $this->other = User::create(['name' => 'Other', 'email' => 'other@example.com', 'password' => Hash::make('password')]);
        $this->other->tenants()->attach($this->tenant->id, ['role' => 'employee']);
        Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $this->other->id,
            'name' => 'Other', 'status' => 'active', 'workload' => 'green',
        ]);

        $segment = KnowledgeSegment::create([
            'tenant_id' => $this->tenant->id, 'label' => 'Lessons', 'sort_order' => 1,
        ]);
        $this->entry = KnowledgeEntry::create([
            'tenant_id' => $this->tenant->id, 'seg_id' => $segment->id, 'employee_id' => $this->authorEmployee->id,
            'title' => 'Original title', 'body' => 'Original body.',
        ]);
    }

    public function test_author_can_edit_their_own_lesson(): void
    {
        $this->actingAs($this->author)->withSession(['current_tenant' => $this->tenant->id])
            ->putJson("/app/knowledge-bank/{$this->entry->id}", [
                'title' => 'Updated title', 'body' => 'Updated body.',
            ])
            ->assertOk()
            ->assertJson(['title' => 'Updated title', 'body' => 'Updated body.']);

        $this->assertDatabaseHas('knowledge_entries', [
            'id' => $this->entry->id, 'title' => 'Updated title', 'body' => 'Updated body.',
        ]);
    }

    public function test_another_employee_cannot_edit_the_lesson(): void
    {
        $this->actingAs($this->other)->withSession(['current_tenant' => $this->tenant->id])
            ->putJson("/app/knowledge-bank/{$this->entry->id}", [
                'title' => 'Hijacked', 'body' => 'Hijacked body.',
            ])
            ->assertForbidden();

        $this->assertDatabaseHas('knowledge_entries', [
            'id' => $this->entry->id, 'title' => 'Original title',
        ]);
    }
}
