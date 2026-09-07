<?php

namespace Tests\Acceptance;

use App\Models\AppNotification;
use App\Models\AuditLog;
use App\Models\BirthdayWish;
use App\Models\Employee;
use App\Models\KnowledgeEntry;
use App\Models\KnowledgeSegment;
use App\Models\TotSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Acceptance for docs/specs/CR-30.md (session S05, custom Unijaya reactions). The spec's
 * Acceptance paragraph is split into five numbered items in the order it lists them.
 *
 * Shapes this file fixes for the generator (recorded in docs/build/OPEN.md):
 * - the tenant's reaction set is a table, read through `GET /app/reactions` as
 *   `{reactions: [{key, label, icon, retired}]}` in display order, active ones only. A fresh
 *   tenant has the eight defaults, keys `power`, `legend`, `chefs_kiss`, `noted_with_fear`,
 *   `send_help`, `respect`, `how_did_you_do_this`, `claim_bila`, labels as the spec writes them.
 * - every existing react endpoint (`/app/tot/{session}/react`, `/app/knowledge-bank/{entry}/react`,
 *   `/app/birthday/wish/{wish}/react`) takes `reaction` = a key; an unknown or retired key and
 *   the old generic `emoji` field are 422. The JSON state keeps `reactions` (key => count) and
 *   `mine` (list of keys). One reaction per person per item: the same key again removes it, a
 *   different key replaces it.
 * - a picker button is `<button data-reaction-pick="<key>">`; a tally on an item is
 *   `[data-reaction-count="<key>"]`. Pickers offer active keys only; tallies show whatever was
 *   left, retired or not, with the retired reaction's label.
 * - HR and management manage the set on Company Settings: `POST /app/admin/reactions`
 *   `{key, label, icon}` adds one (422 past ten active, 422 on a duplicate key),
 *   `POST /app/admin/reactions/{key}/retire` retires one. There is no rename route; an existing
 *   reaction's label never changes. Employees get 403 on both.
 * - Request Help is `POST /app/board/{id}/request-help` `{employee_id, message}` by someone who
 *   may edit the card. It tags that person as Helper (CR-04 role `helper`), writes an
 *   `app_notifications` row for them and a `participants` audit row. A reaction, `send_help`
 *   included, writes no notification.
 */
class CR30Test extends TestCase
{
    use AlwaysChecks;
    use RefreshDatabase;

    private const DEFAULT_KEYS = ['power', 'legend', 'chefs_kiss', 'noted_with_fear', 'send_help', 'respect', 'how_did_you_do_this', 'claim_bila'];

