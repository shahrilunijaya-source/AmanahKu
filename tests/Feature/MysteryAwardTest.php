<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use App\Support\AwardBoard;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * CR-27 edge cases CR27Test.php doesn't cover. CR27Test's own leak-check
 * (test_acceptance_2) uses the spec's example category "Professional Tab
 * Collector" as a literal secret, which collides with the pre-existing CR-31
 * `tab_collector` easter egg unconditionally embedded in layouts/app.blade.php
 * (see docs/build/OPEN.md and docs/build/sessions/S27/handoff.md) — that
 * failure is out of this session's scope. This file re-runs the same leak
 * matrix with a category/explanation that collides with nothing (grepped the
 * repo first), so the Mystery Award's own "hidden before publish" behaviour
 * still has a passing, non-vacuous check across all five pages, four
 * viewers, and both pre-publish timestamps CR27Test itself uses.
 */
class MysteryAwardTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function tenant(): Tenant
    {
        return Tenant::firstOrCreate(['slug' => 'acme'], ['name' => 'Acme', 'initials' => 'AC']);
    }

    private function person(string $name, string $role = 'employee', array $attrs = []): Employee
    {
        $user = User::create(['name' => $name, 'email' => strtolower(preg_replace('/\W+/', '', $name)).uniqid().'@example.com', 'password' => Hash::make('password')]);
        $user->tenants()->attach($this->tenant()->id, ['role' => $role]);

        return Employee::create(array_merge(['tenant_id' => $this->tenant()->id, 'user_id' => $user->id, 'name' => $name, 'status' => 'active', 'workload' => 'green'], $attrs));
    }

    private function actingInTenantAs(Employee $employee): static
    {
        $this->actingAs($employee->user)->withSession(['current_tenant' => $this->tenant()->id]);

        return $this;
    }

    #[Test]
    public function test_category_stays_hidden_across_every_page_and_viewer_with_a_non_colliding_secret(): void
    {
        $director = $this->person('Director', 'director');
        $hr = $this->person('HR', 'hr');
        $winner = $this->person('Winner');
        $viewer = $this->person('Viewer');

        Carbon::setTestNow('2026-09-29 10:00:00');
        $this->actingInTenantAs($director)->post('/app/awards/mystery', [
            'employee_id' => $winner->id,
            'category' => 'Reply-All Reflex',
            'explanation' => 'Turned a typo into a tuesday-long thread, again.',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $secrets = ['Reply-All Reflex', 'turned a typo into a tuesday-long thread', 'data-slide="mystery"', 'data-award="mystery"'];
        $pages = ['/app/dash', '/app/awards', '/app/awards?month=2026-09-01', '/app/profile', '/app/profile?emp='.$winner->id];

        foreach (['2026-09-29 10:05:00', '2026-09-30 23:00:00'] as $at) {
            Carbon::setTestNow($at);
            foreach ([$director, $winner, $viewer, $hr] as $who) {
                foreach ($pages as $url) {
                    $html = $this->actingInTenantAs($who)->get($url)->assertOk()->getContent();
                    foreach ($secrets as $secret) {
                        $this->assertStringNotContainsString(strtolower($secret), strtolower($html), "{$secret} leaked on {$url} for {$who->name} at {$at}");
                    }
                }
            }
        }

        // The picker sees a sealed marker, never the content; a non-privileged viewer
        // sees neither the mystery block nor the pick form at all.
        $select = $this->actingInTenantAs($director)->get('/app/awards')->assertOk()->getContent();
        $this->assertStringContainsString('data-mystery-picked="2026-09-01"', $select);
        $plainViewerSelect = $this->actingInTenantAs($viewer)->get('/app/awards')->assertOk()->getContent();
        $this->assertStringNotContainsString('Mystery Award', $plainViewerSelect);
        $this->assertStringNotContainsString('Seal it', $plainViewerSelect);

        // Reveal: now it is on the dashboard, last slide, and on the Awards screen.
        Carbon::setTestNow('2026-10-01 08:00:00');
        Artisan::call('awards:publish');
        Carbon::setTestNow('2026-10-01 10:00:00');
        $dash = $this->actingInTenantAs($winner)->get('/app/dash')->assertOk()->getContent();
        $this->assertStringContainsString('Reply-All Reflex', $dash);
        $this->assertStringContainsString('data-winner="'.$winner->id.'"', $dash);
        $this->assertSame(strrpos($dash, 'data-slide="'), strpos($dash, 'data-slide="mystery"'), 'mystery slide is not last');
    }

    #[Test]
    public function test_category_over_80_chars_and_explanation_over_500_chars_are_rejected(): void
    {
        $director = $this->person('Director', 'director');
        $winner = $this->person('Winner');

        Carbon::setTestNow('2026-09-29 10:00:00');
        $this->actingInTenantAs($director)->post('/app/awards/mystery', [
            'employee_id' => $winner->id,
            'category' => str_repeat('a', 81),
            'explanation' => 'fine',
        ])->assertSessionHasErrors('category');

        $this->actingInTenantAs($director)->post('/app/awards/mystery', [
            'employee_id' => $winner->id,
            'category' => 'fine',
            'explanation' => str_repeat('a', 501),
        ])->assertSessionHasErrors('explanation');

        $this->assertSame(0, DB::table('mystery_awards')->count());

        // Exactly at the limit is accepted.
        $this->actingInTenantAs($director)->post('/app/awards/mystery', [
            'employee_id' => $winner->id,
            'category' => str_repeat('b', 80),
            'explanation' => str_repeat('c', 500),
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(1, DB::table('mystery_awards')->count());
    }

    #[Test]
    public function test_committee_refuses_an_archived_employee(): void
    {
        $director = $this->person('Director', 'director');
        $a = $this->person('A');
        $b = $this->person('B');
        $archived = $this->person('Archived', 'employee', ['archived_at' => now()]);

        Carbon::setTestNow('2026-09-29 10:00:00');
        $this->actingInTenantAs($director)->post('/app/awards/mystery/committee', [
            'employee_ids' => [$a->id, $b->id, $archived->id],
        ])->assertSessionHasErrors('employee_ids');
        $this->assertSame(0, DB::table('mystery_committee')->count());
    }

    #[Test]
    public function test_self_pick_is_refused_server_side(): void
    {
        $director = $this->person('Director', 'director');

        Carbon::setTestNow('2026-09-29 10:00:00');
        $this->actingInTenantAs($director)->post('/app/awards/mystery', [
            'employee_id' => $director->id,
            'category' => 'Self Starter',
            'explanation' => 'Nominated themselves, bold move.',
        ])->assertSessionHasErrors('employee_id');
        $this->assertSame(0, DB::table('mystery_awards')->count());
    }

    #[Test]
    public function test_mystery_alone_renders_as_the_sole_slide_with_no_regular_award_that_month(): void
    {
        $director = $this->person('Director', 'director');
        $winner = $this->person('Winner');

        DB::table('mystery_awards')->insert([
            'tenant_id' => $this->tenant()->id, 'month' => '2026-09-01', 'employee_id' => $winner->id,
            'category' => 'Solo Act', 'explanation' => 'Only award standing this month.',
            'picked_by' => $director->id, 'published_at' => '2026-10-01 08:00:00',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        app(CurrentTenant::class)->set($this->tenant());
        $slides = AwardBoard::slidesForMonth('2026-09-01');
        $this->assertCount(1, $slides);
        $this->assertSame('mystery', $slides->first()->award_key);
    }

    #[Test]
    public function test_committee_member_sees_select_tab_and_form_but_not_the_committee_roster_control(): void
    {
        $director = $this->person('Director', 'director');
        $member = $this->person('Committee Member');

        Carbon::setTestNow('2026-09-29 10:00:00');
        $this->actingInTenantAs($director)->post('/app/awards/mystery/committee', [
            'employee_ids' => [$member->id, $this->person('B')->id, $this->person('C')->id],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $page = $this->actingInTenantAs($member)->get('/app/awards')->assertOk()->getContent();
        $this->assertStringContainsString('Mystery Award', $page);
        $this->assertStringContainsString('action="'.url('/app/awards/mystery').'"', $page);
        $this->assertStringNotContainsString('Save committee', $page);
    }

    #[Test]
    public function test_mystery_pick_and_committee_writes_are_audited(): void
    {
        $director = $this->person('Director', 'director');
        $winner = $this->person('Winner');

        Carbon::setTestNow('2026-09-29 10:00:00');
        $this->actingInTenantAs($director)->post('/app/awards/mystery/committee', [
            'employee_ids' => [$winner->id, $this->person('B')->id, $this->person('C')->id],
        ])->assertRedirect();
        $this->assertSame(1, AuditLog::where('action', 'award.mystery_committee')->count());

        $this->actingInTenantAs($director)->post('/app/awards/mystery', [
            'employee_id' => $winner->id,
            'category' => 'Solo Winner',
            'explanation' => 'Only person left to pick.',
        ])->assertRedirect();
        $this->assertSame(1, AuditLog::where('action', 'award.mystery_picked')->count());
    }
}
