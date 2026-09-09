<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\PlotTwistPoll;
use App\Models\PlotTwistQuestion;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkItem;
use App\Models\WorkItemComment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\Acceptance\AlwaysChecks;
use Tests\TestCase;

/**
 * S24 / CR-25: coverage the frozen CR25Test (tests/Acceptance) does not exercise
 * directly — audit entries for the non-anonymous actions, cross-tenant denial on
 * the bound-poll routes, the "named employee must be this tenant's" validation,
 * and the CR-18 feed's "no card yet" / "already fed" idempotency paths.
 */
class PlotTwistTest extends TestCase
{
    use AlwaysChecks;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-07 09:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function payload(array $over = []): array
    {
        return array_merge([
            'question' => "Unijaya's unofficial national food?",
            'kind' => 'fun',
            'options' => ['Nasi lemak', 'Roti canai'],
            'opens_on' => '2026-09-07',
        ], $over);
    }

    #[Test]
    public function test_publish_and_suggest_write_audit_entries_with_the_actor_attached(): void
    {
        $hr = $this->person('Hidayah HR', 'hr');
        $shazwan = $this->person('Shazwan Dev');

        $this->actingInTenantAs($hr)->post('/app/plot-twist', $this->payload())->assertSessionHasNoErrors();
        $poll = PlotTwistPoll::sole();
        $published = AuditLog::where('action', 'plot_twist.published')->first();
        $this->assertNotNull($published);
        $this->assertSame($hr->user_id, $published->user_id);
        $this->assertSame("plot_twist_poll:{$poll->id}", $published->target);

        $this->actingInTenantAs($shazwan)
            ->post('/app/plot-twist/suggest', ['text' => 'Best kopitiam?', 'kind' => 'fun'])
            ->assertSessionHasNoErrors();
        $question = PlotTwistQuestion::sole();
        $suggested = AuditLog::where('action', 'plot_twist.suggested')->first();
        $this->assertNotNull($suggested);
        $this->assertSame($shazwan->user_id, $suggested->user_id);
        $this->assertSame("plot_twist_question:{$question->id}", $suggested->target);
    }

    #[Test]
    public function test_a_poll_owned_by_another_tenant_is_never_reachable(): void
    {
        $hr = $this->person('Hidayah HR', 'hr');
        $this->actingInTenantAs($hr)->post('/app/plot-twist', $this->payload())->assertSessionHasNoErrors();
        $poll = PlotTwistPoll::sole();
        $option = $poll->options->first();

        $otherTenant = Tenant::create(['slug' => 'bravo', 'name' => 'Bravo', 'initials' => 'BR']);
        $attacker = User::create(['name' => 'Attacker', 'email' => 'attacker@example.com', 'password' => Hash::make('password')]);
        $attacker->tenants()->attach($otherTenant->id, ['role' => 'director']);
        $attackerEmployee = Employee::create(['tenant_id' => $otherTenant->id, 'user_id' => $attacker->id, 'name' => 'Attacker', 'status' => 'active', 'workload' => 'green']);

        $acting = fn () => $this->actingAs($attacker)->withSession(['current_tenant' => $otherTenant->id]);

        $acting()->postJson("/app/plot-twist/{$poll->id}/vote", ['option_id' => $option->id])->assertStatus(404);
        $acting()->post("/app/plot-twist/{$poll->id}/opt-out")->assertStatus(404);
        $acting()->getJson("/app/plot-twist/{$poll->id}/results")->assertStatus(404);
        $this->assertNotNull($attackerEmployee->id);
        $this->assertSame(0, DB::table('plot_twist_votes')->where('poll_id', $poll->id)->count());
    }

    #[Test]
    public function test_a_who_poll_cannot_name_an_employee_from_another_tenant(): void
    {
        $hr = $this->person('Hidayah HR', 'hr');
        PlotTwistQuestion::create(['tenant_id' => $this->tenant()->id, 'text' => 'Who brings the best lunch?', 'kind' => 'who', 'template' => true, 'approved' => true]);

        $otherTenant = Tenant::create(['slug' => 'bravo', 'name' => 'Bravo', 'initials' => 'BR']);
        $foreignEmployee = Employee::create(['tenant_id' => $otherTenant->id, 'name' => 'Foreigner', 'status' => 'active', 'workload' => 'green']);

        $this->actingInTenantAs($hr)->post('/app/plot-twist', $this->payload([
            'kind' => 'who', 'question' => 'Who brings the best lunch?', 'named_employee_id' => $foreignEmployee->id,
        ]))->assertSessionHasErrors('named_employee_id');

        $this->assertSame(0, PlotTwistPoll::count());
    }

    #[Test]
    public function test_social_poll_feed_skips_quietly_when_no_matching_card_then_writes_once_a_card_exists(): void
    {
        $hr = $this->person('Hidayah HR', 'hr');
        $voter = $this->person('Yati Dev');

        $this->actingInTenantAs($hr)->post('/app/plot-twist', $this->payload([
            'kind' => 'social', 'question' => 'Next social activity?', 'options' => ['Bowling', 'Karaoke'],
        ]))->assertSessionHasNoErrors();
        $poll = PlotTwistPoll::sole();
        $bowling = $poll->options()->where('label', 'Bowling')->sole();

        $this->actingInTenantAs($voter)->postJson("/app/plot-twist/{$poll->id}/vote", ['option_id' => $bowling->id])->assertOk();

        // No matching card yet: rendering results must not throw, and must not touch idea_fed_at.
        Carbon::setTestNow('2026-09-11 15:00:00');
        $this->actingInTenantAs($hr)->getJson("/app/plot-twist/{$poll->id}/results")->assertOk();
        $this->assertNull($poll->fresh()->idea_fed_at);
        $this->assertSame(0, WorkItemComment::count());

        // A matching card appears later: the next render feeds it exactly once.
        $card = WorkItem::create([
            'tenant_id' => $this->tenant()->id, 'title' => 'Plan next social activity', 'type' => 'task',
            'priority' => 'low', 'status' => 'todo', 'progress' => 0, 'labels' => ['recurring'],
        ]);

        $this->actingInTenantAs($hr)->getJson("/app/plot-twist/{$poll->id}/results")->assertOk();
        $this->assertNotNull($poll->fresh()->idea_fed_at);
        $this->assertSame(1, WorkItemComment::where('work_item_id', $card->id)->count());
        $this->assertStringContainsString('Bowling', WorkItemComment::sole()->body);

        // Rendered again: no duplicate comment.
        $this->actingInTenantAs($hr)->getJson("/app/plot-twist/{$poll->id}/results")->assertOk();
        $this->assertSame(1, WorkItemComment::where('work_item_id', $card->id)->count());
    }
}
