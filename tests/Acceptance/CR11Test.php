<?php

namespace Tests\Acceptance;

use App\Models\AuditLog;
use App\Models\CompanyEvent;
use App\Models\Employee;
use App\Models\WorkItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Acceptance for docs/specs/CR-11.md (session S13, Events: attendees, T.A.A. and calendar
 * sync, post-event sharing). Shapes fixed in OPEN "QA / CR-11 / shapes fixed by CR11Test":
 * `starts_at`/`ends_at` on the event, `events.attendees` replacing the set (RSVP rows +
 * one Event card each + a CalendarPort upsert each), `events.show` with the post-event
 * sections after `ends_at`, photos / comments / one lesson per attendee mirrored into
 * Knowledge, removal archiving the card and sending a deleteEvent, and the `events`
 * dashboard widget. The calendar half is a `port_outbox` intent (ports contract, stub driver).
 */
class CR11Test extends TestCase
{
    use AlwaysChecks;
    use RefreshDatabase;

    private const TITLE = 'Exclusive Invitation: HPE AI Developer Days';

    private Employee $hr;

    private Employee $kussairi;

    private Employee $syakir;

    private Employee $staff;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-08-20 10:00:00');

        $this->hr = $this->person('Hidayah', 'hr');
        $this->kussairi = $this->person('Kussairi', 'manager');
        $this->syakir = $this->person('Syakir');
        $this->staff = $this->person('Shazwan');
    }

    // ── 1. attendees, T.A.A. cards, calendar intent ─────────────────

    public function test_acceptance_1_tagging_kussairi_and_syakir_puts_the_event_on_both_boards_and_calendars(): void
    {
        $event = $this->hpeEvent();
        $cardsBefore = WorkItem::query()->count();

        // Plain staff, not the creator, cannot set attendees.
        $this->actingInTenantAs($this->staff)
            ->postJson("/app/events/{$event->id}/attendees", ['attendees' => [$this->staff->id]])
            ->assertStatus(403);
        $this->assertSame($cardsBefore, WorkItem::query()->count());

        $this->actingInTenantAs($this->hr)
            ->postJson("/app/events/{$event->id}/attendees", ['attendees' => [$this->kussairi->id, $this->syakir->id]])
            ->assertSuccessful();

        // Attendees are RSVP rows, Going by default.
        $this->assertEqualsCanonicalizing(
            [$this->kussairi->id, $this->syakir->id],
            DB::table('event_rsvps')->where('company_event_id', $event->id)->pluck('employee_id')->all()
        );
        $this->assertSame(['going', 'going'], DB::table('event_rsvps')->where('company_event_id', $event->id)->pluck('response')->all());

        // One Event card each.
        $cards = WorkItem::query()->where('company_event_id', $event->id)->get();
        $this->assertCount(2, $cards);
        foreach ([$this->kussairi, $this->syakir] as $attendee) {
            $card = $cards->firstWhere('employee_id', $attendee->id);
            $this->assertNotNull($card, "{$attendee->display_name} has no Event card");
            $this->assertSame('event', $card->type);
            $this->assertSame(self::TITLE, $card->title);
            $this->assertSame('2026-08-27', $card->due_at?->format('Y-m-d'));
            $this->assertNull($card->archived_at);
            $this->assertStringContainsString('HPE Malaysia', (string) $card->description, 'the card carries the location');
            $this->assertNotNull($card->google_event_id, 'the calendar external id is kept on the card');
        }

        // Both boards show it, an outsider's does not.
        $this->actingInTenantAs($this->kussairi)->get('/app/board')->assertOk()->assertSee(self::TITLE);
        $this->actingInTenantAs($this->syakir)->get('/app/board')->assertOk()->assertSee(self::TITLE);
        $this->actingInTenantAs($this->staff)->get('/app/board')->assertOk()->assertDontSee(self::TITLE);

        // Calendar: one recorded upsertEvent intent per attendee, pointing at that attendee's card.
        $upserts = DB::table('port_outbox')->where('port', 'calendar')->where('method', 'upsertEvent')->get();
        $this->assertCount(2, $upserts);
        foreach ([$this->kussairi, $this->syakir] as $attendee) {
            $card = $cards->firstWhere('employee_id', $attendee->id);
            $row = $upserts->first(fn ($r) => (int) (json_decode($r->payload, true)['for_employee_id'] ?? 0) === $attendee->id);
            $this->assertNotNull($row, "no calendar intent for {$attendee->display_name}");
            $payload = json_decode($row->payload, true);
            $this->assertSame('sent', $row->status);
            $this->assertSame(self::TITLE, $payload['title']);
            $this->assertStringStartsWith('2026-08-27T09:00', $payload['starts_at']);
            $this->assertSame($card->id, (int) $row->subject_id);
            $this->assertSame($row->external_id, $card->google_event_id);
        }

        // Saving the same set again is idempotent; adding one more adds one card.
        $this->actingInTenantAs($this->hr)
            ->postJson("/app/events/{$event->id}/attendees", ['attendees' => [$this->kussairi->id, $this->syakir->id]])
            ->assertSuccessful();
        $this->assertSame(2, WorkItem::query()->where('company_event_id', $event->id)->whereNull('archived_at')->count());
        $this->actingInTenantAs($this->kussairi)
            ->postJson("/app/events/{$event->id}/attendees", ['attendees' => [$this->kussairi->id, $this->syakir->id, $this->staff->id]])
            ->assertSuccessful();
        $this->assertSame(3, WorkItem::query()->where('company_event_id', $event->id)->whereNull('archived_at')->count());

        // Per-attendee status on the event page.
        $this->actingInTenantAs($this->syakir)->post("/app/events/{$event->id}/rsvp", ['response' => 'registered'])->assertSessionHasNoErrors();
        $this->actingInTenantAs($this->hr)->post("/app/events/{$event->id}/rsvp", ['response' => 'attended', 'employee_id' => $this->kussairi->id])->assertSessionHasNoErrors();
        $page = $this->actingInTenantAs($this->staff)->get("/app/events/{$event->id}");
        $page->assertOk();
        $page->assertSee(self::TITLE);
        $page->assertSeeInOrder(['Kussairi', 'Attended']);
        $page->assertSeeInOrder(['Syakir', 'Registered']);
        $page->assertSeeInOrder(['Shazwan', 'Going']);

        $this->assertTrue(AuditLog::query()->where('target', 'like', '%HPE AI Developer Days%')->where('action', 'like', '%attendee%')->exists(), 'setting attendees is audited');
    }

    // ── 2. the post-event page unlocks after the end time ───────────

    public function test_acceptance_2_after_27_aug_3_45_pm_the_event_page_shows_photos_comments_and_lessons(): void
    {
        $event = $this->hpeEvent();
        $this->setAttendees($event, [$this->kussairi->id, $this->syakir->id]);

        Carbon::setTestNow('2026-08-27 15:00:00');
        $before = $this->actingInTenantAs($this->syakir)->get("/app/events/{$event->id}");
        $before->assertOk();
        $before->assertSee(self::TITLE);
        $before->assertDontSee('data-event-tab=', false);
        $before->assertDontSee('Lessons learnt');

        Carbon::setTestNow('2026-08-27 16:00:00');
        $after = $this->actingInTenantAs($this->syakir)->get("/app/events/{$event->id}");
        $after->assertOk();
        $after->assertSee('data-event-tab="photos"', false);
        $after->assertSee('data-event-tab="comments"', false);
        $after->assertSee('data-event-tab="lessons"', false);
        $after->assertSeeInOrder(['Photos', 'Comments', 'Lessons learnt']);

        // Posting before the end is refused.
        Carbon::setTestNow('2026-08-27 15:00:00');
        $this->actingInTenantAs($this->syakir)
            ->postJson("/app/events/{$event->id}/lessons", ['learnt' => 'Too early'])
            ->assertStatus(422);
        $this->assertSame(0, DB::table('event_lessons')->count());
    }

    // ── 3. photos + lesson, visible to all, searchable in Knowledge ──

    public function test_acceptance_3_syakir_uploads_three_photos_and_a_lesson_everyone_sees_and_knowledge_finds(): void
    {
        Storage::fake('local');
        $event = $this->hpeEvent();
        $this->setAttendees($event, [$this->kussairi->id, $this->syakir->id]);
        Carbon::setTestNow('2026-08-28 09:00:00');

        // A non-attendee cannot post photos or a lesson.
        $this->actingInTenantAs($this->staff)
            ->post("/app/events/{$event->id}/photos", ['photos' => [UploadedFile::fake()->image('nope.jpg', 200, 200)], 'captions' => ['nope']])
            ->assertStatus(403);
        $this->actingInTenantAs($this->staff)
            ->postJson("/app/events/{$event->id}/lessons", ['learnt' => 'Nope'])
            ->assertStatus(403);

        $this->actingInTenantAs($this->syakir)
            ->post("/app/events/{$event->id}/photos", [
                'photos' => [
                    UploadedFile::fake()->image('keynote.jpg', 400, 300),
                    UploadedFile::fake()->image('lab.jpg', 400, 300),
                    UploadedFile::fake()->image('booth.jpg', 400, 300),
                ],
                'captions' => ['Keynote on agentic AI', 'Hands-on lab', 'HPE booth'],
            ])
            ->assertSessionHasNoErrors();

        $photos = DB::table('event_photos')->where('company_event_id', $event->id)->orderBy('sort_order')->orderBy('id')->get();
        $this->assertCount(3, $photos);
        $this->assertSame([$this->syakir->id, $this->syakir->id, $this->syakir->id], $photos->pluck('employee_id')->map(fn ($id) => (int) $id)->all());
        $this->assertSame(['Keynote on agentic AI', 'Hands-on lab', 'HPE booth'], $photos->pluck('caption')->all());
        foreach ($photos as $photo) {
            Storage::disk('local')->assertExists($photo->path);
            $this->actingInTenantAs($this->staff)->get("/app/events/photos/{$photo->id}")->assertOk();
        }

        $lesson = [
            'learnt' => 'Guardrails belong in the platform, not in every prompt',
            'how_to_use' => 'Centralise the guardrail layer for RMS and AmanahKu',
            'links' => ['https://developer.hpe.com/ai-days-2026/slides'],
        ];
        $this->actingInTenantAs($this->syakir)
            ->postJson("/app/events/{$event->id}/lessons", $lesson)
            ->assertSuccessful();
        $this->assertSame(1, DB::table('event_lessons')->where('company_event_id', $event->id)->where('employee_id', $this->syakir->id)->count());

        // Everyone sees the photos and the lesson on the event page.
        $page = $this->actingInTenantAs($this->staff)->get("/app/events/{$event->id}");
        $page->assertOk();
        $page->assertSee('Keynote on agentic AI');
        $page->assertSee('Hands-on lab');
        $page->assertSee('HPE booth');
        $page->assertSee("/app/events/photos/{$photos[0]->id}", false);
        $page->assertSee('Guardrails belong in the platform, not in every prompt');
        $page->assertSee('Centralise the guardrail layer for RMS and AmanahKu');
        $page->assertSee('https://developer.hpe.com/ai-days-2026/slides');
        $page->assertSeeInOrder(['Guardrails belong in the platform', 'Syakir']);

        // ... and Knowledge finds it.
        $entry = DB::table('knowledge_entries')->where('employee_id', $this->syakir->id)->first();
        $this->assertNotNull($entry, 'the lesson was not mirrored into Knowledge');
        $this->assertStringContainsString('Guardrails belong in the platform', $entry->body);
        $this->assertSame('Events', DB::table('knowledge_segments')->where('id', $entry->seg_id)->value('label'));
        $this->actingInTenantAs($this->staff)->get('/app/knowledge-bank?q=Guardrails+belong')
            ->assertOk()
            ->assertSee('Guardrails belong in the platform');

        // Saving again updates the same lesson and the same Knowledge entry.
        $this->actingInTenantAs($this->syakir)
            ->postJson("/app/events/{$event->id}/lessons", ['learnt' => 'Guardrails belong in the platform, not in every prompt (v2)'] + $lesson)
            ->assertSuccessful();
        $this->assertSame(1, DB::table('event_lessons')->where('company_event_id', $event->id)->count());
        $this->assertSame(1, DB::table('knowledge_entries')->where('employee_id', $this->syakir->id)->count());
        $this->assertStringContainsString('(v2)', DB::table('knowledge_entries')->where('employee_id', $this->syakir->id)->value('body'));

        // Others comment, reply and react.
        $lessonId = DB::table('event_lessons')->where('company_event_id', $event->id)->value('id');
        $comment = $this->actingInTenantAs($this->staff)
            ->postJson("/app/events/{$event->id}/comments", ['body' => 'Which guardrail library did they show?'])
            ->assertSuccessful();
        $commentId = $comment->json('id') ?? DB::table('event_comments')->where('company_event_id', $event->id)->value('id');
        $this->actingInTenantAs($this->syakir)
            ->postJson("/app/events/{$event->id}/comments", ['body' => 'NeMo Guardrails, slides linked above.', 'parent_id' => $commentId])
            ->assertSuccessful();
        $this->assertSame(2, DB::table('event_comments')->where('company_event_id', $event->id)->count());

        $key = $this->actingInTenantAs($this->staff)->getJson('/app/reactions')->json('reactions.0.key');
        $this->assertNotNull($key);
        $this->actingInTenantAs($this->staff)
            ->postJson("/app/events/{$event->id}/lessons/{$lessonId}/react", ['reaction' => $key])
            ->assertSuccessful()
            ->assertJsonPath("reactions.{$key}", 1);
        $this->assertSame(1, DB::table('event_reactions')->where('lesson_id', $lessonId)->count());
        $this->actingInTenantAs($this->staff)
            ->postJson("/app/events/{$event->id}/react", ['reaction' => 'not-a-key'])
            ->assertStatus(422);

        $page = $this->actingInTenantAs($this->kussairi)->get("/app/events/{$event->id}");
        $page->assertOk();
        $page->assertSee('Which guardrail library did they show?');
        $page->assertSee('NeMo Guardrails, slides linked above.');

        $this->assertTrue(AuditLog::query()->where('target', 'like', '%HPE AI Developer Days%')->where('action', 'like', '%lesson%')->exists(), 'writing a lesson is audited');
    }

    // ── 4. removing an attendee removes the card and the calendar event ──

    public function test_acceptance_4_removing_syakir_removes_his_card_and_calendar_event_and_leaves_kussairi_alone(): void
    {
        $event = $this->hpeEvent();
        $this->setAttendees($event, [$this->kussairi->id, $this->syakir->id]);
        $syakirCard = WorkItem::query()->where('company_event_id', $event->id)->where('employee_id', $this->syakir->id)->firstOrFail();
        $kussairiCard = WorkItem::query()->where('company_event_id', $event->id)->where('employee_id', $this->kussairi->id)->firstOrFail();
        $externalId = $syakirCard->google_event_id;
        $this->assertNotNull($externalId);

        $this->actingInTenantAs($this->hr)
            ->postJson("/app/events/{$event->id}/attendees", ['attendees' => [$this->kussairi->id]])
            ->assertSuccessful();

        $this->assertSame(0, DB::table('event_rsvps')->where('company_event_id', $event->id)->where('employee_id', $this->syakir->id)->count());
        $this->assertSame(1, DB::table('event_rsvps')->where('company_event_id', $event->id)->where('employee_id', $this->kussairi->id)->count());

        $syakirCard->refresh();
        $this->assertNotNull($syakirCard->archived_at, 'the card leaves the board, the row stays for history');
        $this->assertNull($kussairiCard->fresh()->archived_at);
        $this->actingInTenantAs($this->syakir)->get('/app/board')->assertOk()->assertDontSee(self::TITLE);
        $this->actingInTenantAs($this->kussairi)->get('/app/board')->assertOk()->assertSee(self::TITLE);

        $deletes = DB::table('port_outbox')->where('port', 'calendar')->where('method', 'deleteEvent')->get();
        $this->assertCount(1, $deletes);
        $payload = json_decode($deletes[0]->payload, true);
        $this->assertSame($this->syakir->id, (int) $payload['for_employee_id']);
        $this->assertSame($externalId, $payload['external_id']);
        $this->assertSame('sent', $deletes[0]->status);

        $page = $this->actingInTenantAs($this->staff)->get("/app/events/{$event->id}");
        $page->assertOk();
        $page->assertSee('Kussairi');
        $page->assertDontSee('Syakir');
    }

    // ── scope 2: rescheduling moves every card and re-upserts the same calendar id ──

    public function test_scope_2_rescheduling_the_event_moves_the_cards_and_updates_the_same_calendar_events(): void
    {
        $event = $this->hpeEvent();
        $this->setAttendees($event, [$this->kussairi->id, $this->syakir->id]);
        $ids = WorkItem::query()->where('company_event_id', $event->id)->pluck('google_event_id', 'employee_id');

        $this->actingInTenantAs($this->hr)
            ->post("/app/events/{$event->id}", $this->eventFields(['starts_at' => '2026-09-15 09:00', 'ends_at' => '2026-09-15 15:45', 'event_date' => '2026-09-15']))
            ->assertSessionHasNoErrors();

        $event->refresh();
        $this->assertSame('2026-09-15', $event->event_date->format('Y-m-d'));
        $this->assertSame('2026-09-15 09:00', Carbon::parse($event->starts_at)->format('Y-m-d H:i'));

        $cards = WorkItem::query()->where('company_event_id', $event->id)->get();
        $this->assertCount(2, $cards, 'a reschedule never makes a second card');
        foreach ($cards as $card) {
            $this->assertSame('2026-09-15', $card->due_at?->format('Y-m-d'));
            $this->assertSame($ids[$card->employee_id], $card->google_event_id, 'the calendar id never changes');
        }

        $upserts = DB::table('port_outbox')->where('port', 'calendar')->where('method', 'upsertEvent')->orderBy('id')->get();
        $this->assertCount(4, $upserts);
        foreach ($upserts->slice(2) as $row) {
            $payload = json_decode($row->payload, true);
            $this->assertStringStartsWith('2026-09-15T09:00', $payload['starts_at']);
            $this->assertSame($ids[(int) $payload['for_employee_id']], $payload['external_id']);
        }

        // An Event card's date is not locked (dates contract Rule 2), unlike a Task's.
        $card = $cards->firstWhere('employee_id', $this->kussairi->id);
        $this->actingInTenantAs($this->kussairi)
            ->patchJson("/app/board/{$card->id}", ['due_at' => '2026-09-16'])
            ->assertSuccessful();
        $this->assertSame('2026-09-16', $card->fresh()->due_at?->format('Y-m-d'));
    }

    // ── scope 6: the Events dashboard card ──────────────────────────

    public function test_scope_6_everyone_sees_the_events_card_on_the_dashboard_with_who_is_going(): void
    {
        $event = $this->hpeEvent();
        $this->setAttendees($event, [$this->kussairi->id, $this->syakir->id]);

        Carbon::setTestNow('2026-08-27 08:00:00');
        foreach ([$this->kussairi, $this->staff] as $viewer) {
            $dash = $this->actingInTenantAs($viewer)->get('/app/dash');
            $dash->assertOk();
            $dash->assertSee('data-widget="events"', false);
            $dash->assertSee(self::TITLE);
            $dash->assertSee('Kussairi');
            $dash->assertSee('Syakir');
            $dash->assertSee("/app/events/{$event->id}", false);
        }

        // Keep it plain: still there, text only.
        $this->actingInTenantAs($this->staff)->postJson('/app/dashboard/prefs', ['plain' => true])->assertOk();
        $plain = $this->actingInTenantAs($this->staff)->get('/app/dash');
        $plain->assertOk();
        $plain->assertSee('data-widget="events"', false);
        $plain->assertSee(self::TITLE);

        // A week after the end the card is gone again.
        Carbon::setTestNow('2026-09-05 09:00:00');
        $later = $this->actingInTenantAs($this->kussairi)->get('/app/dash');
        $later->assertOk();
        $later->assertDontSee('data-widget="events"', false);
    }

    // ── every-session checks ────────────────────────────────────────

    public function test_always_checks(): void
    {
        $this->assertDueDateLocked();
        $this->assertAuditLogImmutable();
        $this->assertDashboardUnchanged();
        $this->assertKeepItPlainHonoured();
    }

    // ── helpers ──────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function eventFields(array $overrides = []): array
    {
        return array_merge([
            'title' => self::TITLE,
            'type' => 'training',
            'event_date' => '2026-08-27',
            'starts_at' => '2026-08-27 09:00',
            'ends_at' => '2026-08-27 15:45',
            'location' => 'HPE Malaysia, Level 23A, Menara Suezcap 2, Kuala Lumpur',
            'host' => 'HPE',
            'registration_url' => 'https://developer.hpe.com/ai-days-2026/register',
            'description' => 'A full day on agentic AI with hands-on labs.',
        ], $overrides);
    }

    private function hpeEvent(): CompanyEvent
    {
        $this->actingInTenantAs($this->hr)
            ->post('/app/events', $this->eventFields())
            ->assertSessionHasNoErrors();

        $event = CompanyEvent::query()->where('title', self::TITLE)->firstOrFail();
        $this->assertSame('2026-08-27 15:45', Carbon::parse($event->ends_at)->format('Y-m-d H:i'));

        return $event;
    }

    /** @param list<int> $ids */
    private function setAttendees(CompanyEvent $event, array $ids): void
    {
        $this->actingInTenantAs($this->hr)
            ->postJson("/app/events/{$event->id}/attendees", ['attendees' => $ids])
            ->assertSuccessful();
    }
}
