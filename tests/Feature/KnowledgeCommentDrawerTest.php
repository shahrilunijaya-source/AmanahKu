<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\KnowledgeComment;
use App\Models\KnowledgeEntry;
use App\Models\KnowledgeSegment;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The Knowledge Bank comment slide-over (the T.O.T.-style drawer) talks to the
 * server over JSON: list on open, post, delete. Each must return the shape the
 * kbCard Alpine component consumes.
 */
class KnowledgeCommentDrawerTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    private Employee $viewer;

    private KnowledgeEntry $entry;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        app(CurrentTenant::class)->set($this->tenant);

        $this->user = User::create(['name' => 'Viewer', 'email' => 'viewer@example.com', 'password' => Hash::make('password')]);
        $this->user->tenants()->attach($this->tenant->id, ['role' => 'employee']);
        $this->viewer = Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $this->user->id,
            'name' => 'Viewer', 'status' => 'active', 'workload' => 'green',
        ]);

        $segment = KnowledgeSegment::create([
            'tenant_id' => $this->tenant->id, 'label' => 'Lessons', 'sort_order' => 1,
        ]);
        $this->entry = KnowledgeEntry::create([
            'tenant_id' => $this->tenant->id, 'seg_id' => $segment->id, 'employee_id' => $this->viewer->id,
            'title' => 'A lesson', 'body' => 'The body.',
        ]);
    }

    private function actingInTenant(): self
    {
        $this->actingAs($this->user)->withSession(['current_tenant' => $this->tenant->id]);

        return $this;
    }

    public function test_comments_list_returns_json_thread(): void
    {
        KnowledgeComment::create([
            'tenant_id' => $this->tenant->id, 'entry_id' => $this->entry->id,
            'employee_id' => $this->viewer->id, 'body' => 'First!',
        ]);

        $this->actingInTenant()
            ->getJson("/app/knowledge-bank/{$this->entry->id}/comments")
            ->assertOk()
            ->assertJson(['commentsCount' => 1])
            ->assertJsonPath('comments.0.body', 'First!')
            ->assertJsonPath('comments.0.canDelete', true);
    }

    public function test_posting_a_comment_over_json_returns_updated_count(): void
    {
        $this->actingInTenant()
            ->postJson("/app/knowledge-bank/{$this->entry->id}/comments", ['body' => 'Nice one.'])
            ->assertOk()
            ->assertJson(['commentsCount' => 1]);

        $this->assertDatabaseHas('knowledge_comments', [
            'entry_id' => $this->entry->id, 'body' => 'Nice one.',
        ]);
    }

    public function test_deleting_own_comment_over_json_returns_updated_count(): void
    {
        $comment = KnowledgeComment::create([
            'tenant_id' => $this->tenant->id, 'entry_id' => $this->entry->id,
            'employee_id' => $this->viewer->id, 'body' => 'Remove me.',
        ]);

        $this->actingInTenant()
            ->deleteJson("/app/knowledge-bank/comments/{$comment->id}")
            ->assertOk()
            ->assertJson(['commentsCount' => 0]);

        $this->assertDatabaseMissing('knowledge_comments', ['id' => $comment->id]);
    }

    public function test_reacting_then_pressing_the_same_emoji_is_a_toggle(): void
    {
        // Add.
        $this->actingInTenant()
            ->postJson("/app/knowledge-bank/{$this->entry->id}/react", ['emoji' => '🔥'])
            ->assertOk()
            ->assertJsonPath('reactions.🔥', 1)
            ->assertJsonPath('mine', ['🔥']);

        // Same emoji again → removed.
        $this->actingInTenant()
            ->postJson("/app/knowledge-bank/{$this->entry->id}/react", ['emoji' => '🔥'])
            ->assertOk()
            ->assertJsonPath('mine', []);

        $this->assertDatabaseMissing('knowledge_reactions', [
            'entry_id' => $this->entry->id, 'employee_id' => $this->viewer->id,
        ]);
    }

    public function test_react_rejects_an_unknown_emoji(): void
    {
        $this->actingInTenant()
            ->postJson("/app/knowledge-bank/{$this->entry->id}/react", ['emoji' => '🦄'])
            ->assertStatus(422);
    }

    public function test_helpful_star_returns_json_state(): void
    {
        $this->actingInTenant()
            ->postJson("/app/knowledge-bank/{$this->entry->id}/star")
            ->assertOk()
            ->assertJson(['stars' => 1, 'starred' => true]);

        // Toggling off comes back as not-starred.
        $this->actingInTenant()
            ->postJson("/app/knowledge-bank/{$this->entry->id}/star")
            ->assertOk()
            ->assertJson(['stars' => 0, 'starred' => false]);
    }
}
