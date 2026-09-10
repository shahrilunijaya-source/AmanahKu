<?php

namespace Tests\Acceptance;

use App\Models\Employee;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Acceptance for docs/specs/CR-24.md (session S22, Big Deal Alert). The spec's one
 * Acceptance sentence is split into four numbered items in the order it lists them,
 * then the Rules paragraph is item 5 and Keep it plain (culture-pack preamble) is item 6.
 *
 * Shapes this file fixes for the generator (recorded in docs/build/OPEN.md):
 * - a Big Deal is raised with `POST /app/big-deals` (multipart) by `manager`, `hr`,
 *   `management` or `director`; `employee` gets 403. Fields: `type` (one of `go_live`,
 *   `tender_won`, `claim_received`, `uat_completed`, `milestone`, `client_compliment`,
 *   `other`; anything else 422), `title`, `story` ("What it took"), optional `project_id`
 *   or `work_item_id` (the card it was raised from), optional `track_ref` (free text, the
 *   Track milestone / tender record; no port call), `team` = list of employee ids,
 *   `photos[]` = up to three images, and for `client_compliment` a required `source` file
 *   plus optional `client_contact` text and `names_approved` (0/1).
 * - table `big_deals` (tenant_id, type, title, story, raised_by employee id,
 *   project_id, work_item_id, track_ref, client_contact, names_approved, source_path,
 *   published_at, timestamps); `big_deal_photos` (big_deal_id, path); `big_deal_members`
 *   (big_deal_id, employee_id); `big_deal_reactions` (big_deal_id, employee_id, reaction
 *   key, one row per person). Raising writes an audit row `big_deal.raised`, target
 *   `big_deal:<id>`.
 * - the banner is one more moment in the moments band (contracts/dashboard-slots.md):
 *   `<section class="uj-db-band uj-db-moment" data-kind="big-deal" data-big-deal="<id>">`,
 *   kicker "BIG DEAL ALERT", the title, the story, one `[data-big-deal-member="<employee id>"]`
 *   avatar per team member, one `<img>` per photo served by
 *   `GET /app/big-deals/{deal}/photos/{photo}`, a CR-30 picker (`data-reaction-pick`) and
 *   tallies (`data-reaction-count`). It shows on every dashboard in the tenant while
 *   `now < published_at + 3 days`.
 * - reactions: `POST /app/big-deals/{deal}/react` `{reaction}` with CR-30 semantics
 *   (unknown key 422, same key again removes, a different key replaces, one per person).
 * - after 3 days the deal leaves the dashboard and is listed on the Wins page,
 *   `GET /app/wins` (The Playground), as `[data-win="<id>"]` with title and story.
 * - Keep it plain: the section still renders, text only: no `uj-db-confetti`, no `uj-db-art`.
 */
class CR24Test extends TestCase
{
    use AlwaysChecks;
    use RefreshDatabase;

    private const TYPES = ['go_live', 'tender_won', 'claim_received', 'uat_completed', 'milestone', 'client_compliment', 'other'];

    private Employee $kussairi;

    private Employee $shazwan;

    private Employee $yati;

    private Project $ilpf;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Carbon::setTestNow('2026-09-09 10:00:00');

