<?php

namespace Tests\Acceptance;

use App\Models\Employee;
use App\Models\GreetingLine;
use App\Models\PublicHoliday;
use App\Support\GreetingBank;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Acceptance for docs/specs/CR-33.md (session S20, creative greeting line). Most of the
 * bank already exists (App\Support\GreetingBank, App\Models\GreetingLine, HR curation on
 * Company Settings, staff suggestions). This file pins the spec's rules on top of it. Shapes
 * fixed in OPEN "QA / CR-33 / shapes fixed by CR33Test":
 *
 * - The dashboard heading is the `<h1>` on `/app/dash`; the English line is in the h1 text,
 *   the Malay line of the same row sits in its `x-text` expression, so one response carries
 *   both. Each page load picks one approved line; the previous line's id is remembered in the
 *   session so two consecutive loads never show the same line when the bucket has more than
 *   one candidate.
 * - Lines never talk about performance or lateness: no approved line, in either language,
 *   may fire because of overdue cards or a missing clock-in, and no shown line may contain
 *   "overdue", "past due", "late", "clock in", "clock-in", "waiting on you", "tertunggak",
 *   "lewat" or "belum clock". The existing `overdue` and `not_clocked_in` triggers contradict
 *   the spec ("never about performance or lateness") and must go from the bank and the picker.
 * - Spec buckets that must exist as approved lines (trigger names): `early` (before 08:00),
 *   `morning`, `afternoon`, `evening`; `monday`, `wednesday`, `friday`, `saturday` (weekend
 *   lines may keep the `weekend` trigger for Sunday); `month_start` (first dashboard load of
 *   the calendar month), `all_clear` (no open card past due), `long_weekend` (a public holiday
 *   adjoining the coming weekend), `holiday_eve`; `birthday`, `anniversary` (joined_at month
 *   and day), `back_from_leave` (first load after an approved leave that ended yesterday or
 *   later than the last load). Weather lines (`rain`) are only ever shown when
 *   `config('services.weather.enabled')` is true; it ships unset, so they are skipped.
 * - Priority stays personal > situation > day > time (GreetingLine::BUCKETS).
 * - "Keep it plain" (dashboard prefs `plain`) yields exactly "Good morning, {first name}."
 *   (or afternoon/evening) and the Malay "Selamat pagi, {first name}." with nothing else.
 * - The bank holds at least 60 approved lines per tenant, each with both languages.
 */
class CR33Test extends TestCase
{
    use AlwaysChecks;
    use RefreshDatabase;

    private const FORBIDDEN = ['overdue', 'past due', 'late', 'clock in', 'clock-in', 'waiting on you', 'tertunggak', 'lewat', 'belum clock'];

    private const SPEC_TRIGGERS = ['early', 'morning', 'afternoon', 'evening', 'monday', 'wednesday', 'friday', 'saturday', 'month_start', 'all_clear', 'long_weekend', 'holiday_eve', 'birthday', 'anniversary', 'back_from_leave'];

    private Employee $yati;