    private const DEFAULT_LABELS = ['Power', 'Legend', "Chef's Kiss", 'Noted With Fear', 'Send Help', 'Respect', 'How Did You Do This?', 'Claim Bila?'];

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    #[Test]
    public function test_acceptance_1_reaction_picker_shows_the_eight_custom_reactions_everywhere(): void
    {
        Carbon::setTestNow(self::QUIET_DAY);
        $emysha = $this->person('Emysha');
        $yati = $this->person('Yati');

        // The catalog itself.
        $catalog = $this->actingInTenantAs($yati)->getJson('/app/reactions')->assertOk()->json('reactions');
        $this->assertSame(self::DEFAULT_KEYS, array_column($catalog, 'key'));
        $this->assertSame(self::DEFAULT_LABELS, array_column($catalog, 'label'));
        foreach ($catalog as $reaction) {
            $this->assertNotSame('', (string) ($reaction['icon'] ?? ''), "{$reaction['key']} has no icon");
            $this->assertFalse($reaction['retired']);
        }

        // Every surface that reacts today offers the same eight, by key, and nothing generic.
        $session = $this->totSession($emysha);
        $entry = $this->knowledgeEntry($emysha);
        $wish = $this->birthdayWish($emysha, $yati);

        foreach (['/app/tot', '/app/knowledge-bank', '/app/dash'] as $url) {
            $html = $this->actingInTenantAs($yati)->get($url)->assertOk()->getContent();
            foreach (self::DEFAULT_KEYS as $key) {
                $this->assertStringContainsString('data-reaction-pick="'.$key.'"', $html, "{$url} picker lacks {$key}");
            }
            foreach (['👍', '👏', '🔥', '💡', '🤔', '❤️'] as $emoji) {
                $this->assertStringNotContainsString('data-reaction-pick="'.$emoji.'"', $html, "{$url} still offers a generic emoji");
            }
        }

        // Each endpoint takes a key, counts once per person per item, and refuses the old emoji.
        foreach ([
            "/app/tot/{$session->id}/react",
            "/app/knowledge-bank/{$entry->id}/react",
            "/app/birthday/wish/{$wish->id}/react",
        ] as $url) {
            $this->actingInTenantAs($yati)->postJson($url, ['emoji' => '👍'])->assertStatus(422);
            $this->actingInTenantAs($yati)->postJson($url, ['reaction' => 'thumbs'])->assertStatus(422);

            $state = $this->actingInTenantAs($yati)->postJson($url, ['reaction' => 'power'])->assertOk()->json();
            $this->assertSame(1, $this->tally($state, 'power'), "{$url}: first Power did not count");
            $this->assertSame(['power'], $this->mine($state));

            $state = $this->actingInTenantAs($yati)->postJson($url, ['reaction' => 'power'])->assertOk()->json();
            $this->assertSame(0, $this->tally($state, 'power'), "{$url}: pressing Power again should undo it, never count twice");
            $this->assertNotContains('power', $this->mine($state), "{$url}: pressing Power again did not undo it");

            $this->actingInTenantAs($yati)->postJson($url, ['reaction' => 'legend'])->assertOk();
            $state = $this->actingInTenantAs($yati)->postJson($url, ['reaction' => 'respect'])->assertOk()->json();
            $this->assertSame(0, $this->tally($state, 'legend'), "{$url}: a second reaction did not replace the first");
            $this->assertSame(1, $this->tally($state, 'respect'));
        }
    }

    #[Test]
    public function test_acceptance_2_send_help_sends_no_notification(): void
    {
        Carbon::setTestNow(self::QUIET_DAY);
        $emysha = $this->person('Emysha');
        $yati = $this->person('Yati');
        $session = $this->totSession($emysha);
        $entry = $this->knowledgeEntry($emysha);

        $before = AppNotification::count();

        $this->actingInTenantAs($yati)->postJson("/app/tot/{$session->id}/react", ['reaction' => 'send_help'])->assertOk();
        $this->actingInTenantAs($yati)->postJson("/app/knowledge-bank/{$entry->id}/react", ['reaction' => 'send_help'])->assertOk();

        $this->assertSame($before, AppNotification::count(), 'a Send Help reaction notified someone');
        // The same press again is the undo, not a repeat, and still tells nobody.
        $state = $this->actingInTenantAs($yati)->postJson("/app/tot/{$session->id}/react", ['reaction' => 'send_help'])->assertOk()->json();
        $this->assertSame(0, $this->tally($state, 'send_help'));
        $this->assertSame($before, AppNotification::count());
    }

