<?php

namespace Tests\Acceptance;

use App\Models\Employee;
use App\Support\EasterEggBank;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Acceptance for docs/specs/CR-31.md (session S21, dashboard easter eggs). The "Keep it
 * plain" toggle already exists (S04, `DashboardPrefs` key `plain`, `POST /app/dashboard/prefs`).
 * Shapes fixed in OPEN "QA / CR-31 / shapes fixed by CR31Test":
 *
 * - Bank: table `easter_eggs` (tenant_id, kind, text_en, text_ms, approved_at, suggested_by,
 *   timestamps), seeded per tenant with at least two approved lines per kind in both
 *   languages. Kinds: `friday_late` (Friday from 17:00), `inbox_zero` (the last overdue open
 *   card moved to Done), `late_night` (a dashboard load from 22:00), `tab_collector` (20+
 *   Amanahku tabs, client-side, human check), `holiday_eve` (text only; the celebration itself
 *   is the CR-20 moment). HR curates on Company Settings: `POST /app/admin/eggs`,
 *   `POST /app/admin/eggs/{easterEgg}` (edit or `approve=1`), `POST /app/admin/eggs/{easterEgg}/delete`;
 *   employees get 403 there.
 * - Delivery on the dashboard: one `<div class="uj-egg" data-egg="<kind>">` carrying the
 *   English text and the Malay text (x-text on `$store.ui.lang`, same as the greeting), no
 *   band, no new widget, nothing on the page when no egg is active. The `late_night` egg also
 *   carries `<a data-egg-shortcut href="/app/overtime">` ("Log your hours as overtime?").
 * - Delivery on the board: the JSON of `POST /app/board/{card}/move {status: done}` gains
 *   `egg: {kind, text_en, text_ms} | null`; `inbox_zero` fires only when the moved card was
 *   the viewer's last overdue open card.
 * - Once per day per user: table `easter_egg_views` (tenant_id, employee_id, kind, shown_on)
 *   unique on (employee_id, kind, shown_on). A second show the same day renders nothing; the
 *   next day it can show again.
 * - Keep it plain: no `uj-egg`, `egg: null` on move, no `uj-db-confetti`, and `<body data-plain>`
 *   so the shell animations named in OPEN "QA / CR-06a / Keep it plain still leaves shell
 *   animations running" (`.uj-fade`, `.uj-dw-tile-in`, `.kb-pulse-ring`) are switched off in
 *   CSS under plain. The profile screen shows the same "Keep it plain" switch bound to the
 *   same prefs key (no second flag).
 * - No sound, no timer that blocks, nothing judgemental in the late-night text.
 */
class CR31Test extends TestCase
{
    use AlwaysChecks;
    use RefreshDatabase;

    private Employee $yati;