        $this->kussairi = $this->person('Kussairi PM', 'manager');
        $this->shazwan = $this->person('Shazwan Dev');
        $this->yati = $this->person('Yati Dev');
        $this->ilpf = Project::create([
            'tenant_id' => $this->tenant()->id, 'code' => 'ILPF', 'project_code' => 'ILPF-2026-01',
            'name' => 'iLPF', 'client' => 'Lembaga', 'status' => 'active',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    #[Test]
    public function test_acceptance_1_pm_marks_ilpf_uat_completed_as_big_deal_with_three_photos(): void
    {
        // A developer cannot raise one, from a card or otherwise.
        $this->actingInTenantAs($this->shazwan)
            ->post('/app/big-deals', $this->payload())
            ->assertStatus(403);
        $this->assertSame(0, DB::table('big_deals')->count());

        // The PM can, with three photos, from the project card.
        $this->actingInTenantAs($this->kussairi)
            ->post('/app/big-deals', $this->payload())
            ->assertSessionHasNoErrors();

        $deal = DB::table('big_deals')->first();
        $this->assertNotNull($deal, 'big_deals row missing');
        $this->assertSame('uat_completed', $deal->type);
        $this->assertSame('iLPF just completed UAT', $deal->title);
        $this->assertSame($this->kussairi->id, (int) $deal->raised_by);
        $this->assertSame($this->ilpf->id, (int) $deal->project_id);
        $this->assertSame('TRK-MS-42', $deal->track_ref);
        $this->assertNotNull($deal->published_at);

        $photos = DB::table('big_deal_photos')->where('big_deal_id', $deal->id)->get();
        $this->assertCount(3, $photos);
        foreach ($photos as $photo) {
            Storage::disk('local')->assertExists($photo->path);
            $this->actingInTenantAs($this->yati)->get("/app/big-deals/{$deal->id}/photos/{$photo->id}")->assertOk();
        }

        $members = DB::table('big_deal_members')->where('big_deal_id', $deal->id)->pluck('employee_id')->map(fn ($id) => (int) $id)->all();
        sort($members);
        $this->assertSame([$this->shazwan->id, $this->yati->id], $members);

        $this->assertDatabaseHas('audit_logs', [
            'tenant_id' => $this->tenant()->id,
            'action' => 'big_deal.raised',
            'target' => "big_deal:{$deal->id}",
            'user_id' => $this->kussairi->user_id,
        ]);

        // A director can raise one too; a fourth photo is refused.
        $director = $this->person('Shahril Director', 'director');
        $this->actingInTenantAs($director)
            ->post('/app/big-deals', $this->payload(['title' => 'Tender won', 'type' => 'tender_won', 'photos' => [
                UploadedFile::fake()->image('a.jpg', 300, 200), UploadedFile::fake()->image('b.jpg', 300, 200),
                UploadedFile::fake()->image('c.jpg', 300, 200), UploadedFile::fake()->image('d.jpg', 300, 200),
            ]]))
            ->assertSessionHasErrors();
        $this->actingInTenantAs($director)
            ->post('/app/big-deals', $this->payload(['title' => 'Tender won', 'type' => 'tender_won', 'photos' => []]))
            ->assertSessionHasNoErrors();
        $this->assertSame(2, DB::table('big_deals')->count());
    }

    #[Test]
    public function test_acceptance_2_banner_on_all_dashboards_with_team_avatars_photos_and_story(): void
    {
        $deal = $this->raise();

        foreach ([$this->kussairi, $this->shazwan, $this->yati, $this->person('Emysha Outsider')] as $viewer) {
            $page = $this->actingInTenantAs($viewer)->get('/app/dash')->assertOk();
            $html = $page->getContent();

            $page->assertSee('data-kind="big-deal"', false);
            $page->assertSee('data-big-deal="'.$deal->id.'"', false);
            $page->assertSee('BIG DEAL ALERT');
            $page->assertSee('iLPF just completed UAT');
            $page->assertSee('Everyone involved may now breathe again.');
            $page->assertSee('Three weeks of fixes, two all-nighters, one very patient client.');
            $page->assertSee('data-big-deal-member="'.$this->shazwan->id.'"', false);
            $page->assertSee('data-big-deal-member="'.$this->yati->id.'"', false);
            $this->assertSame(3, preg_match_all('#/app/big-deals/'.$deal->id.'/photos/\d+#', $html), "{$viewer->name} does not see three photos");

            // The banner sits in the moments band above the grid, the grid itself is untouched.
            $page->assertSeeInOrder(['data-kind="big-deal"', 'Current month summary', 'Daily clock log', 'Pending tasks'], false);
            $this->assertSame(0, substr_count($html, 'data-band="'), 'no other band may appear');
        }

        // Another tenant sees nothing.
        $other = Tenant::create(['slug' => 'other', 'name' => 'Other', 'initials' => 'OT']);
        $user = User::create(['name' => 'Stranger', 'email' => 'stranger@example.com', 'password' => bcrypt('password')]);
        $user->tenants()->attach($other->id, ['role' => 'employee']);
        Employee::create(['tenant_id' => $other->id, 'user_id' => $user->id, 'name' => 'Stranger', 'status' => 'active', 'workload' => 'green']);
        $this->actingAs($user)->withSession(['current_tenant' => $other->id])
            ->get('/app/dash')->assertOk()->assertDontSee('data-kind="big-deal"', false);
    }

    #[Test]
    public function test_acceptance_3_reactions_work_on_the_banner(): void
    {
        $deal = $this->raise();

        $page = $this->actingInTenantAs($this->yati)->get('/app/dash')->assertOk();
        $html = $this->bannerHtml($page, $deal->id);
        $this->assertStringContainsString('data-reaction-pick="power"', $html);
        $this->assertStringContainsString('data-reaction-pick="respect"', $html);
        $this->assertStringNotContainsString('data-reaction-pick="thumbs"', $html);

        // Unknown key refused.
        $this->actingInTenantAs($this->yati)->postJson("/app/big-deals/{$deal->id}/react", ['reaction' => 'thumbs'])->assertStatus(422);
        $this->actingInTenantAs($this->yati)->postJson("/app/big-deals/{$deal->id}/react", [])->assertStatus(422);

        // Yati reacts, Shazwan reacts, the tally shows two.
        $this->actingInTenantAs($this->yati)->postJson("/app/big-deals/{$deal->id}/react", ['reaction' => 'respect'])->assertOk();
        $this->actingInTenantAs($this->shazwan)->postJson("/app/big-deals/{$deal->id}/react", ['reaction' => 'respect'])->assertOk();
        $this->assertSame(2, DB::table('big_deal_reactions')->where('big_deal_id', $deal->id)->where('reaction', 'respect')->count());

        $html = $this->bannerHtml($this->actingInTenantAs($this->kussairi)->get('/app/dash')->assertOk(), $deal->id);
        $this->assertMatchesRegularExpression('/data-reaction-count="respect"[^>]*>[^<]*2/', $html, 'tally for respect should read 2');

        // Same key again removes, a different key replaces: still one row per person.
        $this->actingInTenantAs($this->yati)->postJson("/app/big-deals/{$deal->id}/react", ['reaction' => 'respect'])->assertOk();
        $this->assertSame(1, DB::table('big_deal_reactions')->where('big_deal_id', $deal->id)->count());
        $this->actingInTenantAs($this->shazwan)->postJson("/app/big-deals/{$deal->id}/react", ['reaction' => 'legend'])->assertOk();
        $rows = DB::table('big_deal_reactions')->where('big_deal_id', $deal->id)->get();
        $this->assertCount(1, $rows);
        $this->assertSame('legend', $rows[0]->reaction);
        $this->assertSame($this->shazwan->id, (int) $rows[0]->employee_id);

        // Reacting never writes a notification (CR-30).
        $this->assertSame(0, DB::table('app_notifications')->count());
    }

    #[Test]
    public function test_acceptance_4_after_three_days_it_leaves_the_dashboard_and_sits_on_the_wins_page(): void
    {
        $deal = $this->raise();

        // Day 3, still inside the window: on the dashboard.
        Carbon::setTestNow('2026-09-12 09:59:00');
        $this->actingInTenantAs($this->yati)->get('/app/dash')->assertOk()->assertSee('data-big-deal="'.$deal->id.'"', false);

        // Three full days later: gone from every dashboard, present on Wins.
        Carbon::setTestNow('2026-09-12 10:01:00');
        foreach ([$this->kussairi, $this->yati] as $viewer) {
            $this->actingInTenantAs($viewer)->get('/app/dash')->assertOk()->assertDontSee('data-kind="big-deal"', false);
        }

        $wins = $this->actingInTenantAs($this->yati)->get('/app/wins')->assertOk();
        $wins->assertSee('data-win="'.$deal->id.'"', false);
        $wins->assertSee('iLPF just completed UAT');
        $wins->assertSee('Three weeks of fixes, two all-nighters, one very patient client.');
        $wins->assertSee('data-big-deal-member="'.$this->shazwan->id.'"', false);

        // The Playground carries it in the left panel for everyone, and an employee may read it.
        $this->actingInTenantAs($this->shazwan)->get('/app/wins')->assertOk()->assertSee('data-win="'.$deal->id.'"', false);

        // A month on it is still there: Wins is an archive, not a window.
        Carbon::setTestNow('2026-10-12 10:00:00');
        $this->actingInTenantAs($this->yati)->get('/app/wins')->assertOk()->assertSee('data-win="'.$deal->id.'"', false);

        // The row itself was never deleted or rewritten.
        $this->assertDatabaseHas('big_deals', ['id' => $deal->id, 'title' => 'iLPF just completed UAT']);
    }

    #[Test]
    public function test_acceptance_5_rules_fixed_types_compliment_needs_its_source_and_client_names_stay_hidden(): void
    {
        // Types are fixed.
        $this->actingInTenantAs($this->kussairi)
            ->post('/app/big-deals', $this->payload(['type' => 'party']))
            ->assertSessionHasErrors('type');
        foreach (self::TYPES as $type) {
            if ($type === 'client_compliment') {
                continue;
            }
            $this->actingInTenantAs($this->kussairi)
                ->post('/app/big-deals', $this->payload(['type' => $type, 'title' => "Deal {$type}", 'photos' => []]))
                ->assertSessionHasNoErrors();
        }
        $this->assertSame(count(self::TYPES) - 1, DB::table('big_deals')->count());

        // A client compliment without its source is refused.
        $this->actingInTenantAs($this->kussairi)
            ->post('/app/big-deals', $this->payload([
                'type' => 'client_compliment', 'title' => 'Lembaga wrote in', 'photos' => [],
                'client_contact' => 'Puan Rahimah',
            ]))
            ->assertSessionHasErrors('source');

        // With the source, names stay hidden until approved.
        $this->actingInTenantAs($this->kussairi)
            ->post('/app/big-deals', $this->payload([
                'type' => 'client_compliment', 'title' => 'Lembaga wrote in', 'photos' => [],
                'story' => 'They said the team was a pleasure to work with.',
                'client_contact' => 'Puan Rahimah',
                'source' => UploadedFile::fake()->create('compliment.pdf', 40, 'application/pdf'),
            ]))
            ->assertSessionHasNoErrors();
        $compliment = DB::table('big_deals')->where('type', 'client_compliment')->first();
        $this->assertNotNull($compliment);
        $this->assertNotNull($compliment->source_path);
        Storage::disk('local')->assertExists($compliment->source_path);
        $this->assertSame(0, (int) $compliment->names_approved);

        $page = $this->actingInTenantAs($this->yati)->get('/app/dash')->assertOk();
        $page->assertSee('Lembaga wrote in');
        $page->assertDontSee('Puan Rahimah');

        // Approved names show.
        $this->actingInTenantAs($this->kussairi)
            ->post('/app/big-deals', $this->payload([
                'type' => 'client_compliment', 'title' => 'Lembaga wrote in again', 'photos' => [],
                'client_contact' => 'Encik Faizal', 'names_approved' => 1,
                'source' => UploadedFile::fake()->create('compliment2.pdf', 40, 'application/pdf'),
            ]))
            ->assertSessionHasNoErrors();
        $this->actingInTenantAs($this->yati)->get('/app/dash')->assertOk()->assertSee('Encik Faizal');

        // Raising from a T.A.A. card records the card.
        $card = $this->card($this->shazwan, ['title' => 'UAT sign-off', 'project_id' => $this->ilpf->id]);
        $this->actingInTenantAs($this->kussairi)
            ->post('/app/big-deals', $this->payload(['title' => 'From the card', 'work_item_id' => $card->id, 'project_id' => null, 'photos' => []]))
            ->assertSessionHasNoErrors();
        $this->assertDatabaseHas('big_deals', ['title' => 'From the card', 'work_item_id' => $card->id]);
    }

    #[Test]
    public function test_acceptance_6_keep_it_plain_gives_a_text_only_banner(): void
    {
        $deal = $this->raise();

        $loud = $this->actingInTenantAs($this->yati)->get('/app/dash')->assertOk();
        $loud->assertSee('data-big-deal="'.$deal->id.'"', false);
        $loudHtml = $this->bannerHtml($loud, $deal->id);
        $this->assertStringContainsString('uj-db-art', $loudHtml);

        $this->actingInTenantAs($this->yati)->postJson('/app/dashboard/prefs', ['plain' => true])->assertOk();

        $plain = $this->actingInTenantAs($this->yati)->get('/app/dash')->assertOk();
        $plain->assertSee('data-big-deal="'.$deal->id.'"', false);
        $plain->assertSee('iLPF just completed UAT');
        $plain->assertSee('Three weeks of fixes, two all-nighters, one very patient client.');
        $plainHtml = $this->bannerHtml($plain, $deal->id);
        $this->assertStringNotContainsString('uj-db-confetti', $plainHtml);
        $this->assertStringNotContainsString('uj-db-art', $plainHtml);
        $this->assertStringNotContainsString('<canvas', $plainHtml);
        $this->assertStringNotContainsString('<audio', $plainHtml);
    }

    #[Test]
    public function test_always_checks_from_s22(): void
    {
        Carbon::setTestNow();
        $this->assertDueDateLocked();
        $this->assertAuditLogImmutable();
        $this->assertDashboardUnchanged();
        $this->assertKeepItPlainHonoured();
    }

    // ── helpers ──

    /** @return array<string, mixed> */
    private function payload(array $over = []): array
    {
        return array_merge([
            'type' => 'uat_completed',
            'title' => 'iLPF just completed UAT',
            'story' => "Everyone involved may now breathe again.\nThree weeks of fixes, two all-nighters, one very patient client.",
            'project_id' => $this->ilpf->id,
            'track_ref' => 'TRK-MS-42',
            'team' => [$this->shazwan->id, $this->yati->id],
            'photos' => [
                UploadedFile::fake()->image('uat-1.jpg', 640, 480),
                UploadedFile::fake()->image('uat-2.jpg', 640, 480),
                UploadedFile::fake()->image('uat-3.jpg', 640, 480),
            ],
        ], $over);
    }

    private function raise(): object
    {
        $this->actingInTenantAs($this->kussairi)->post('/app/big-deals', $this->payload())->assertSessionHasNoErrors();
        $deal = DB::table('big_deals')->orderByDesc('id')->first();
        $this->assertNotNull($deal, 'big_deals row missing');

        return $deal;
    }

    /** The banner section for one deal, so assertions cannot be satisfied by another band. */
    private function bannerHtml(TestResponse $page, int $dealId): string
    {
        $html = $page->getContent();
        $start = strpos($html, 'data-big-deal="'.$dealId.'"');
        $this->assertNotFalse($start, "banner for deal {$dealId} missing");
        $start = strrpos(substr($html, 0, $start), '<section');
        $end = strpos($html, '</section>', $start);

        return substr($html, $start, $end - $start);
    }
}
