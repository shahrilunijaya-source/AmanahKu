<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkItem;
use App\Models\WrappedArc;
use App\Models\WrappedStory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * CR-22 edge cases CR22Test.php doesn't cover: cross-tenant 404 on a bound Wrapped model
 * (route-model binding is not tenant-scoped, see docs/build/RULES.md), a person with zero
 * cards closed still getting a full 6-8 card deck with a populated "quiet" arc, and
 * wrapped:build being safe to run twice in the same month.
 */
class WrappedTest extends TestCase
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

    private function person(string $name, string $role = 'employee'): Employee
    {
        $user = User::create(['name' => $name, 'email' => strtolower(preg_replace('/\W+/', '', $name)).uniqid().'@example.com', 'password' => Hash::make('password')]);
        $user->tenants()->attach($this->tenant()->id, ['role' => $role]);

        return Employee::create(['tenant_id' => $this->tenant()->id, 'user_id' => $user->id, 'name' => $name, 'status' => 'active', 'workload' => 'green']);
    }

    private function actingInTenantAs(Employee $employee): static
    {
        $this->actingAs($employee->user)->withSession(['current_tenant' => $this->tenant()->id]);

        return $this;
    }

    #[Test]
    public function test_cross_tenant_share_and_react_are_404_not_403(): void
    {
        Carbon::setTestNow('2026-10-01 08:00:00');
        $owner = $this->person('Owner Person');
        Artisan::call('wrapped:build');
        $story = WrappedStory::where('employee_id', $owner->id)->first();
        $this->assertNotNull($story);

        // Fetch the arc now, before any cross-tenant HTTP call below: ResolveTenant
        // middleware sets the CurrentTenant singleton for the stranger's session on each
        // request, and (being a singleton, not reset between requests in-process) it stays
        // pointed at the OTHER tenant afterward — an Eloquent query for "acme"'s own arc
        // issued after those requests would pick up the leftover scope and wrongly find
        // nothing. Reading it first sidesteps that in-process-only artifact.
        $arc = WrappedArc::where('tenant_id', $this->tenant()->id)->first();
        $this->assertNotNull($arc);

        $other = Tenant::create(['slug' => 'other-co', 'name' => 'Other Co', 'initials' => 'OC']);
        $strangerUser = User::create(['name' => 'Stranger', 'email' => 'stranger-wrapped@example.com', 'password' => Hash::make('password')]);
        $strangerUser->tenants()->attach($other->id, ['role' => 'employee']);
        Employee::create(['tenant_id' => $other->id, 'user_id' => $strangerUser->id, 'name' => 'Stranger', 'status' => 'active', 'workload' => 'green']);

        $asStranger = fn () => $this->actingAs($strangerUser)->withSession(['current_tenant' => $other->id]);

        $asStranger()->post("/app/wrapped/{$story->id}/share")->assertNotFound();
        $asStranger()->post("/app/wrapped/{$story->id}/unshare")->assertNotFound();
        $asStranger()->postJson("/app/wrapped/{$story->id}/react", ['reaction' => 'respect'])->assertNotFound();
        $asStranger()->post("/app/wrapped/arcs/{$arc->id}/retire")->assertNotFound();
    }

    #[Test]
    public function test_a_person_with_zero_cards_closed_still_gets_a_full_deck_and_a_quiet_arc(): void
    {
        Carbon::setTestNow('2026-10-01 08:00:00');
        $quiet = $this->person('Quiet Person');

        Artisan::call('wrapped:build');

        $story = WrappedStory::where('employee_id', $quiet->id)->first();
        $this->assertNotNull($story);
        $this->assertSame(0, $story->cards['cards_closed']);
        $this->assertSame('', $story->cards['best_day']);
        $this->assertNotNull($story->arc_title);
        $this->assertContains($story->arc_title, WrappedArc::where('rule', 'quiet')->where('active', true)->pluck('title')->all());

        $page = $this->actingInTenantAs($quiet)->get('/app/wrapped')->assertOk();
        $html = $page->getContent();
        $page->assertSee('data-wrapped="'.$story->id.'"', false);
        $cards = substr_count($html, 'data-wrapped-card="');
        $this->assertGreaterThanOrEqual(6, $cards);
        $this->assertLessThanOrEqual(8, $cards);
    }

    #[Test]
    public function test_best_day_is_dropped_when_the_frozen_closed_count_is_zero(): void
    {
        // QA (S28 grade): live cards exist but no award snapshot row does, so the frozen
        // count is 0. The deck must not say "N of your 0 cards".
        $person = $this->person('Snapshotless Person');
        Carbon::setTestNow('2026-09-10 09:00:00');
        $card = WorkItem::create(['tenant_id' => $this->tenant()->id, 'employee_id' => $person->id, 'title' => 'Closed without a snapshot', 'status' => 'todo']);
        $this->actingInTenantAs($person)->postJson("/app/board/{$card->id}/move", ['status' => 'done'])->assertOk();

        Carbon::setTestNow('2026-10-01 08:00:00');
        Artisan::call('wrapped:build');

        $story = WrappedStory::where('employee_id', $person->id)->first();
        $this->assertSame(0, $story->cards['cards_closed']);
        $this->assertSame('', $story->cards['best_day']);
        $this->assertSame(0, $story->cards['best_day_count']);
        $this->actingInTenantAs($person)->get('/app/wrapped')->assertOk()->assertDontSee('of your 0 cards');
    }

    #[Test]
    public function test_wrapped_build_run_twice_in_the_same_month_adds_no_rows(): void
    {
        Carbon::setTestNow('2026-10-01 08:00:00');
        $this->person('Employee One');
        $this->person('Employee Two');

        Artisan::call('wrapped:build');
        $count = DB::table('wrapped_stories')->count();
        $this->assertGreaterThan(0, $count);

        Artisan::call('wrapped:build');
        $this->assertSame($count, DB::table('wrapped_stories')->count(), 'a second run in the same month must add nothing');

        $arcCount = DB::table('wrapped_arcs')->count();
        $this->assertGreaterThanOrEqual(30, $arcCount);
        Artisan::call('wrapped:build');
        $this->assertSame($arcCount, DB::table('wrapped_arcs')->count(), 'arcs are seeded once, never re-seeded');
    }
}
