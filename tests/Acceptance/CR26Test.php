<?php

namespace Tests\Acceptance;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Acceptance for docs/specs/CR-26.md (session S26, Side Quests). The spec's single
 * Acceptance sentence is split into items 1 to 3 (quests live in The Playground, Yati
 * completes one by posting and it shows in the feed, the badge sits on her profile for 30
 * days); the Rules paragraph is item 4 (self-declared with a photo or one-liner, reactions,
 * HR curates with 2 to 3 live, staff suggest, badges never count anywhere); Keep it plain
 * (culture-pack preamble) is item 5.
 *
 * Shapes this file fixes for the generator (recorded in docs/build/OPEN.md):
 * - screen `side-quests` in The Playground, `GET /app/side-quests`: every live quest as
 *   `[data-quest="<id>"]` with its title, and under it the feed, newest first, one
 *   `[data-quest-post="<post id>"]` per completion with the author's name, the quest
 *   title, the one-liner and, when a photo was posted, one `<img>` served by
 *   `GET /app/side-quests/posts/{post}/photo`. Each feed post carries a CR-30 picker
 *   (`data-reaction-pick`) and tallies (`data-reaction-count`).
 * - tables: `side_quests` (tenant_id, title, blurb nullable, status `live`|`retired`|
 *   `suggested`, suggested_by employee id nullable, created_by employee id nullable,
 *   timestamps); `side_quest_posts` (tenant_id, quest_id, employee_id, note nullable,
 *   photo_path nullable, timestamps); `side_quest_badges` (tenant_id, employee_id,
 *   quest_id, post_id, earned_at, expires_at); `side_quest_reactions` (post_id,
 *   employee_id, reaction, one row per person per post).
 * - curation (`hr`, `director` only; `employee`/`manager` 403): `POST /app/side-quests
 *   {title, blurb?}` → 302, status `live`; `POST /app/side-quests/{quest}/retire` → 302,
 *   status `retired`; `POST /app/side-quests/{quest}/approve` turns a `suggested` quest
 *   `live`. At most 3 live quests at a time: a 4th publish or approve → 422 (session
 *   error on `title`), nothing written. Anyone signed in suggests with `POST
 *   /app/side-quests/suggest {title}` → 302, a `suggested` row (never shown as a quest
 *   on the screen, HR sees it in a suggestions list `[data-quest-suggestion="<id>"]`).
 * - completing: `POST /app/side-quests/{quest}/complete` (multipart) `{note?, photo?}`
 *   → 302; at least one of `note` (max 280) or `photo` (image) is required, neither →
 *   422; a quest that is not `live` → 422; a second completion of the same quest by the
 *   same person → 422. Writes the post, a badge with `earned_at = now()` and `expires_at
 *   = now() + 30 days`, and audit `side_quest.completed`, target `side_quest_post:<id>`.
 *   Publishing, retiring, approving and suggesting are audited as `side_quest.published`,
 *   `side_quest.retired`, `side_quest.approved`, `side_quest.suggested`, target
 *   `side_quest:<id>`.
 * - badge: `/app/profile` (own) and `/app/profile?emp=<id>` (anyone in the tenant) render
 *   `[data-quest-badge="<quest id>"]` with the quest title while `now() < expires_at`;
 *   from `expires_at` on it is gone. Badges never count: `award_results` gets no row,
 *   nothing on `/app/wins` or the awards band mentions them, and no route other than the
 *   screen, the photo and the POSTs above contains "side-quest".
 * - reactions: `POST /app/side-quests/posts/{post}/react {reaction}` with CR-30
 *   semantics (unknown key 422, same key again removes, a different key replaces, one
 *   per person).
 * - another tenant's quest or post is 404 on every bound route.
 * - Keep it plain: the screen and the badge keep their text, with no `uj-sq-art`, no
 *   `uj-db-confetti`, no `<canvas`, no `<audio`, and the cheeky kicker
 *   "NOT A KPI. NEVER WILL BE." is replaced by "Optional challenges".
 */
class CR26Test extends TestCase
{
    use AlwaysChecks;
    use RefreshDatabase;

    private Employee $hidayah;

    private Employee $shahril;

    private Employee $kussairi;

    private Employee $yati;

    private Employee $shazwan;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-09 10:00:00');
        Storage::fake('local');

