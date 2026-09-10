<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkItem;
use App\Models\WorkItemComment;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * CR-08: Push to Track on card comments. Acceptance 1–7 of docs/specs/CR-08.md,
 * driven through the drawer's JSON endpoints and the Track pull API.
 */
class PushCommentToTrackTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    private Employee $junior;   // acceptance 1: plain employee

    private Employee $seniorPm; // acceptance 2: manager role

    private Employee $director;

    private Employee $adri;     // mentionable participant

    private Employee $hr;       // mints the Track pull token

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        app(CurrentTenant::class)->set($this->tenant);

        $this->junior = $this->person('Emysha', 'employee');
        $this->seniorPm = $this->person('Yati', 'manager');
        $this->director = $this->person('Shahril', 'director');
        $this->adri = $this->person('Adri', 'employee');
        $this->hr = $this->person('Hidayah', 'hr');

        $this->project = Project::create(['tenant_id' => $this->tenant->id, 'code' => 'EACC', 'name' => 'eACC', 'is_active' => true, 'track_linked_at' => now()]);

        app(CurrentTenant::class)->set(null);
    }

    protected function tearDown(): void
    {
        app(CurrentTenant::class)->set(null);
        parent::tearDown();
    }

    public function test_acceptance_1_plain_employee_sees_no_push_tick(): void
    {
        $card = $this->card($this->junior);

        $this->as($this->junior)->getJson("/app/board/{$card->id}")
            ->assertOk()
            ->assertJsonPath('card.can_push_to_track', false);
    }

    public function test_acceptance_2_senior_pm_ticks_and_the_comment_reaches_the_track_feed(): void
    {
        $card = $this->card($this->seniorPm);

        $this->as($this->seniorPm)->getJson("/app/board/{$card->id}")
            ->assertJsonPath('card.can_push_to_track', true)
            ->assertJsonPath('card.push_to_track_disabled', null);

        $this->as($this->seniorPm)->postJson("/app/board/{$card->id}/comments", ['body' => 'Payment cleared today.', 'push_to_track' => true])
            ->assertCreated()
            ->assertJsonPath('comment.pushed', true)
            ->assertJsonPath('comment.track_version', 1);

        $feed = $this->trackPull()->assertOk()->json('data.comments');
        $this->assertCount(1, $feed);
        $this->assertSame('Payment cleared today.', $feed[0]['body']);
        $this->assertSame('Yati', $feed[0]['author']);
        $this->assertSame('Manager', $feed[0]['author_role']);
        $this->assertSame($this->project->id, $feed[0]['project_id']);
        $this->assertStringContainsString("/app/board/{$card->id}", $feed[0]['card_url']);

        $this->assertDatabaseHas('port_outbox', ['port' => 'track', 'method' => 'pushComment']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'comment.pushed_to_track']);
    }

    public function test_acceptance_3_untick_needs_a_reason_and_track_sees_withdrawn_not_deleted(): void
    {
        $card = $this->card($this->seniorPm);
        $comment = $this->pushed($card, $this->seniorPm, 'Concern about bond.');

        $this->as($this->seniorPm)->patchJson("/app/board/comments/{$comment->id}", ['push_to_track' => false])
            ->assertStatus(422)->assertJsonValidationErrors('reason');

        $this->as($this->seniorPm)->patchJson("/app/board/comments/{$comment->id}", ['push_to_track' => false, 'reason' => 'Posted on wrong project'])
            ->assertOk()
            ->assertJsonPath('comment.withdrawn_reason', 'Posted on wrong project');

        // Delete on a pushed comment is a withdrawal too, never a removal.
        $other = $this->pushed($card, $this->seniorPm, 'Another record.');
        $this->as($this->seniorPm)->deleteJson("/app/board/comments/{$other->id}")->assertStatus(422);
        $this->as($this->seniorPm)->deleteJson("/app/board/comments/{$other->id}", ['reason' => 'Duplicate'])->assertOk()->assertJsonPath('withdrawn', true);
        $this->assertDatabaseHas('work_item_comments', ['id' => $other->id, 'withdrawn_reason' => 'Duplicate']);

        $feed = collect($this->trackPull()->json('data.comments'))->keyBy('id');
        $this->assertSame('Posted on wrong project', $feed[$comment->id]['withdrawn_reason']);
        $this->assertNotNull($feed[$comment->id]['withdrawn_at']);
        $this->assertDatabaseHas('port_outbox', ['port' => 'track', 'method' => 'withdrawComment']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'comment.withdrawn_from_track']);
    }

    public function test_acceptance_4_editing_a_pushed_comment_makes_v2_with_history(): void
    {
        $card = $this->card($this->seniorPm);
        $comment = $this->pushed($card, $this->seniorPm, 'Claim submitted.');

        $this->as($this->seniorPm)->patchJson("/app/board/comments/{$comment->id}", ['body' => 'Claim submitted and acknowledged.'])
            ->assertOk()
            ->assertJsonPath('comment.track_version', 2)
            ->assertJsonPath('comment.body', 'Claim submitted and acknowledged.');

        $row = $this->trackPull()->json('data.comments.0');
        $this->assertSame(2, $row['version']);
        $this->assertSame('Claim submitted and acknowledged.', $row['body']);
        $this->assertSame(['Claim submitted.', 'Claim submitted and acknowledged.'], array_column($row['versions'], 'body'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'comment.track_version']);
    }

    public function test_acceptance_5_preview_flattens_mentions_and_excludes_a_confidential_attachment(): void
    {
        $card = $this->card($this->seniorPm);
        $card->participants()->attach($this->adri->id, ['role' => 'helper']);

        $this->as($this->seniorPm)->postJson("/app/board/{$card->id}/comments/preview", [
            'body' => 'Please check with @Adri before Friday.',
            'attachments' => [
                ['name' => 'secret.pdf', 'confidential' => true, 'push' => true],
                ['name' => 'receipt.pdf', 'confidential' => false, 'push' => true],
                ['name' => 'draft.docx', 'confidential' => false, 'push' => false],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('track_body', 'Please check with Adri before Friday.')
            ->assertJsonPath('attachments.0', ['name' => 'secret.pdf', 'included' => false, 'why' => 'confidential'])
            ->assertJsonPath('attachments.1', ['name' => 'receipt.pdf', 'included' => true, 'why' => null])
            ->assertJsonPath('attachments.2', ['name' => 'draft.docx', 'included' => false, 'why' => 'not ticked']);

        $this->as($this->seniorPm)->postJson("/app/board/{$card->id}/comments", ['body' => '@Adri to follow up.', 'push_to_track' => true])->assertCreated();
        $this->assertSame('Adri to follow up.', $this->trackPull()->json('data.comments.0.body'));
        // The card keeps the mention for its own notification.
        $this->assertDatabaseHas('work_item_comments', ['body' => '@Adri to follow up.']);
    }

    public function test_acceptance_5c_only_ticked_non_confidential_files_reach_track(): void
    {
        Storage::fake('local');
        $card = $this->card($this->seniorPm);

        $res = $this->as($this->seniorPm)->post("/app/board/{$card->id}/comments", [
            'body' => 'Receipt attached.',
            'push_to_track' => '1',
            'attachments' => [
                UploadedFile::fake()->create('secret.pdf', 10, 'application/pdf'),
                UploadedFile::fake()->create('receipt.pdf', 10, 'application/pdf'),
                UploadedFile::fake()->create('draft.docx', 10, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'),
            ],
            'attachments_confidential' => [0],
            'attachments_push' => [0, 1],
        ], ['Accept' => 'application/json'])->assertCreated();

        $files = collect($res->json('comment.attachments'))->keyBy('name');
        $this->assertCount(3, $files);
        $this->assertTrue($files['secret.pdf']['confidential']);
        $this->assertFalse($files['secret.pdf']['pushed'], 'a confidential file cannot be pushed even when ticked');
        $this->assertTrue($files['receipt.pdf']['pushed']);
        $this->assertFalse($files['draft.docx']['pushed']);

        $feed = $this->trackPull()->json('data.comments.0.attachments');
        $this->assertCount(1, $feed);
        $this->assertSame('receipt.pdf', $feed[0]['name']);

        // The link Track gets is card-gated, never a public file.
        $this->as($this->seniorPm)->get($feed[0]['url'])->assertOk();
        $this->as($this->junior)->get($feed[0]['url'])->assertForbidden();
    }

    public function test_acceptance_5b_internal_card_cannot_be_pushed(): void
    {
        $card = $this->card($this->seniorPm, ['labels' => ['internal']]);

        $this->as($this->seniorPm)->getJson("/app/board/{$card->id}")
            ->assertJsonPath('card.push_to_track_disabled', 'Internal cards cannot be pushed to Track.');
        $this->as($this->seniorPm)->postJson("/app/board/{$card->id}/comments", ['body' => 'Secret.', 'push_to_track' => true])
            ->assertStatus(422)->assertJsonValidationErrors('push_to_track');
    }

    public function test_acceptance_6_director_tick_is_off_by_default_and_plain_post_does_not_push(): void
    {
        $card = $this->card($this->director);

        $this->as($this->director)->getJson("/app/board/{$card->id}")->assertJsonPath('card.can_push_to_track', true);

        // No push_to_track in the request = nothing pushed, however senior the author.
        $this->as($this->director)->postJson("/app/board/{$card->id}/comments", ['body' => 'Noted.'])
            ->assertCreated()->assertJsonPath('comment.pushed', false);
        $this->assertSame([], $this->trackPull()->json('data.comments'));
    }

    public function test_acceptance_7_unlinked_project_disables_the_tick_with_a_reason(): void
    {
        $this->project->forceFill(['track_linked_at' => null])->save();
        $card = $this->card($this->seniorPm);

        $this->as($this->seniorPm)->getJson("/app/board/{$card->id}")
            ->assertJsonPath('card.can_push_to_track', true)
            ->assertJsonPath('card.push_to_track_disabled', 'This card\'s project is not linked to Track.');
        $this->as($this->seniorPm)->postJson("/app/board/{$card->id}/comments", ['body' => 'x', 'push_to_track' => true])
            ->assertStatus(422)->assertJsonValidationErrors('push_to_track');
    }

    public function test_the_projects_pe_can_push_even_as_a_plain_employee(): void
    {
        $this->project->forceFill(['pe_id' => $this->junior->id])->save();
        $card = $this->card($this->junior);

        $this->as($this->junior)->getJson("/app/board/{$card->id}")->assertJsonPath('card.can_push_to_track', true);
        $this->as($this->junior)->postJson("/app/board/{$card->id}/comments", ['body' => 'Site visit done.', 'push_to_track' => true])->assertCreated();
        $this->assertSame('PE', $this->trackPull()->json('data.comments.0.author_role'));
    }

    public function test_a_plain_employee_forcing_the_flag_is_refused(): void
    {
        $card = $this->card($this->junior);

        $this->as($this->junior)->postJson("/app/board/{$card->id}/comments", ['body' => 'x', 'push_to_track' => true])->assertForbidden();
    }

    public function test_track_pull_marks_the_projects_it_asks_for_as_linked(): void
    {
        $this->project->forceFill(['track_linked_at' => null])->save();

        $this->trackPull(['project_ids' => (string) $this->project->id])->assertOk();

        $this->assertNotNull($this->project->fresh()->track_linked_at);
    }

    // --- helpers -----------------------------------------------------------

    private function person(string $name, string $role): Employee
    {
        $user = User::create(['name' => $name, 'email' => strtolower($name).'@example.com', 'password' => Hash::make('password')]);
        $user->tenants()->attach($this->tenant->id, ['role' => $role]);

        return Employee::create(['tenant_id' => $this->tenant->id, 'user_id' => $user->id, 'name' => $name, 'status' => 'active', 'workload' => 'green']);
    }

    private function as(Employee $employee): static
    {
        return $this->actingAs(User::find($employee->user_id))->withSession(['current_tenant' => $this->tenant->id]);
    }

    private function card(Employee $owner, array $attrs = []): WorkItem
    {
        app(CurrentTenant::class)->set($this->tenant);
        $card = WorkItem::create($attrs + [
            'tenant_id' => $this->tenant->id, 'employee_id' => $owner->id, 'project_id' => $this->project->id,
            'title' => 'Bond renewal', 'type' => 'task', 'priority' => 'medium', 'status' => 'todo', 'progress' => 0,
        ]);
        app(CurrentTenant::class)->set(null);

        return $card;
    }

    private function pushed(WorkItem $card, Employee $by, string $body): WorkItemComment
    {
        $id = $this->as($by)->postJson("/app/board/{$card->id}/comments", ['body' => $body, 'push_to_track' => true])->assertCreated()->json('comment.id');

        return WorkItemComment::withoutGlobalScopes()->findOrFail($id);
    }

    private function trackPull(array $query = [])
    {
        $token = User::find($this->hr->user_id)->mintApiToken($this->tenant, 'track', ['comments:read'])->plainTextToken;

        return $this->getJson('/api/v1/project-comments?'.http_build_query($query), ['Authorization' => 'Bearer '.$token]);
    }
}
