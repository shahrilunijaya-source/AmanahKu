<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Tenant;
use App\Models\TotComment;
use App\Models\TotParticipation;
use App\Models\TotReaction;
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

    public function test_the_thread_loads_on_demand(): void
    {
        $session = $this->slot();
        $this->actingInTenant()->post("/app/tot/{$session->id}/comment", ['body' => 'First']);

        $this->actingInTenant()->getJson("/app/tot/{$session->id}/comments")
            ->assertOk()
            ->assertJsonPath('comments.0.body', 'First')
            ->assertJsonPath('comments.0.name', 'Demo')
            ->assertJsonPath('comments.0.canDelete', true);
    }

    public function test_the_thread_carries_only_this_session(): void
    {
        $mine = $this->slot();
        $other = TotSession::create([
            'tenant_id' => $this->tenant->id, 'year' => 2026, 'month' => 10, 'status' => 'planned',
        ]);
        $this->actingInTenant()->post("/app/tot/{$mine->id}/comment", ['body' => 'Mine']);
        $this->actingInTenant()->post("/app/tot/{$other->id}/comment", ['body' => 'Other']);

        $this->actingInTenant()->getJson("/app/tot/{$mine->id}/comments")
            ->assertOk()
            ->assertJsonCount(1, 'comments')
            ->assertJsonPath('comments.0.body', 'Mine');
    }

    public function test_the_presenter_gets_the_anonymous_notes_with_the_thread(): void
    {
        $session = $this->slot();
        $session->update(['presenter_employee_id' => $this->employee->id]);
        $this->actingInTenant()->post("/app/tot/{$session->id}/rate", ['score' => 5, 'note' => 'Clear slides']);

        $this->actingInTenant()->getJson("/app/tot/{$session->id}/comments")
            ->assertOk()
            ->assertJsonPath('notes', ['Clear slides']);
    }

    public function test_a_plain_viewer_gets_no_notes(): void
    {
        $session = $this->slot();
        $this->actingInTenant()->post("/app/tot/{$session->id}/rate", ['score' => 5, 'note' => 'Clear slides']);

        $this->actingInTenant()->getJson("/app/tot/{$session->id}/comments")
            ->assertOk()
            ->assertJsonPath('notes', []);
    }

    public function test_a_foreign_tenant_thread_is_not_readable(): void
    {
        $other = Tenant::create(['slug' => 'beta', 'name' => 'Beta', 'initials' => 'BT']);
        $foreign = TotSession::create([
            'tenant_id' => $other->id, 'year' => 2026, 'month' => 11, 'status' => 'planned',
        ]);

        $this->actingInTenant()->getJson("/app/tot/{$foreign->id}/comments")->assertNotFound();
    }

    public function test_every_action_still_works_as_a_plain_form_post(): void
    {
        $session = $this->slot();

        $this->actingInTenant()->post("/app/tot/{$session->id}/react", ['emoji' => '👍'])->assertRedirect();
        $this->actingInTenant()->post("/app/tot/{$session->id}/watched")->assertRedirect();
        $this->actingInTenant()->post("/app/tot/{$session->id}/rate", ['score' => 3])->assertRedirect();
        $this->actingInTenant()->post("/app/tot/{$session->id}/comment", ['body' => 'Plain post'])->assertRedirect();

        $this->assertSame(1, TotComment::where('session_id', $session->id)->count());
        $this->assertSame(3, TotParticipation::where('session_id', $session->id)->value('score'));
    }

    public function test_watching_twice_takes_it_back(): void
    {
        $session = $this->slot();

        $this->actingInTenant()->postJson("/app/tot/{$session->id}/watched");
        $response = $this->actingInTenant()->postJson("/app/tot/{$session->id}/watched");

        $response->assertOk()
            ->assertJsonPath('watched', 0)
            ->assertJsonPath('iWatched', false);

        $this->assertNull(
            TotParticipation::where('session_id', $session->id)->value('watched_at')
        );
    }

    public function test_un_watching_leaves_your_score_alone(): void
    {
        $session = $this->slot();

        $this->actingInTenant()->postJson("/app/tot/{$session->id}/rate", ['score' => 4]);
        $this->actingInTenant()->postJson("/app/tot/{$session->id}/watched");

        $row = TotParticipation::where('session_id', $session->id)->first();

        $this->assertNull($row->watched_at);
        $this->assertSame(4, $row->score);
    }

    public function test_rating_the_same_score_again_clears_it_and_its_note(): void
    {
        $session = $this->slot();

        $this->actingInTenant()->postJson("/app/tot/{$session->id}/rate", ['score' => 4, 'note' => 'Useful']);
        $response = $this->actingInTenant()->postJson("/app/tot/{$session->id}/rate", ['score' => null]);

        $response->assertOk()->assertJsonPath('myScore', null);

        $row = TotParticipation::where('session_id', $session->id)->first();
        $this->assertNull($row->score);
        $this->assertNull($row->note, 'a note with no score is orphaned');
        $this->assertNotNull($row->watched_at, 'you still watched it');
    }

    public function test_clearing_a_rating_you_never_gave_creates_no_row(): void
    {
        $session = $this->slot();

        $this->actingInTenant()->postJson("/app/tot/{$session->id}/rate", ['score' => null])
            ->assertOk()
            ->assertJsonPath('myScore', null)
            ->assertJsonPath('iWatched', false);

        $this->assertSame(0, TotParticipation::where('session_id', $session->id)->count());
    }

    public function test_a_cleared_rating_drops_out_of_the_average_and_the_notes(): void
    {
        $session = $this->slot();
        $session->update(['presenter_employee_id' => $this->employee->id]);

        $other = Employee::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Other',
            'status' => 'active', 'workload' => 'green',
        ]);
        TotParticipation::create([
            'tenant_id' => $this->tenant->id, 'session_id' => $session->id, 'employee_id' => $other->id,
            'score' => 2, 'note' => 'Theirs', 'watched_at' => now(),
        ]);

        $this->actingInTenant()->postJson("/app/tot/{$session->id}/rate", ['score' => 4, 'note' => 'Mine']);
        $response = $this->actingInTenant()->postJson("/app/tot/{$session->id}/rate", ['score' => null]);

        $response->assertOk()
            ->assertJsonPath('score.average', 2)
            ->assertJsonPath('score.count', 1);
    }

    public function test_an_out_of_range_score_is_still_rejected(): void
    {
        $session = $this->slot();

        $this->actingInTenant()->postJson("/app/tot/{$session->id}/rate", ['score' => 6])
            ->assertStatus(422);
    }

    public function test_a_second_emoji_replaces_the_first(): void
    {
        $session = $this->slot();

        $this->actingInTenant()->postJson("/app/tot/{$session->id}/react", ['emoji' => '👍']);
        $response = $this->actingInTenant()->postJson("/app/tot/{$session->id}/react", ['emoji' => '🔥']);

        $response->assertOk()->assertJsonPath('mine', ['🔥']);

        $this->assertSame(1, TotReaction::where('session_id', $session->id)
            ->where('employee_id', $this->employee->id)->count(), 'one emoji per person');
        $this->assertJsonStringEqualsJsonString(
            json_encode(['🔥' => 1]),
            json_encode($response->json('reactions')),
            'the first emoji is gone from the counts, not just from mine'
        );
    }

    public function test_pressing_the_same_emoji_still_removes_it(): void
    {
        $session = $this->slot();

        $this->actingInTenant()->postJson("/app/tot/{$session->id}/react", ['emoji' => '👍']);
        $response = $this->actingInTenant()->postJson("/app/tot/{$session->id}/react", ['emoji' => '👍']);

        $response->assertOk()->assertJsonPath('mine', []);
        $this->assertSame(0, TotReaction::where('session_id', $session->id)->count());
    }

    public function test_replacing_your_emoji_leaves_other_people_alone(): void
    {
        $session = $this->slot();

        $other = Employee::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Other',
            'status' => 'active', 'workload' => 'green',
        ]);
        TotReaction::create([
            'tenant_id' => $this->tenant->id, 'session_id' => $session->id,
            'employee_id' => $other->id, 'emoji' => '👍',
        ]);

        $this->actingInTenant()->postJson("/app/tot/{$session->id}/react", ['emoji' => '👍']);
        $response = $this->actingInTenant()->postJson("/app/tot/{$session->id}/react", ['emoji' => '🔥']);

        $response->assertOk()->assertJsonPath('mine', ['🔥']);
        $this->assertSame(1, TotReaction::where('session_id', $session->id)
            ->where('employee_id', $other->id)->count(), 'their reaction survives');
        $this->assertSame(1, $response->json('reactions.👍'), 'and still counts');
    }
}