        $this->hidayah = $this->person('Hidayah HR', 'hr');
        $this->shahril = $this->person('Shahril Director', 'director');
        $this->kussairi = $this->person('Kussairi PM', 'manager');
        $this->yati = $this->person('Yati Dev');
        $this->shazwan = $this->person('Shazwan Dev');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    #[Test]
    public function test_acceptance_1_two_to_three_quests_live_in_the_playground(): void
    {
        // Only HR/admin publish.
        $this->actingInTenantAs($this->kussairi)->post('/app/side-quests', ['title' => 'Share one useful AI prompt'])->assertStatus(403);
        $this->actingInTenantAs($this->yati)->post('/app/side-quests', ['title' => 'Share one useful AI prompt'])->assertStatus(403);
        $this->assertSame(0, DB::table('side_quests')->count());

        $ai = $this->publish('Share one useful AI prompt', 'Paste the prompt and what it saved you.');
        $lunch = $this->publish('Have lunch with someone outside your project');
        $short = $this->publish('Teach a colleague one shortcut');
        $this->assertSame('live', $ai->status);
        $this->assertSame($this->hidayah->id, (int) $ai->created_by);
        $this->assertSame(3, AuditLog::where('action', 'side_quest.published')->count());
        $this->assertSame("side_quest:{$ai->id}", AuditLog::where('action', 'side_quest.published')->orderBy('id')->first()->target);

        // A fourth live quest is refused, 2 to 3 at a time.
        $this->actingInTenantAs($this->hidayah)->post('/app/side-quests', ['title' => 'Post your workstation view'])->assertSessionHasErrors('title');
        $this->assertSame(3, DB::table('side_quests')->where('status', 'live')->count());

        foreach ([$this->yati, $this->shahril] as $viewer) {
            $page = $this->actingInTenantAs($viewer)->get('/app/side-quests')->assertOk();
            foreach ([$ai, $lunch, $short] as $quest) {
                $page->assertSee('data-quest="'.$quest->id.'"', false);
                $page->assertSee($quest->title);
            }
            $page->assertSee('Paste the prompt and what it saved you.');
        }

        // Retiring one frees a slot; the retired quest leaves the screen.
        $this->actingInTenantAs($this->kussairi)->post("/app/side-quests/{$short->id}/retire")->assertStatus(403);
        $this->actingInTenantAs($this->hidayah)->post("/app/side-quests/{$short->id}/retire")->assertRedirect();
        $this->assertSame('retired', DB::table('side_quests')->where('id', $short->id)->value('status'));
        $this->assertSame(1, AuditLog::where('action', 'side_quest.retired')->where('target', "side_quest:{$short->id}")->count());
        $this->actingInTenantAs($this->yati)->get('/app/side-quests')->assertOk()->assertDontSee('data-quest="'.$short->id.'"', false);
        $this->actingInTenantAs($this->shahril)->post('/app/side-quests', ['title' => 'Post your workstation view'])->assertSessionHasNoErrors();
        $this->assertSame(3, DB::table('side_quests')->where('status', 'live')->count());
    }

    #[Test]
    public function test_acceptance_2_yati_completes_a_quest_by_posting_and_it_shows_in_the_feed(): void
    {
        $ai = $this->publish('Share one useful AI prompt');
        $this->publish('Have lunch with someone outside your project');

        $this->actingInTenantAs($this->yati)
            ->post("/app/side-quests/{$ai->id}/complete", [
                'note' => 'Prompt: "Explain this stack trace like I am new to Laravel." Saved me an hour.',
                'photo' => UploadedFile::fake()->image('prompt.png', 400, 300),
            ])->assertRedirect();

        $post = DB::table('side_quest_posts')->where('quest_id', $ai->id)->first();
        $this->assertNotNull($post, 'side_quest_posts row missing');
        $this->assertSame($this->yati->id, (int) $post->employee_id);
        $this->assertStringContainsString('Saved me an hour', $post->note);
        $this->assertNotEmpty($post->photo_path);
        Storage::disk('local')->assertExists($post->photo_path);

        $audit = AuditLog::where('action', 'side_quest.completed')->sole();
        $this->assertSame($this->yati->user_id, $audit->user_id);
        $this->assertSame("side_quest_post:{$post->id}", $audit->target);

        // Newest first: Shazwan completes the same quest later and sits above Yati.
        Carbon::setTestNow('2026-09-09 11:00:00');
        $this->actingInTenantAs($this->shazwan)->post("/app/side-quests/{$ai->id}/complete", ['note' => 'Prompt: summarise this MR in three bullets.'])->assertRedirect();
        $later = DB::table('side_quest_posts')->where('employee_id', $this->shazwan->id)->first();

        foreach ([$this->kussairi, $this->shahril, $this->yati] as $viewer) {
            $page = $this->actingInTenantAs($viewer)->get('/app/side-quests')->assertOk();
            $page->assertSee('data-quest-post="'.$post->id.'"', false);
            $page->assertSee('Yati Dev');
            $page->assertSee('Saved me an hour');
            $page->assertSee("/app/side-quests/posts/{$post->id}/photo", false);
            $page->assertSee('data-reaction-pick', false);
            $page->assertSeeInOrder(['data-quest-post="'.$later->id.'"', 'data-quest-post="'.$post->id.'"'], false);
        }
        $this->actingInTenantAs($this->kussairi)->get("/app/side-quests/posts/{$post->id}/photo")->assertOk();
        // A post without a photo has no image and no photo link.
        $this->assertNull($later->photo_path);
        $this->actingInTenantAs($this->kussairi)->get("/app/side-quests/posts/{$later->id}/photo")->assertNotFound();
    }

