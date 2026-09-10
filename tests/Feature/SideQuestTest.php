<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Acceptance\AlwaysChecks;
use Tests\TestCase;

/**
 * S26 / CR-26: coverage the frozen CR26Test (tests/Acceptance) does not
 * exercise directly — cross-tenant 404 on every bound route (approve, photo,
 * react, on top of the retire/complete pairs CR26Test already covers), the
 * badge expiry boundary read straight from BuildsPeopleData's query, the live
 * cap counting only `live` (retired and suggested rows never count), and the
 * CR-30 reaction replace/remove semantics in isolation.
 */
class SideQuestTest extends TestCase
{
    use AlwaysChecks;
    use RefreshDatabase;

    private Employee $hidayah;

    private Employee $yati;

    private Employee $shazwan;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-09 10:00:00');
        Storage::fake('local');

        $this->hidayah = $this->person('Hidayah HR', 'hr');
        $this->yati = $this->person('Yati Dev');
        $this->shazwan = $this->person('Shazwan Dev');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private ?Tenant $other = null;

    private function otherTenant(): Tenant
    {
        return $this->other ??= Tenant::create(['slug' => 'other-co', 'name' => 'Other Co', 'initials' => 'OC']);
    }

    private function otherTenantQuest(string $status = 'live'): int
    {
        return (int) DB::table('side_quests')->insertGetId([
            'tenant_id' => $this->otherTenant()->id, 'title' => 'Foreign quest', 'status' => $status,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function otherTenantPost(int $questId): int
    {
        return (int) DB::table('side_quest_posts')->insertGetId([
            'tenant_id' => DB::table('side_quests')->where('id', $questId)->value('tenant_id'),
            'quest_id' => $questId, 'employee_id' => $this->yati->id, 'note' => 'Foreign note',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    #[Test]
    public function test_every_bound_route_404s_on_another_tenants_quest_or_post(): void
    {
        $otherQuest = $this->otherTenantQuest('suggested');
        $otherPost = $this->otherTenantPost($this->otherTenantQuest());

        $this->actingInTenantAs($this->hidayah)->post("/app/side-quests/{$otherQuest}/approve")->assertNotFound();
        $this->actingInTenantAs($this->yati)->get("/app/side-quests/posts/{$otherPost}/photo")->assertNotFound();
        $this->actingInTenantAs($this->yati)->postJson("/app/side-quests/posts/{$otherPost}/react", ['reaction' => 'respect'])->assertNotFound();
        $this->assertSame(0, DB::table('side_quest_reactions')->count());
    }

    #[Test]
    public function test_badge_gone_exactly_at_expiry_never_before(): void
    {
        $this->actingInTenantAs($this->hidayah)->post('/app/side-quests', ['title' => 'Share one useful AI prompt'])->assertSessionHasNoErrors();
        $quest = DB::table('side_quests')->where('title', 'Share one useful AI prompt')->first();

        $this->actingInTenantAs($this->yati)->post("/app/side-quests/{$quest->id}/complete", ['note' => 'Done.'])->assertRedirect();

        // One second before expiry: still on the profile.
        Carbon::setTestNow('2026-10-09 09:59:59');
        $this->actingInTenantAs($this->yati)->get('/app/profile')->assertOk()
            ->assertSee('data-quest-badge="'.$quest->id.'"', false);

        // The exact expiry instant: gone, row kept.
        Carbon::setTestNow('2026-10-09 10:00:00');
        $this->actingInTenantAs($this->yati)->get('/app/profile')->assertOk()
            ->assertDontSee('data-quest-badge=', false);
        $this->assertSame(1, DB::table('side_quest_badges')->count());
    }

    #[Test]
    public function test_live_cap_counts_only_live_quests(): void
    {
        // Two retired and one suggested row sitting around must not count against the cap.
        DB::table('side_quests')->insert([
            ['tenant_id' => $this->tenant()->id, 'title' => 'Old retired A', 'status' => 'retired', 'created_at' => now(), 'updated_at' => now()],
            ['tenant_id' => $this->tenant()->id, 'title' => 'Old retired B', 'status' => 'retired', 'created_at' => now(), 'updated_at' => now()],
            ['tenant_id' => $this->tenant()->id, 'title' => 'Pending suggestion', 'status' => 'suggested', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->actingInTenantAs($this->hidayah)->post('/app/side-quests', ['title' => 'Live one'])->assertSessionHasNoErrors();
        $this->actingInTenantAs($this->hidayah)->post('/app/side-quests', ['title' => 'Live two'])->assertSessionHasNoErrors();
        $this->assertSame(2, DB::table('side_quests')->where('status', 'live')->count());

        // A third is still fine — the cap only ever saw the 2 live rows, never the 3 others.
        $this->actingInTenantAs($this->hidayah)->post('/app/side-quests', ['title' => 'Live three'])->assertSessionHasNoErrors();
        $this->assertSame(3, DB::table('side_quests')->where('status', 'live')->count());
        $this->assertSame(6, DB::table('side_quests')->count());

        // The 4th is refused purely on the live count, unaffected by the 2 retired + 1 suggested rows.
        $this->actingInTenantAs($this->hidayah)->post('/app/side-quests', ['title' => 'Live four'])->assertSessionHasErrors('title');
        $this->assertSame(3, DB::table('side_quests')->where('status', 'live')->count());
    }

    #[Test]
    public function test_reaction_replace_and_remove_leave_exactly_one_row_per_person(): void
    {
        $this->actingInTenantAs($this->hidayah)->post('/app/side-quests', ['title' => 'Share one useful AI prompt'])->assertSessionHasNoErrors();
        $quest = DB::table('side_quests')->where('title', 'Share one useful AI prompt')->first();
        $this->actingInTenantAs($this->yati)->post("/app/side-quests/{$quest->id}/complete", [
            'photo' => UploadedFile::fake()->image('desk.jpg'),
        ])->assertRedirect();
        $post = DB::table('side_quest_posts')->where('quest_id', $quest->id)->first();

        // First react: one row.
        $this->actingInTenantAs($this->shazwan)->postJson("/app/side-quests/posts/{$post->id}/react", ['reaction' => 'power'])->assertOk();
        $this->assertSame(['power'], DB::table('side_quest_reactions')->where('post_id', $post->id)->pluck('reaction')->all());

        // Replace: still exactly one row, now the new key.
        $this->actingInTenantAs($this->shazwan)->postJson("/app/side-quests/posts/{$post->id}/react", ['reaction' => 'legend'])->assertOk();
        $rows = DB::table('side_quest_reactions')->where('post_id', $post->id)->get();
        $this->assertCount(1, $rows);
        $this->assertSame('legend', $rows->first()->reaction);

        // Same key again: removed, zero rows left for this person.
        $this->actingInTenantAs($this->shazwan)->postJson("/app/side-quests/posts/{$post->id}/react", ['reaction' => 'legend'])->assertOk();
        $this->assertSame(0, DB::table('side_quest_reactions')->where('post_id', $post->id)->where('employee_id', $this->shazwan->id)->count());

        // Two different people reacting with the same key both land, independently of each other.
        $this->actingInTenantAs($this->shazwan)->postJson("/app/side-quests/posts/{$post->id}/react", ['reaction' => 'respect'])->assertOk();
        $this->actingInTenantAs($this->hidayah)->postJson("/app/side-quests/posts/{$post->id}/react", ['reaction' => 'respect'])->assertOk();
        $this->assertSame(2, DB::table('side_quest_reactions')->where('post_id', $post->id)->where('reaction', 'respect')->count());
    }
}