    #[Test]
    public function test_acceptance_3_request_help_on_a_card_notifies_and_tags_a_helper(): void
    {
        Carbon::setTestNow(self::QUIET_DAY);
        $emysha = $this->person('Emysha');
        $yati = $this->person('Yati');
        $adri = $this->person('Adri');
        $card = $this->card($emysha, ['title' => 'Deploy the new pricing page']);

        $notificationsBefore = AppNotification::where('user_id', $yati->user_id)->count();

        // Validation: a person and a short message are required.
        $this->actingInTenantAs($emysha)->postJson("/app/board/{$card->id}/request-help", [])->assertStatus(422);
        $this->actingInTenantAs($emysha)->postJson("/app/board/{$card->id}/request-help", ['employee_id' => $yati->id, 'message' => str_repeat('x', 300)])->assertStatus(422);

        // Someone who cannot edit the card cannot ask for help on it.
        $this->actingInTenantAs($adri)->postJson("/app/board/{$card->id}/request-help", ['employee_id' => $yati->id, 'message' => 'Help'])->assertStatus(403);

        $this->actingInTenantAs($emysha)
            ->postJson("/app/board/{$card->id}/request-help", ['employee_id' => $yati->id, 'message' => 'Stuck on the CDN cache rule, can you look?'])
            ->assertOk();

        $this->assertSame('helper', $card->fresh()->roleFor($yati->id), 'Request Help did not tag the person as Helper');
        $this->assertSame(1, $card->participants()->count(), 'Request Help tagged more than the one person');

        $notification = AppNotification::where('user_id', $yati->user_id)->latest('id')->first();
        $this->assertSame($notificationsBefore + 1, AppNotification::where('user_id', $yati->user_id)->count(), 'the helper was not notified');
        $this->assertStringContainsString('Emysha', $notification->title.' '.$notification->body, 'the notification does not say who asked');
        $this->assertStringContainsString('Stuck on the CDN cache rule', $notification->title.' '.$notification->body, 'the notification does not carry the message');
        $this->assertStringContainsString((string) $card->id, (string) $notification->url, 'the notification does not link to the card');

        $audit = AuditLog::where('subject_id', $card->id)->where('field', 'participants')->latest('id')->first();
        $this->assertNotNull($audit, 'no audit entry for the Helper tag');
        $this->assertStringContainsString("{$yati->id}:helper", (string) $audit->new_value);

        // Asking the same person again is not a second tag and not a second notification.
        $this->actingInTenantAs($emysha)
            ->postJson("/app/board/{$card->id}/request-help", ['employee_id' => $yati->id, 'message' => 'Still stuck.'])
            ->assertOk();
        $this->assertSame(1, $card->participants()->count());
        $this->assertSame($notificationsBefore + 2, AppNotification::where('user_id', $yati->user_id)->count(), 'a second explicit request is a second message');
    }

    #[Test]
    public function test_acceptance_4_hr_adds_a_ninth_reaction_never_renames_and_stops_at_ten(): void
    {
        Carbon::setTestNow(self::QUIET_DAY);
        $hr = $this->person('Hidayah HR', 'hr');
        $staff = $this->person('Emysha');
        $emysha = $this->person('Emysha Two');

        // Staff may not touch the set.
        $this->actingInTenantAs($staff)->postJson('/app/admin/reactions', ['key' => 'goat', 'label' => 'GOAT', 'icon' => 'goat'])->assertStatus(403);

        // HR adds a ninth; it shows up in the catalog and in a picker straight away.
        $this->actingInTenantAs($hr)->postJson('/app/admin/reactions', ['key' => 'goat', 'label' => 'GOAT', 'icon' => 'goat'])->assertSuccessful();
        $catalog = $this->actingInTenantAs($hr)->getJson('/app/reactions')->assertOk()->json('reactions');
        $this->assertCount(9, $catalog);
        $this->assertSame('goat', $catalog[8]['key']);
        $this->assertSame('GOAT', $catalog[8]['label']);

        $this->totSession($emysha);
        $this->actingInTenantAs($staff)->get('/app/tot')->assertOk()->assertSee('data-reaction-pick="goat"', false);

        // Duplicate key, then the tenth, then the eleventh is refused.
        $this->actingInTenantAs($hr)->postJson('/app/admin/reactions', ['key' => 'goat', 'label' => 'Again', 'icon' => 'goat'])->assertStatus(422);
        $this->actingInTenantAs($hr)->postJson('/app/admin/reactions', ['key' => 'tenth', 'label' => 'Tenth', 'icon' => 'star'])->assertSuccessful();
        $this->actingInTenantAs($hr)->postJson('/app/admin/reactions', ['key' => 'eleventh', 'label' => 'Eleventh', 'icon' => 'star'])->assertStatus(422);
        $this->assertCount(10, $this->actingInTenantAs($hr)->getJson('/app/reactions')->json('reactions'));

        // Never rename: whatever route the client tries, "Power" stays "Power".
        foreach (['patchJson', 'putJson', 'postJson'] as $verb) {
            $response = $this->actingInTenantAs($hr)->{$verb}('/app/admin/reactions/power', ['label' => 'Strength']);
            $this->assertFalse($response->isSuccessful(), "{$verb} /app/admin/reactions/power renamed a reaction (".$response->status().')');
        }
        $labels = array_column($this->actingInTenantAs($hr)->getJson('/app/reactions')->json('reactions'), 'label', 'key');
        $this->assertSame('Power', $labels['power']);
    }

