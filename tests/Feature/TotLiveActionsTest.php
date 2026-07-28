<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Tenant;
use App\Models\TotComment;
use App\Models\TotSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class TotLiveActionsTest extends TestCase
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

    private function slot(): TotSession
    {
        return TotSession::create([
            'tenant_id' => $this->tenant->id, 'year' => 2026, 'month' => 9,
            'title' => 'Barcode rollout', 'status' => 'done',
        ]);
    }

    public function test_reacting_returns_the_new_state_as_json(): void
    {
        $session = $this->slot();

        $response = $this->actingInTenant()
            ->postJson("/app/tot/{$session->id}/react", ['emoji' => '👍']);

        $response->assertOk()
            ->assertJsonPath('reactions.👍', 1)
            ->assertJsonPath('mine', ['👍'])
            ->assertJsonPath('comments', 0);
    }

    public function test_reacting_twice_removes_it_and_says_so(): void
    {
        $session = $this->slot();

        $this->actingInTenant()->postJson("/app/tot/{$session->id}/react", ['emoji' => '👍']);
        $response = $this->actingInTenant()->postJson("/app/tot/{$session->id}/react", ['emoji' => '👍']);

        $response->assertOk()
            ->assertJsonPath('mine', [])
            ->assertJsonMissingPath('reactions.👍');
    }

    public function test_a_plain_form_post_still_redirects(): void
    {
        $session = $this->slot();

        $this->actingInTenant()
            ->post("/app/tot/{$session->id}/react", ['emoji' => '👍'])
            ->assertRedirect();
    }

    public function test_watching_returns_the_new_state(): void
    {
        $session = $this->slot();

        $this->actingInTenant()->postJson("/app/tot/{$session->id}/watched")
            ->assertOk()
            ->assertJsonPath('watched', 1)
            ->assertJsonPath('iWatched', true);
    }

    public function test_rating_returns_my_score_but_hides_the_summary_from_a_plain_viewer(): void
    {
        $session = $this->slot();

        $this->actingInTenant()
            ->postJson("/app/tot/{$session->id}/rate", ['score' => 4, 'note' => 'Useful'])
            ->assertOk()
            ->assertJsonPath('myScore', 4)
            ->assertJsonPath('myNote', 'Useful')
            ->assertJsonPath('score', null);
    }

    public function test_the_presenter_sees_the_score_summary(): void
    {
        $session = $this->slot();
        $session->update(['presenter_employee_id' => $this->employee->id]);

        $this->actingInTenant()
            ->postJson("/app/tot/{$session->id}/rate", ['score' => 5])
            ->assertOk()
            ->assertJsonPath('score.average', 5)
            ->assertJsonPath('score.count', 1);
    }

    public function test_the_state_never_carries_a_rater_name_or_note_list(): void
    {
        $session = $this->slot();
        $session->update(['presenter_employee_id' => $this->employee->id]);

        $response = $this->actingInTenant()
            ->postJson("/app/tot/{$session->id}/rate", ['score' => 5, 'note' => 'Secret note']);

        $this->assertArrayNotHasKey('notes', $response->json('score'));
        $response->assertJsonMissing(['name' => 'Demo']);
    }

    public function test_commenting_returns_the_new_count(): void
    {
        $session = $this->slot();

        $this->actingInTenant()
            ->postJson("/app/tot/{$session->id}/comment", ['body' => 'Good session'])
            ->assertOk()
            ->assertJsonPath('comments', 1);
    }

    /**
     * deleteComment() resolves the parent session before the row goes, so the card can be
     * told its new count. If that lookup ever moves after the delete it returns null and
     * this route 500s, which no other test would catch.
     */
    public function test_removing_a_comment_returns_the_new_count(): void
    {
        $session = $this->slot();
        $this->actingInTenant()->post("/app/tot/{$session->id}/comment", ['body' => 'Bye']);
        $comment = TotComment::where('session_id', $session->id)->firstOrFail();

        $this->actingInTenant()->deleteJson("/app/tot/comments/{$comment->id}")
            ->assertOk()
            ->assertJsonPath('comments', 0)
            ->assertJsonPath('id', $session->id);
    }
}