    #[Test]
    public function test_acceptance_3_badge_on_her_profile_for_30_days(): void
    {
        $ai = $this->publish('Share one useful AI prompt');
        $this->publish('Have lunch with someone outside your project');

        $this->actingInTenantAs($this->yati)->post("/app/side-quests/{$ai->id}/complete", ['note' => 'Prompt shared.'])->assertRedirect();
        $badge = DB::table('side_quest_badges')->where('employee_id', $this->yati->id)->first();
        $this->assertNotNull($badge, 'side_quest_badges row missing');
        $this->assertSame($ai->id, (int) $badge->quest_id);
        $this->assertSame('2026-09-09 10:00:00', substr((string) $badge->earned_at, 0, 19));
        $this->assertSame('2026-10-09 10:00:00', substr((string) $badge->expires_at, 0, 19));

        // Own profile and anyone viewing her.
        $this->actingInTenantAs($this->yati)->get('/app/profile')->assertOk()
            ->assertSee('data-quest-badge="'.$ai->id.'"', false)->assertSee('Share one useful AI prompt');
        $this->actingInTenantAs($this->kussairi)->get('/app/profile?emp='.$this->yati->id)->assertOk()
            ->assertSee('data-quest-badge="'.$ai->id.'"', false);
        // Not on someone who did not complete it.
        $this->actingInTenantAs($this->shazwan)->get('/app/profile')->assertOk()->assertDontSee('data-quest-badge=', false);

        // Day 29: still there. Day 30: gone, row kept.
        Carbon::setTestNow('2026-10-08 10:00:00');
        $this->actingInTenantAs($this->yati)->get('/app/profile')->assertOk()->assertSee('data-quest-badge="'.$ai->id.'"', false);
        Carbon::setTestNow('2026-10-09 10:00:00');
        $this->actingInTenantAs($this->yati)->get('/app/profile')->assertOk()->assertDontSee('data-quest-badge=', false);
        $this->actingInTenantAs($this->kussairi)->get('/app/profile?emp='.$this->yati->id)->assertOk()->assertDontSee('data-quest-badge=', false);
        $this->assertSame(1, DB::table('side_quest_badges')->count());
    }