    protected function setUp(): void
    {
        parent::setUp();
        GreetingBank::seed($this->tenant()->id);
        $this->yati = $this->person('Yati Binti Moktar', 'employee', ['joined_at' => '2023-03-01', 'date_of_birth' => '1995-09-15']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── 1. Tuesday morning: rotation, no repeats, never about performance ──

    public function test_acceptance_1_ten_tuesday_morning_loads_rotate_without_immediate_repeats(): void
    {
        $this->assertGreaterThanOrEqual(60, GreetingLine::where('tenant_id', $this->tenant()->id)->approved()->count(), 'the bank has fewer than 60 approved lines');
        $this->assertSame(0, GreetingLine::where('tenant_id', $this->tenant()->id)->where(fn ($q) => $q->where('text_en', '')->orWhere('text_ms', '')->orWhereNull('text_ms'))->count(), 'a line is missing one language');
        foreach (self::SPEC_TRIGGERS as $trigger) {
            $this->assertTrue(GreetingLine::where('tenant_id', $this->tenant()->id)->approved()->where('trigger', $trigger)->exists(), "no approved line for the spec bucket '{$trigger}'");
        }
        $this->assertFalse(GreetingLine::where('tenant_id', $this->tenant()->id)->whereIn('trigger', ['overdue', 'not_clocked_in'])->exists(), 'the bank still carries lines about overdue work or clocking in');

        // Tuesday 2026-09-15, 10:00, an overdue card and no clock-in: neither may colour the line.
        $this->card($this->yati, ['title' => 'Late card', 'due_at' => '2026-09-01']);
        Carbon::setTestNow('2026-09-15 10:00:00');

        $seen = [];
        $previous = null;
        for ($i = 0; $i < 10; $i++) {
            $h1 = $this->heading($this->loadDash());
            $this->assertNotSame($previous, $h1, "load {$i} repeated the previous line");
            $this->assertCleanLine($h1);
            $line = $this->lineFor($h1);
            $this->assertContains($line->trigger, ['morning', 'tuesday'], "load {$i} showed a '{$line->trigger}' line on a plain Tuesday morning: {$h1}");
            $seen[$h1] = true;
            $previous = $h1;
        }

        $this->assertGreaterThanOrEqual(3, count($seen), 'fewer than 3 different lines over ten loads');
    }

    // ── 2. Friday 4 PM ──

    public function test_acceptance_2_friday_afternoon_shows_a_friday_or_evening_line(): void
    {
        Carbon::setTestNow('2026-09-18 16:00:00');

        for ($i = 0; $i < 5; $i++) {
            $h1 = $this->heading($this->loadDash());
            $this->assertCleanLine($h1);
            $line = $this->lineFor($h1);
            $this->assertContains($line->trigger, ['friday', 'evening'], "Friday 4 PM showed a '{$line->trigger}' line: {$h1}");
            $this->assertStringContainsString('Yati', $h1);
        }
    }

    // ── 3. Birthday beats everything ──

    public function test_acceptance_3_birthday_line_wins_over_every_other_bucket(): void
    {
        // 2026-09-15 is Yati's birthday, a Tuesday, the eve of Malaysia Day, with an overdue card.
        PublicHoliday::create(['tenant_id' => $this->tenant()->id, 'name' => 'Malaysia Day', 'date' => '2026-09-16']);
        $this->card($this->yati, ['title' => 'Late card', 'due_at' => '2026-09-01']);
        Carbon::setTestNow('2026-09-15 09:00:00');

        for ($i = 0; $i < 10; $i++) {
            $h1 = $this->heading($this->loadDash());
            $this->assertSame('birthday', $this->lineFor($h1)->trigger, "birthday lost to another bucket: {$h1}");
            $this->assertStringContainsString('Yati', $h1);
        }

        // The day after, the birthday line is gone.
        Carbon::setTestNow('2026-09-16 09:00:00');
        $this->assertNotSame('birthday', $this->lineFor($this->heading($this->loadDash()))->trigger);
    }

    // ── 4. Keep it plain ──

    public function test_acceptance_4_keep_it_plain_gives_only_the_plain_greeting(): void
    {
        $this->actingInTenantAs($this->yati)->postJson('/app/dashboard/prefs', ['plain' => true])->assertOk();
        PublicHoliday::create(['tenant_id' => $this->tenant()->id, 'name' => 'Malaysia Day', 'date' => '2026-09-16']);

        Carbon::setTestNow('2026-09-15 09:00:00'); // birthday, holiday eve, Tuesday morning
        $response = $this->loadDash();
        $this->assertSame('Good morning, Yati.', $this->heading($response));
        $response->assertSee('Selamat pagi, Yati.', false);
        $this->assertNull(GreetingLine::where('text_en', 'Good morning, Yati.')->first());

        Carbon::setTestNow('2026-09-18 16:00:00');
        $this->assertSame('Good afternoon, Yati.', $this->heading($this->loadDash()));

        Carbon::setTestNow('2026-09-18 19:00:00');
        $this->assertSame('Good evening, Yati.', $this->heading($this->loadDash()));

        // Off again: the bank is back.
        $this->actingInTenantAs($this->yati)->postJson('/app/dashboard/prefs', ['plain' => false])->assertOk();
        Carbon::setTestNow('2026-09-18 16:00:00');
        $this->assertNotSame('Good afternoon, Yati.', $this->heading($this->loadDash()));
    }

    // ── 5. BM ──

    public function test_acceptance_5_bahasa_line_of_the_same_row_is_served_with_the_page(): void
    {
        Carbon::setTestNow('2026-09-15 10:00:00');

        for ($i = 0; $i < 5; $i++) {
            $response = $this->loadDash();
            $line = $this->lineFor($this->heading($response));
            $ms = str_replace('{name}', 'Yati', $line->text_ms);
            $this->assertNotSame('', trim($line->text_ms), "line {$line->id} has no Malay text");
            $this->assertNotSame($line->text_en, $line->text_ms, "line {$line->id} has the English text as its Malay text");
            $response->assertSee(e($ms), false);
            $this->assertCleanLine($ms);
        }

        // A staff suggestion is not in rotation until HR approves it.
        $this->actingInTenantAs($this->yati)
            ->post('/app/greetings/suggest', ['trigger' => 'morning', 'text_en' => 'Suggested morning, {name}.', 'text_ms' => 'Cadangan pagi, {name}.'])
            ->assertSessionHasNoErrors();
        $suggested = GreetingLine::where('text_en', 'Suggested morning, {name}.')->firstOrFail();
        $this->assertNull($suggested->approved_at);
        for ($i = 0; $i < 10; $i++) {
            $this->assertNotSame('Suggested morning, Yati.', $this->heading($this->loadDash()), 'an unapproved suggestion was shown');
        }

        $hr = $this->person('Hidayah HR', 'hr');
        $this->actingInTenantAs($hr)->post("/app/admin/greetings/{$suggested->id}", ['approve' => 1])->assertSessionHasNoErrors();
        $this->assertNotNull($suggested->fresh()->approved_at, 'HR approval did not land');
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

    private function loadDash(): TestResponse
    {
        return $this->actingInTenantAs($this->yati)->get('/app/dash')->assertOk();
    }

    private function heading(TestResponse $response): string
    {
        preg_match('/<h1[^>]*>(.*?)<\/h1>/s', $response->getContent(), $m);
        $this->assertNotEmpty($m, 'no <h1> on the dashboard');

        return html_entity_decode(trim(strip_tags($m[1])), ENT_QUOTES | ENT_HTML5);
    }

    /** The approved bank row the shown heading came from (name filled in). */
    private function lineFor(string $h1): GreetingLine
    {
        $line = GreetingLine::where('tenant_id', $this->tenant()->id)->approved()->get()
            ->first(fn (GreetingLine $l) => str_replace('{name}', 'Yati', $l->text_en) === $h1);
        $this->assertNotNull($line, "heading is not an approved bank line: {$h1}");

        return $line;
    }

    private function assertCleanLine(string $text): void
    {
        foreach (self::FORBIDDEN as $word) {
            $this->assertStringNotContainsStringIgnoringCase($word, $text, "greeting talks about performance or lateness: {$text}");
        }
    }
}