    protected function setUp(): void
    {
        parent::setUp();
        $this->yati = $this->person('Yati Binti Moktar');
        if (class_exists(EasterEggBank::class)) {
            EasterEggBank::seed($this->tenant()->id);
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── 1. Friday 5:05 PM, once ──

    public function test_acceptance_1_friday_after_five_shows_the_egg_once_per_day(): void
    {
        $this->assertTrue(Schema::hasTable('easter_eggs'), 'no easter_eggs bank table');
        foreach (['friday_late', 'inbox_zero', 'late_night', 'tab_collector', 'holiday_eve'] as $kind) {
            $rows = DB::table('easter_eggs')->where('tenant_id', $this->tenant()->id)->where('kind', $kind)->whereNotNull('approved_at');
            $this->assertGreaterThanOrEqual(2, $rows->count(), "fewer than two approved '{$kind}' eggs");
            $this->assertSame(0, (clone $rows)->where(fn ($q) => $q->whereNull('text_ms')->orWhere('text_ms', '')->orWhereNull('text_en')->orWhere('text_en', ''))->count(), "an '{$kind}' egg is missing a language");
        }

        // Thursday 17:05 and Friday 16:55: nothing.
        Carbon::setTestNow('2026-09-10 17:05:00');
        $this->dash()->assertDontSee('uj-egg', false);
        Carbon::setTestNow('2026-09-11 16:55:00');
        $this->dash()->assertDontSee('uj-egg', false);

        // Friday 17:05: the egg, with both languages.
        Carbon::setTestNow('2026-09-11 17:05:00');
        $first = $this->dash();
        $first->assertSee('data-egg="friday_late"', false);
        $text = $this->eggText($first, 'friday_late');
        $this->assertNotSame('', $text);
        $this->assertTrue(
            DB::table('easter_eggs')->where('tenant_id', $this->tenant()->id)->where('kind', 'friday_late')->where('text_en', $text)->exists(),
            "shown text is not a bank line: {$text}"
        );
        $ms = DB::table('easter_eggs')->where('tenant_id', $this->tenant()->id)->where('text_en', $text)->value('text_ms');
        $first->assertSee(e($ms), false);
        $this->assertDatabaseHas('easter_egg_views', ['employee_id' => $this->yati->id, 'kind' => 'friday_late', 'shown_on' => '2026-09-11']);

        // Same day again: nothing. Even at 17:30.
        Carbon::setTestNow('2026-09-11 17:30:00');
        $this->dash()->assertDontSee('uj-egg', false);

        // A week later: again.
        Carbon::setTestNow('2026-09-18 17:05:00');
        $this->dash()->assertSee('data-egg="friday_late"', false);

        // The colleague's view is not consumed by Yati's.
        $colleague = $this->person('Colleague Two');
        $this->actingInTenantAs($colleague)->get('/app/dash')->assertOk()->assertSee('data-egg="friday_late"', false);
    }

    // ── 2. Clear the last overdue card ──

    public function test_acceptance_2_clearing_the_last_overdue_card_returns_the_inbox_zero_egg_once(): void
    {
        Carbon::setTestNow('2026-09-09 15:00:00');
        $late1 = $this->card($this->yati, ['title' => 'Late one', 'due_at' => '2026-09-01']);
        $late2 = $this->card($this->yati, ['title' => 'Late two', 'due_at' => '2026-09-02']);
        $this->card($this->yati, ['title' => 'Future', 'due_at' => '2026-09-30']);

        $r1 = $this->move($late1);
        $this->assertNull($r1->json('egg'), 'egg fired while another overdue card was still open');

        $r2 = $this->move($late2);
        $this->assertSame('inbox_zero', $r2->json('egg.kind'), 'no egg when the last overdue card cleared');
        $this->assertNotSame('', (string) $r2->json('egg.text_en'));
        $this->assertNotSame('', (string) $r2->json('egg.text_ms'));
        $this->assertTrue(DB::table('easter_eggs')->where('tenant_id', $this->tenant()->id)->where('kind', 'inbox_zero')->where('text_en', $r2->json('egg.text_en'))->exists());
        $this->assertDatabaseHas('easter_egg_views', ['employee_id' => $this->yati->id, 'kind' => 'inbox_zero', 'shown_on' => '2026-09-09']);

        // Reopen and clear again the same day: no second show.
        $this->actingInTenantAs($this->yati)->postJson("/app/board/{$late2->id}/move", ['status' => 'todo'])->assertOk();
        $this->assertNull($this->move($late2)->json('egg'), 'inbox_zero shown twice in one day');

        // The move itself was never blocked.
        $this->assertSame('done', $late2->fresh()->status);

        // Dashboard on a quiet day carries no egg for this.
        $this->dash()->assertDontSee('uj-egg', false);
    }

    // ── 3. Keep it plain ──

    public function test_acceptance_3_keep_it_plain_removes_every_egg_and_every_confetti(): void
    {
        $this->actingInTenantAs($this->yati)->postJson('/app/dashboard/prefs', ['plain' => true])->assertOk();
        $this->person('Birthday Colleague', 'employee', ['date_of_birth' => '1990-09-11']);

        Carbon::setTestNow('2026-09-11 17:05:00'); // Friday after five, colleague's birthday
        $page = $this->dash();
        $page->assertDontSee('uj-egg', false);
        $page->assertDontSee('uj-db-confetti', false);
        $page->assertSee('<body data-plain', false);
        $this->assertDatabaseMissing('easter_egg_views', ['employee_id' => $this->yati->id]);

        Carbon::setTestNow('2026-09-11 22:30:00');
        $this->dash()->assertDontSee('uj-egg', false);

        $late = $this->card($this->yati, ['title' => 'Late', 'due_at' => '2026-09-01']);
        $this->assertNull($this->move($late)->json('egg'));

        // The profile screen carries the same switch, bound to the same key.
        $profile = $this->actingInTenantAs($this->yati)->get('/app/profile')->assertOk();
        $profile->assertSee('Keep it plain', false);
        $profile->assertSee('/app/dashboard/prefs', false);

        // Off again: the body attribute goes and the egg is back.
        $this->actingInTenantAs($this->yati)->postJson('/app/dashboard/prefs', ['plain' => false])->assertOk();
        Carbon::setTestNow('2026-09-11 17:05:00');
        $loud = $this->dash();
        $loud->assertDontSee('<body data-plain', false);
        $loud->assertSee('data-egg="friday_late"', false);
    }

    // ── 4. Late-night login with the overtime shortcut ──

    public function test_acceptance_4_late_night_login_shows_the_egg_with_an_overtime_shortcut(): void
    {
        Carbon::setTestNow('2026-09-08 21:30:00');
        $this->dash()->assertDontSee('data-egg="late_night"', false);

        Carbon::setTestNow('2026-09-08 22:30:00');
        $page = $this->dash();
        $page->assertSee('data-egg="late_night"', false);
        $page->assertSee('data-egg-shortcut', false);
        $page->assertSee('href="/app/overtime"', false);
        $text = $this->eggText($page, 'late_night');
        foreach (['lazy', 'slack', 'should have', 'wasting', 'again?'] as $bad) {
            $this->assertStringNotContainsStringIgnoringCase($bad, $text, "late-night egg is judgemental: {$text}");
        }
        $page->assertDontSee('<audio', false);

        // Once per day.
        Carbon::setTestNow('2026-09-08 23:10:00');
        $this->dash()->assertDontSee('data-egg="late_night"', false);
        Carbon::setTestNow('2026-09-09 22:30:00');
        $this->dash()->assertSee('data-egg="late_night"', false);
    }

    // ── 5. HR edits the bank, staff cannot ──

    public function test_acceptance_5_hr_edits_the_egg_bank_and_staff_cannot(): void
    {
        $hr = $this->person('Hidayah HR', 'hr');
        $this->actingInTenantAs($hr)
            ->post('/app/admin/eggs', ['kind' => 'friday_late', 'text_en' => 'Custom Friday line.', 'text_ms' => 'Baris Jumaat tersendiri.'])
            ->assertSessionHasNoErrors();
        $id = DB::table('easter_eggs')->where('text_en', 'Custom Friday line.')->value('id');
        $this->assertNotNull($id, 'HR could not add an egg');
        $this->assertNotNull(DB::table('easter_eggs')->where('id', $id)->value('approved_at'), 'an HR-added egg is not approved');

        $this->actingInTenantAs($hr)
            ->post("/app/admin/eggs/{$id}", ['kind' => 'friday_late', 'text_en' => 'Custom Friday line, edited.', 'text_ms' => 'Baris Jumaat, disunting.'])
            ->assertSessionHasNoErrors();
        $this->assertSame('Custom Friday line, edited.', DB::table('easter_eggs')->where('id', $id)->value('text_en'));

        $this->actingInTenantAs($this->yati)->post('/app/admin/eggs', ['kind' => 'friday_late', 'text_en' => 'Nope.', 'text_ms' => 'Tidak.'])->assertForbidden();
        $this->actingInTenantAs($this->yati)->post("/app/admin/eggs/{$id}/delete")->assertForbidden();

        $this->actingInTenantAs($hr)->post("/app/admin/eggs/{$id}/delete")->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('easter_eggs', ['id' => $id]);

        // Only approved lines can show: make the whole friday_late bank pending and load a Friday.
        DB::table('easter_eggs')->where('tenant_id', $this->tenant()->id)->where('kind', 'friday_late')->update(['approved_at' => null]);
        Carbon::setTestNow('2026-09-11 17:05:00');
        $this->dash()->assertDontSee('data-egg="friday_late"', false);
    }

    // ── 6. Tab collector ──

    public function test_acceptance_6_tab_collector_is_a_human_check(): void
    {
        $this->markTestIncomplete('human check: open 20+ Amanahku tabs in one browser, the "Professional Tab Collector detected." egg shows once that day and never with Keep it plain on.');
    }

    // ── Always ──

    public function test_always_due_dates_locked(): void
    {
        $this->assertDueDateLocked();
    }

    public function test_always_audit_log_immutable(): void
    {
        $this->assertAuditLogImmutable();
    }

    public function test_always_dashboard_unchanged(): void
    {
        $this->assertDashboardUnchanged();
    }

    public function test_always_keep_it_plain_honoured(): void
    {
        $this->assertKeepItPlainHonoured();
    }

    // ── helpers ──

    private function dash(): TestResponse
    {
        return $this->actingInTenantAs($this->yati)->get('/app/dash')->assertOk();
    }

    private function move($card): TestResponse
    {
        return $this->actingInTenantAs($this->yati)->postJson("/app/board/{$card->id}/move", ['status' => 'done'])->assertOk();
    }

    /** The English text inside the egg element for $kind. */
    private function eggText(TestResponse $response, string $kind): string
    {
        preg_match('/<div class="uj-egg"[^>]*data-egg="'.$kind.'"[^>]*>(.*?)<\/div>/s', $response->getContent(), $m);
        $this->assertNotEmpty($m, "no uj-egg element for {$kind}");
        preg_match('/data-egg-en="([^"]*)"/', $m[0], $en);

        return html_entity_decode($en[1] ?? '', ENT_QUOTES | ENT_HTML5);
    }
}