    #[Test]
    public function test_acceptance_4_rules_self_declared_reactions_suggestions_and_badges_never_count(): void
    {
        $ai = $this->publish('Share one useful AI prompt');
        $lunch = $this->publish('Have lunch with someone outside your project');

        // Self-declared, but with something: a note or a photo.
        $this->actingInTenantAs($this->yati)->post("/app/side-quests/{$ai->id}/complete", [])->assertSessionHasErrors();
        $this->actingInTenantAs($this->yati)->post("/app/side-quests/{$ai->id}/complete", ['note' => str_repeat('x', 281)])->assertSessionHasErrors('note');
        $this->actingInTenantAs($this->yati)->post("/app/side-quests/{$ai->id}/complete", ['photo' => UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf')])->assertSessionHasErrors('photo');
        $this->assertSame(0, DB::table('side_quest_posts')->count());

        $this->actingInTenantAs($this->yati)->post("/app/side-quests/{$ai->id}/complete", ['photo' => UploadedFile::fake()->image('desk.jpg', 300, 200)])->assertSessionHasNoErrors();
        // Once per quest per person; another quest is fine.
        $this->actingInTenantAs($this->yati)->post("/app/side-quests/{$ai->id}/complete", ['note' => 'Again'])->assertSessionHasErrors();
        $this->actingInTenantAs($this->yati)->post("/app/side-quests/{$lunch->id}/complete", ['note' => 'Lunch with the admin team'])->assertSessionHasNoErrors();
        $this->assertSame(2, DB::table('side_quest_posts')->where('employee_id', $this->yati->id)->count());
        $this->assertSame(2, DB::table('side_quest_badges')->where('employee_id', $this->yati->id)->count());

        // A retired quest cannot be completed any more; its old posts stay in the feed.
        $this->actingInTenantAs($this->hidayah)->post("/app/side-quests/{$lunch->id}/retire")->assertRedirect();
        $this->actingInTenantAs($this->shazwan)->post("/app/side-quests/{$lunch->id}/complete", ['note' => 'Too late'])->assertSessionHasErrors();
        $lunchPost = DB::table('side_quest_posts')->where('quest_id', $lunch->id)->first();
        $this->actingInTenantAs($this->shazwan)->get('/app/side-quests')->assertOk()->assertSee('data-quest-post="'.$lunchPost->id.'"', false);

        // Others react, CR-30 semantics.
        $post = DB::table('side_quest_posts')->where('quest_id', $ai->id)->first();
        $this->actingInTenantAs($this->shazwan)->postJson("/app/side-quests/posts/{$post->id}/react", ['reaction' => 'thumbs'])->assertStatus(422);
        $this->actingInTenantAs($this->shazwan)->postJson("/app/side-quests/posts/{$post->id}/react", ['reaction' => 'respect'])->assertOk();
        $this->actingInTenantAs($this->kussairi)->postJson("/app/side-quests/posts/{$post->id}/react", ['reaction' => 'respect'])->assertOk();
        $this->assertSame(2, DB::table('side_quest_reactions')->where('post_id', $post->id)->where('reaction', 'respect')->count());
        $html = $this->actingInTenantAs($this->yati)->get('/app/side-quests')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/data-reaction-count="respect"[^>]*>[^<]*2/', $html);
        $this->actingInTenantAs($this->shazwan)->postJson("/app/side-quests/posts/{$post->id}/react", ['reaction' => 'respect'])->assertOk();
        $this->assertSame(1, DB::table('side_quest_reactions')->where('post_id', $post->id)->count());
        $this->actingInTenantAs($this->kussairi)->postJson("/app/side-quests/posts/{$post->id}/react", ['reaction' => 'legend'])->assertOk();
        $this->assertSame(1, DB::table('side_quest_reactions')->where('post_id', $post->id)->count());
        $this->assertSame('legend', DB::table('side_quest_reactions')->where('post_id', $post->id)->value('reaction'));

        // Staff suggest; HR approves into the live list (cap still applies).
        $this->actingInTenantAs($this->shazwan)->post('/app/side-quests/suggest', ['title' => 'Recommend one book, show or cafe'])->assertRedirect();
        $suggested = DB::table('side_quests')->where('status', 'suggested')->first();
        $this->assertNotNull($suggested);
        $this->assertSame($this->shazwan->id, (int) $suggested->suggested_by);
        $this->assertSame(1, AuditLog::where('action', 'side_quest.suggested')->where('target', "side_quest:{$suggested->id}")->count());
        $this->actingInTenantAs($this->yati)->get('/app/side-quests')->assertOk()
            ->assertDontSee('data-quest="'.$suggested->id.'"', false)->assertDontSee('data-quest-suggestion', false);
        $this->actingInTenantAs($this->hidayah)->get('/app/side-quests')->assertOk()->assertSee('data-quest-suggestion="'.$suggested->id.'"', false);
        $this->actingInTenantAs($this->shazwan)->post("/app/side-quests/{$suggested->id}/approve")->assertStatus(403);
        $this->actingInTenantAs($this->hidayah)->post("/app/side-quests/{$suggested->id}/approve")->assertRedirect();
        $this->assertSame('live', DB::table('side_quests')->where('id', $suggested->id)->value('status'));
        $this->assertSame(1, AuditLog::where('action', 'side_quest.approved')->where('target', "side_quest:{$suggested->id}")->count());
        // Fill to 3, then a further approve is refused.
        $this->publish('Ask one person what they are building');
        $this->actingInTenantAs($this->yati)->post('/app/side-quests/suggest', ['title' => 'Post your workstation view'])->assertRedirect();
        $fourth = DB::table('side_quests')->where('status', 'suggested')->first();
        $this->actingInTenantAs($this->hidayah)->post("/app/side-quests/{$fourth->id}/approve")->assertSessionHasErrors('title');
        $this->assertSame('suggested', DB::table('side_quests')->where('id', $fourth->id)->value('status'));

        // Badges never count anywhere.
        $this->assertSame(0, DB::table('award_results')->count());
        $this->assertFalse(Schema::hasColumn('award_results', 'side_quest_id'));
        $this->actingInTenantAs($this->shahril)->get('/app/wins')->assertOk()->assertDontSee('data-quest-badge', false)->assertDontSee('Side Quest', false);
        $routes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($r) => str_contains($r->uri(), 'side-quest'))
            ->map(fn ($r) => implode('|', $r->methods()).' '.$r->uri());
        foreach ($routes as $route) {
            $this->assertStringNotContainsString('export', $route);
            $this->assertStringNotContainsString('report', $route);
            if (str_starts_with($route, 'GET')) {
                $this->assertMatchesRegularExpression('#^GET\|HEAD app/side-quests(/posts/\{post\}/photo)?$#', $route, "unexpected read surface: {$route}");
            }
        }

        // Another tenant's quest and post are unreachable.
        $other = Tenant::create(['slug' => 'other', 'name' => 'Other', 'initials' => 'OT']);
        $otherQuest = DB::table('side_quests')->insertGetId(['tenant_id' => $other->id, 'title' => 'Foreign quest', 'status' => 'live', 'created_at' => now(), 'updated_at' => now()]);
        $this->actingInTenantAs($this->yati)->post("/app/side-quests/{$otherQuest}/complete", ['note' => 'x'])->assertNotFound();
        $this->actingInTenantAs($this->hidayah)->post("/app/side-quests/{$otherQuest}/retire")->assertNotFound();
        $this->actingInTenantAs($this->yati)->get('/app/side-quests')->assertOk()->assertDontSee('Foreign quest');
    }

    #[Test]
    public function test_acceptance_5_keep_it_plain(): void
    {
        $ai = $this->publish('Share one useful AI prompt');
        $this->publish('Have lunch with someone outside your project');
        $this->actingInTenantAs($this->yati)->post("/app/side-quests/{$ai->id}/complete", ['note' => 'Prompt shared.'])->assertRedirect();

        $loud = $this->actingInTenantAs($this->yati)->get('/app/side-quests')->assertOk();
        $loud->assertSee('NOT A KPI', false);

        $this->actingInTenantAs($this->yati)->postJson('/app/dashboard/prefs', ['plain' => true])->assertOk();

        $plain = $this->actingInTenantAs($this->yati)->get('/app/side-quests')->assertOk();
        $plain->assertSee('data-quest="'.$ai->id.'"', false);
        $plain->assertSee('Share one useful AI prompt');
        $plain->assertSee('Prompt shared.');
        $plain->assertSee('Optional challenges');
        $plain->assertDontSee('NOT A KPI', false);
        foreach (['uj-sq-art', 'uj-db-confetti', '<canvas', '<audio'] as $needle) {
            $plain->assertDontSee($needle, false);
        }
        $profile = $this->actingInTenantAs($this->yati)->get('/app/profile')->assertOk();
        $profile->assertSee('data-quest-badge="'.$ai->id.'"', false);
        $profile->assertDontSee('uj-sq-art', false);
    }

    #[Test]
    public function test_always_checks_from_s26(): void
    {
        Carbon::setTestNow();
        $this->assertDueDateLocked();
        $this->assertAuditLogImmutable();
        $this->assertDashboardUnchanged();
        $this->assertKeepItPlainHonoured();
    }

    // ── helpers ──

    private function publish(string $title, ?string $blurb = null): object
    {
        $this->actingInTenantAs($this->hidayah)->post('/app/side-quests', array_filter(['title' => $title, 'blurb' => $blurb]))->assertSessionHasNoErrors();
        $quest = DB::table('side_quests')->where('title', $title)->orderByDesc('id')->first();
        $this->assertNotNull($quest, "side_quests row for '{$title}' missing");

        return $quest;
    }
}