    #[Test]
    public function test_acceptance_5_a_retired_reaction_still_shows_on_old_items_but_leaves_the_picker(): void
    {
        Carbon::setTestNow(self::QUIET_DAY);
        $hr = $this->person('Hidayah HR', 'hr');
        $emysha = $this->person('Emysha');
        $yati = $this->person('Yati');
        $session = $this->totSession($emysha);

        $this->actingInTenantAs($yati)->postJson("/app/tot/{$session->id}/react", ['reaction' => 'claim_bila'])->assertOk();

        $this->actingInTenantAs($emysha)->postJson('/app/admin/reactions/claim_bila/retire')->assertStatus(403);
        $this->actingInTenantAs($hr)->postJson('/app/admin/reactions/claim_bila/retire')->assertSuccessful();

        // Gone from the catalog and the picker...
        $keys = array_column($this->actingInTenantAs($yati)->getJson('/app/reactions')->json('reactions'), 'key');
        $this->assertNotContains('claim_bila', $keys);
        $this->assertCount(7, $keys);
        $html = $this->actingInTenantAs($yati)->get('/app/tot')->assertOk()->getContent();
        $this->assertStringNotContainsString('data-reaction-pick="claim_bila"', $html);

        // ...still on the item it was left on, readable by label.
        $this->assertStringContainsString('data-reaction-count="claim_bila"', $html, 'the old reaction vanished from the item');
        $this->assertStringContainsString('Claim Bila?', $html, 'the retired reaction lost its label');

        // Nobody can leave it any more, and the one already there is still counted.
        $this->actingInTenantAs($emysha)->postJson("/app/tot/{$session->id}/react", ['reaction' => 'claim_bila'])->assertStatus(422);
        $state = $this->actingInTenantAs($emysha)->postJson("/app/tot/{$session->id}/react", ['reaction' => 'power'])->assertOk()->json();
        $this->assertSame(1, $this->tally($state, 'claim_bila'));
    }

    #[Test]
    public function test_always_checks_from_s05(): void
    {
        $this->assertDueDateLocked();
        $this->assertAuditLogImmutable();
        $this->assertDashboardUnchanged();
        $this->assertKeepItPlainHonoured();
    }

    private function totSession(Employee $presenter): TotSession
    {
        return TotSession::create([
            'tenant_id' => $this->tenant()->id, 'year' => 2026, 'month' => 9,
            'presenter_employee_id' => $presenter->id, 'title' => 'Ship faster with feature flags', 'status' => 'done',
        ]);
    }

    private function knowledgeEntry(Employee $author): KnowledgeEntry
    {
        $segment = KnowledgeSegment::create(['tenant_id' => $this->tenant()->id, 'label' => 'Engineering', 'sort_order' => 1]);

        return KnowledgeEntry::create([
            'tenant_id' => $this->tenant()->id, 'seg_id' => $segment->id, 'employee_id' => $author->id,
            'title' => 'Cache rules for the pricing page', 'body' => 'Purge before publish.',
        ]);
    }

    private function birthdayWish(Employee $celebrant, Employee $author): BirthdayWish
    {
        // A wish on today's birthday, so the dashboard band (and its picker) is on the page.
        $celebrant->forceFill(['date_of_birth' => '1995-09-08', 'birthday_private' => false])->save();

        return BirthdayWish::create([
            'tenant_id' => $this->tenant()->id, 'employee_id' => $celebrant->id, 'author_id' => $author->id,
            'body' => 'Happy birthday!', 'celebrated_on' => '2026-09-08',
        ]);
    }

    /** @param  array<string, mixed>  $state */
    private function tally(array $state, string $key): int
    {
        return (int) ($state['reactions'][$key] ?? 0);
    }

    /**
     * @param  array<string, mixed>  $state
     * @return list<string>
     */
    private function mine(array $state): array
    {
        return array_values($state['mine'] ?? []);
    }
}
