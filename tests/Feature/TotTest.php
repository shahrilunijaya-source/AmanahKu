<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\KnowledgeContribution;
use App\Models\Tenant;
use App\Models\TotComment;
use App\Models\TotParticipation;
use App\Models\TotReaction;
use App\Models\TotSession;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Feature coverage for the TOT sessions board.
 * Harness (setUp / actingInTenant / hrActor) copied from IdeaTest.
 */
class TotTest extends TestCase
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

    private function makeSession(array $overrides = []): TotSession
    {
        return TotSession::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'year' => 2026,
            'month' => 3,
            'presenter_employee_id' => $this->employee->id,
            'title' => 'Install git on our own server',
            'status' => 'done',
            'links' => [['label' => 'Slides', 'url' => 'https://example.com/slides']],
        ], $overrides));
    }

    // ── Schema ────────────────────────────────────────────────────

    public function test_a_session_stores_its_links_as_an_array(): void
    {
        $session = $this->makeSession();

        $this->assertSame('Slides', $session->fresh()->links[0]['label']);
    }

    public function test_one_slot_per_tenant_year_and_month(): void
    {
        $this->makeSession();

        $this->expectException(QueryException::class);

        $this->makeSession(['title' => 'Duplicate slot']);
    }

    public function test_first_saturday_is_computed_per_month(): void
    {
        $this->assertSame('2026-03-07', TotSession::firstSaturday(2026, 3)->toDateString());
        $this->assertSame('2026-08-01', TotSession::firstSaturday(2026, 8)->toDateString());
        $this->assertSame('2027-01-02', TotSession::firstSaturday(2027, 1)->toDateString());
    }

    // ── Contribution credit helper ────────────────────────────────

    public function test_mark_credits_the_month_it_is_given_not_the_current_month(): void
    {
        app(CurrentTenant::class)->set($this->tenant);

        KnowledgeContribution::mark($this->employee, 2026, 3);

        $this->assertDatabaseHas('knowledge_monthly_contributions', [
            'employee_id' => $this->employee->id,
            'year' => 2026,
            'month' => 3,
            'submitted' => true,
        ]);
    }

    public function test_mark_is_idempotent_for_the_same_month(): void
    {
        app(CurrentTenant::class)->set($this->tenant);

        KnowledgeContribution::mark($this->employee, 2026, 3);
        KnowledgeContribution::mark($this->employee, 2026, 3);

        $this->assertSame(1, KnowledgeContribution::where('employee_id', $this->employee->id)->count());
    }

    // ── Screen ────────────────────────────────────────────────────

    public function test_the_screen_renders_twelve_slots_for_the_requested_year(): void
    {
        $this->makeSession(['month' => 3, 'title' => 'Install git on our own server']);

        $response = $this->actingInTenant()->get('/app/tot?year=2026');

        $response->assertOk();
        $response->assertViewHas('sessions', fn ($sessions) => count($sessions) === 12);
        $response->assertSee('Install git on our own server');
    }

    public function test_the_screen_defaults_to_the_current_year(): void
    {
        $response = $this->actingInTenant()->get('/app/tot');

        $response->assertOk();
        $response->assertViewHas('year', (int) now()->year);
    }

    // ── Roster permissions ────────────────────────────────────────

    public function test_an_employee_cannot_create_a_slot(): void
    {
        $response = $this->actingInTenant()->post('/app/tot', [
            'year' => 2026, 'month' => 9, 'status' => 'planned',
        ]);

        $response->assertForbidden();
    }

    public function test_hr_creates_a_slot(): void
    {
        $hr = $this->hrActor();

        $response = $this->actingAs($hr)->withSession(['current_tenant' => $this->tenant->id])
            ->post('/app/tot', [
                'year' => 2026, 'month' => 9,
                'presenter_employee_id' => $this->employee->id,
                'title' => 'Queue workers in production',
                'status' => 'confirmed',
            ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('tot_sessions', [
            'year' => 2026, 'month' => 9, 'title' => 'Queue workers in production', 'status' => 'confirmed',
        ]);
    }

    public function test_hr_cannot_overwrite_an_existing_slot_by_posting_store_again(): void
    {
        $session = $this->makeSession(['year' => 2026, 'month' => 9, 'title' => 'Original title']);
        $hr = $this->hrActor();

        $response = $this->actingAs($hr)->withSession(['current_tenant' => $this->tenant->id])
            ->post('/app/tot', [
                'year' => 2026, 'month' => 9,
                'title' => 'Overwritten title',
                'status' => 'planned',
            ]);

        $response->assertStatus(422);
        $this->assertSame('Original title', $session->fresh()->title);
    }

    public function test_the_presenter_edits_their_own_slot(): void
    {
        $session = $this->makeSession(['status' => 'confirmed', 'title' => null]);

        $response = $this->actingInTenant()->post("/app/tot/{$session->id}", [
            'title' => 'Install git on our own server',
        ]);

        $response->assertRedirect();
        $this->assertSame('Install git on our own server', $session->fresh()->title);
    }

    public function test_an_employee_cannot_edit_a_slot_they_do_not_present(): void
    {
        $other = Employee::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Someone else',
            'status' => 'active', 'workload' => 'green',
        ]);
        $session = $this->makeSession(['presenter_employee_id' => $other->id]);

        $response = $this->actingInTenant()->post("/app/tot/{$session->id}", ['title' => 'Hijacked']);

        $response->assertForbidden();
    }

    public function test_the_presenter_cannot_change_the_status(): void
    {
        $session = $this->makeSession(['status' => 'confirmed']);

        $this->actingInTenant()->post("/app/tot/{$session->id}", [
            'title' => 'Install git on our own server',
            'status' => 'done',
        ]);

        $this->assertSame('confirmed', $session->fresh()->status);
    }

    public function test_an_unknown_status_is_rejected(): void
    {
        $hr = $this->hrActor();
        $session = $this->makeSession();

        $response = $this->actingAs($hr)->withSession(['current_tenant' => $this->tenant->id])
            ->post("/app/tot/{$session->id}", ['status' => 'cancelled']);

        $response->assertSessionHasErrors('status');
    }

    public function test_only_privileged_roles_delete_a_slot(): void
    {
        $session = $this->makeSession();

        $this->actingInTenant()->post("/app/tot/{$session->id}/delete")->assertForbidden();

        $hr = $this->hrActor();
        $this->actingAs($hr)->withSession(['current_tenant' => $this->tenant->id])
            ->post("/app/tot/{$session->id}/delete")->assertRedirect();

        $this->assertDatabaseMissing('tot_sessions', ['id' => $session->id]);
    }

    // ── Knowledge Bank contribution credit ────────────────────────

    public function test_marking_a_session_done_credits_the_presenter_for_that_month(): void
    {
        $hr = $this->hrActor();
        $session = $this->makeSession(['year' => 2026, 'month' => 3, 'status' => 'confirmed']);

        // Deliberately act in a different month to prove the credit follows the session.
        $this->travelTo('2026-04-20 09:00:00');

        $this->actingAs($hr)->withSession(['current_tenant' => $this->tenant->id])
            ->post("/app/tot/{$session->id}", ['status' => 'done'])
            ->assertRedirect();

        $this->assertDatabaseHas('knowledge_monthly_contributions', [
            'employee_id' => $this->employee->id, 'year' => 2026, 'month' => 3, 'submitted' => true,
        ]);
        $this->assertDatabaseMissing('knowledge_monthly_contributions', [
            'employee_id' => $this->employee->id, 'year' => 2026, 'month' => 4,
        ]);
    }

    public function test_reverting_a_session_out_of_done_keeps_the_credit(): void
    {
        $hr = $this->hrActor();
        $session = $this->makeSession(['status' => 'confirmed']);

        $this->actingAs($hr)->withSession(['current_tenant' => $this->tenant->id])
            ->post("/app/tot/{$session->id}", ['status' => 'done']);
        $this->actingAs($hr)->withSession(['current_tenant' => $this->tenant->id])
            ->post("/app/tot/{$session->id}", ['status' => 'planned']);

        $this->assertDatabaseHas('knowledge_monthly_contributions', [
            'employee_id' => $this->employee->id, 'year' => 2026, 'month' => 3,
        ]);
    }

    public function test_a_session_with_no_employee_presenter_credits_nobody(): void
    {
        $hr = $this->hrActor();
        $session = $this->makeSession([
            'presenter_employee_id' => null, 'presenter_name' => 'Team', 'status' => 'confirmed',
        ]);

        $this->actingAs($hr)->withSession(['current_tenant' => $this->tenant->id])
            ->post("/app/tot/{$session->id}", ['status' => 'done'])
            ->assertRedirect();

        $this->assertSame(0, KnowledgeContribution::count());
    }

    public function test_a_not_tot_entry_never_credits_a_month(): void
    {
        $hr = $this->hrActor();
        $session = $this->makeSession(['status' => 'not_tot', 'title' => 'Jamuan raya']);

        $this->actingAs($hr)->withSession(['current_tenant' => $this->tenant->id])
            ->post("/app/tot/{$session->id}", ['status' => 'not_tot'])
            ->assertRedirect();

        $this->assertSame(0, KnowledgeContribution::count());
    }

    /**
     * Two behaviours in one pass: editing an already-done, already-credited session
     * (a) never re-fires the credit and (b) leaves the status alone when the request
     * omits the status key entirely, because validate() drops absent nullable keys
     * from $data and fill() never touches an attribute that is not in $data.
     */
    public function test_editing_an_already_done_session_without_status_keeps_it_done_and_does_not_recredit(): void
    {
        app(CurrentTenant::class)->set($this->tenant);

        $hr = $this->hrActor();
        $session = $this->makeSession(['status' => 'done']);
        KnowledgeContribution::mark($this->employee, 2026, 3);

        $this->actingAs($hr)->withSession(['current_tenant' => $this->tenant->id])
            ->post("/app/tot/{$session->id}", ['title' => 'Install git on our own server (updated)'])
            ->assertRedirect();

        $this->assertSame('done', $session->fresh()->status);
        $this->assertSame('Install git on our own server (updated)', $session->fresh()->title);
        $this->assertSame(1, KnowledgeContribution::where('employee_id', $this->employee->id)->count());
    }

    // ── Reactions ─────────────────────────────────────────────────

    public function test_an_employee_reacts_to_a_session(): void
    {
        $session = $this->makeSession();

        $this->actingInTenant()->post("/app/tot/{$session->id}/react", ['emoji' => '👍'])
            ->assertRedirect();

        $this->assertDatabaseHas('tot_reactions', [
            'session_id' => $session->id, 'employee_id' => $this->employee->id, 'emoji' => '👍',
        ]);
    }

    public function test_reacting_twice_with_the_same_emoji_removes_it(): void
    {
        $session = $this->makeSession();

        $this->actingInTenant()->post("/app/tot/{$session->id}/react", ['emoji' => '👍']);
        $this->actingInTenant()->post("/app/tot/{$session->id}/react", ['emoji' => '👍']);

        $this->assertDatabaseMissing('tot_reactions', [
            'session_id' => $session->id, 'employee_id' => $this->employee->id, 'emoji' => '👍',
        ]);
    }

    public function test_one_person_may_hold_several_different_emoji(): void
    {
        $session = $this->makeSession();

        $this->actingInTenant()->post("/app/tot/{$session->id}/react", ['emoji' => '👍']);
        $this->actingInTenant()->post("/app/tot/{$session->id}/react", ['emoji' => '🔥']);

        $this->assertSame(2, TotReaction::where('session_id', $session->id)->count());
    }

    public function test_an_emoji_outside_the_whitelist_is_rejected(): void
    {
        $session = $this->makeSession();

        $this->actingInTenant()->post("/app/tot/{$session->id}/react", ['emoji' => '💩'])
            ->assertSessionHasErrors('emoji');

        $this->assertSame(0, TotReaction::count());
    }

    /**
     * Simulates the lost side of a race between two near-simultaneous reacts for the same
     * person, session and emoji. The controller's own read finds no row (that check ran
     * first, same as a real race), but by the time its create() reaches the database another
     * request has already inserted the same row. We fake that interleaving with a model
     * "creating" hook: the moment this request's create() starts, the hook fires a second,
     * "winning" create for the identical row, so this request's own insert then collides
     * with a row that exists for real. The guard flag stops the hook recursing on that
     * nested create and makes it permanently inert afterwards, so it is harmless if it
     * fires again for unrelated TotReaction rows later in the test run.
     * Covers: the QueryException catch path in react() through the real HTTP route.
     * Does not cover: genuine simultaneous DB connections/threads.
     */
    public function test_a_concurrent_react_race_is_absorbed_not_fatal(): void
    {
        $session = $this->makeSession();
        $raced = false;

        TotReaction::creating(function () use ($session, &$raced): void {
            if ($raced) {
                return;
            }
            $raced = true;

            TotReaction::create([
                'tenant_id' => $this->tenant->id,
                'session_id' => $session->id,
                'employee_id' => $this->employee->id,
                'emoji' => '👍',
            ]);
        });

        $response = $this->actingInTenant()->post("/app/tot/{$session->id}/react", ['emoji' => '👍']);

        $response->assertRedirect();
        $this->assertSame(1, TotReaction::where('session_id', $session->id)
            ->where('employee_id', $this->employee->id)
            ->where('emoji', '👍')
            ->count());
    }

    // ── Watched and rating ────────────────────────────────────────

    public function test_an_employee_marks_a_session_watched(): void
    {
        $session = $this->makeSession();

        $this->actingInTenant()->post("/app/tot/{$session->id}/watched")->assertRedirect();

        $row = TotParticipation::where('session_id', $session->id)->firstOrFail();
        $this->assertNotNull($row->watched_at);
    }

    public function test_rating_twice_updates_the_same_row(): void
    {
        $session = $this->makeSession();

        $this->actingInTenant()->post("/app/tot/{$session->id}/rate", ['score' => 3]);
        $this->actingInTenant()->post("/app/tot/{$session->id}/rate", ['score' => 5, 'note' => 'Clearer than I expected.']);

        $this->assertSame(1, TotParticipation::where('session_id', $session->id)->count());
        $row = TotParticipation::where('session_id', $session->id)->firstOrFail();
        $this->assertSame(5, (int) $row->score);
        $this->assertSame('Clearer than I expected.', $row->note);
    }

    public function test_a_score_outside_one_to_five_is_rejected(): void
    {
        $session = $this->makeSession();

        $this->actingInTenant()->post("/app/tot/{$session->id}/rate", ['score' => 9])
            ->assertSessionHasErrors('score');
    }

    public function test_rating_also_marks_the_session_watched(): void
    {
        $session = $this->makeSession();

        $this->actingInTenant()->post("/app/tot/{$session->id}/rate", ['score' => 4]);

        $row = TotParticipation::where('session_id', $session->id)->firstOrFail();
        $this->assertNotNull($row->watched_at);
    }

    public function test_rating_an_already_watched_session_does_not_move_watched_at_forward(): void
    {
        $session = $this->makeSession();

        $this->travelTo('2026-03-10 09:00:00');
        $this->actingInTenant()->post("/app/tot/{$session->id}/watched");
        $watchedAt = TotParticipation::where('session_id', $session->id)->firstOrFail()->watched_at;

        $this->travelTo('2026-03-11 09:00:00');
        $this->actingInTenant()->post("/app/tot/{$session->id}/rate", ['score' => 4]);

        $row = TotParticipation::where('session_id', $session->id)->firstOrFail();
        $this->assertTrue($watchedAt->equalTo($row->watched_at));
    }

    /**
     * Reverse order of the test above: rate first, then press watched. Pins the same
     * ??= rule from the other direction, since watched() has its own independent ??=
     * on the same column and nothing stops the two call orders from drifting apart.
     */
    public function test_marking_watched_after_rating_does_not_move_watched_at_forward(): void
    {
        $session = $this->makeSession();

        $this->travelTo('2026-03-10 09:00:00');
        $this->actingInTenant()->post("/app/tot/{$session->id}/rate", ['score' => 4]);
        $watchedAt = TotParticipation::where('session_id', $session->id)->firstOrFail()->watched_at;

        $this->travelTo('2026-03-11 09:00:00');
        $this->actingInTenant()->post("/app/tot/{$session->id}/watched");

        $row = TotParticipation::where('session_id', $session->id)->firstOrFail();
        $this->assertTrue($watchedAt->equalTo($row->watched_at));
    }

    /**
     * Simulates the lost side of a race between two near-simultaneous first-time ratings
     * for the same person and session, using the same model "creating" hook technique as
     * test_a_concurrent_react_race_is_absorbed_not_fatal. Unlike a reaction, the two
     * competing ratings carry different scores, so the loser's submission must survive
     * the race, not be dropped: rate() re-reads the row the "winner" inserted and
     * overwrites it with what this request actually submitted.
     * Covers: the recovery branch of the QueryException catch path in rate() through the
     * real HTTP route.
     * Does not cover: genuine simultaneous DB connections/threads, or a second collision
     * on the recovery save() itself (not reachable: that save() is an UPDATE keyed by the
     * row's id, not an INSERT keyed by the unique (session_id, employee_id) pair).
     */
    public function test_a_concurrent_first_rating_race_keeps_this_requests_score(): void
    {
        $session = $this->makeSession();
        $raced = false;

        TotParticipation::creating(function () use ($session, &$raced): void {
            if ($raced) {
                return;
            }
            $raced = true;

            TotParticipation::create([
                'tenant_id' => $this->tenant->id,
                'session_id' => $session->id,
                'employee_id' => $this->employee->id,
                'score' => 2,
                'watched_at' => now(),
            ]);
        });

        $response = $this->actingInTenant()->post("/app/tot/{$session->id}/rate", [
            'score' => 5, 'note' => 'Genuinely useful.',
        ]);

        $response->assertRedirect();
        $this->assertSame(1, TotParticipation::where('session_id', $session->id)->count());
        $row = TotParticipation::where('session_id', $session->id)->firstOrFail();
        $this->assertSame(5, (int) $row->score);
        $this->assertSame('Genuinely useful.', $row->note);
    }

    // ── Rating privacy ────────────────────────────────────────────

    public function test_a_plain_employee_never_receives_scores(): void
    {
        $other = Employee::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Presenter',
            'status' => 'active', 'workload' => 'green',
        ]);
        $session = $this->makeSession(['presenter_employee_id' => $other->id]);
        TotParticipation::create([
            'tenant_id' => $this->tenant->id, 'session_id' => $session->id,
            'employee_id' => $this->employee->id, 'score' => 5, 'note' => 'Very good',
        ]);

        $response = $this->actingInTenant()->get('/app/tot?year=2026');

        $response->assertViewHas('scores', fn ($scores) => $scores === []);
        $response->assertDontSee('Very good');
    }

    public function test_the_presenter_sees_their_own_average_and_notes(): void
    {
        $session = $this->makeSession();
        $rater = Employee::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Rater',
            'status' => 'active', 'workload' => 'green',
        ]);
        TotParticipation::create([
            'tenant_id' => $this->tenant->id, 'session_id' => $session->id,
            'employee_id' => $rater->id, 'score' => 4, 'note' => 'Useful',
        ]);

        $response = $this->actingInTenant()->get('/app/tot?year=2026');

        $response->assertViewHas('scores', function ($scores) use ($session) {
            return isset($scores[$session->id])
                && $scores[$session->id]['average'] === 4.0
                && $scores[$session->id]['count'] === 1
                && $scores[$session->id]['notes'] === ['Useful'];
        });
    }

    public function test_hr_sees_scores_on_every_session(): void
    {
        $other = Employee::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Presenter',
            'status' => 'active', 'workload' => 'green',
        ]);
        $session = $this->makeSession(['presenter_employee_id' => $other->id]);
        TotParticipation::create([
            'tenant_id' => $this->tenant->id, 'session_id' => $session->id,
            'employee_id' => $this->employee->id, 'score' => 2,
        ]);

        $hr = $this->hrActor();
        $response = $this->actingAs($hr)->withSession(['current_tenant' => $this->tenant->id])
            ->get('/app/tot?year=2026');

        $response->assertViewHas('scores', fn ($scores) => isset($scores[$session->id]));
    }

    // ── Discussion ────────────────────────────────────────────────

    public function test_an_employee_posts_a_comment(): void
    {
        $session = $this->makeSession();

        $this->actingInTenant()->post("/app/tot/{$session->id}/comment", [
            'body' => 'Will this cover access control, or only the install?',
        ])->assertRedirect();

        $this->assertDatabaseHas('tot_comments', [
            'session_id' => $session->id,
            'employee_id' => $this->employee->id,
            'body' => 'Will this cover access control, or only the install?',
        ]);
    }

    public function test_an_empty_comment_is_rejected(): void
    {
        $session = $this->makeSession();

        $this->actingInTenant()->post("/app/tot/{$session->id}/comment", ['body' => ''])
            ->assertSessionHasErrors('body');
    }

    public function test_a_person_deletes_their_own_comment(): void
    {
        $session = $this->makeSession();
        $comment = TotComment::create([
            'tenant_id' => $this->tenant->id, 'session_id' => $session->id,
            'employee_id' => $this->employee->id, 'body' => 'Mine',
        ]);

        $this->actingInTenant()->delete("/app/tot/comments/{$comment->id}")->assertRedirect();

        $this->assertDatabaseMissing('tot_comments', ['id' => $comment->id]);
    }

    public function test_an_employee_cannot_delete_someone_elses_comment(): void
    {
        $session = $this->makeSession();
        $other = Employee::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Other',
            'status' => 'active', 'workload' => 'green',
        ]);
        $comment = TotComment::create([
            'tenant_id' => $this->tenant->id, 'session_id' => $session->id,
            'employee_id' => $other->id, 'body' => 'Theirs',
        ]);

        $this->actingInTenant()->delete("/app/tot/comments/{$comment->id}")->assertForbidden();
    }

    public function test_hr_deletes_any_comment(): void
    {
        $session = $this->makeSession();
        $comment = TotComment::create([
            'tenant_id' => $this->tenant->id, 'session_id' => $session->id,
            'employee_id' => $this->employee->id, 'body' => 'Needs moderating',
        ]);

        $hr = $this->hrActor();
        $this->actingAs($hr)->withSession(['current_tenant' => $this->tenant->id])
            ->delete("/app/tot/comments/{$comment->id}")->assertRedirect();

        $this->assertDatabaseMissing('tot_comments', ['id' => $comment->id]);
    }

    public function test_the_screen_carries_comments_per_session(): void
    {
        $session = $this->makeSession();
        TotComment::create([
            'tenant_id' => $this->tenant->id, 'session_id' => $session->id,
            'employee_id' => $this->employee->id, 'body' => 'Saved this one.',
        ]);

        $response = $this->actingInTenant()->get('/app/tot?year=2026');

        $response->assertViewHas('comments', fn ($comments) => count($comments[$session->id]) === 1);
    }
}
