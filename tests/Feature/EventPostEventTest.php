<?php

namespace Tests\Feature;

use App\Models\CompanyEvent;
use App\Models\Employee;
use App\Models\EventLesson;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Photos/comments/lessons governance, the Knowledge mirror's update-in-place behaviour,
 * and the events dashboard widget's visibility window + "keep it plain" mode. The full
 * happy path is already pinned by tests/Acceptance/CR11Test.php; this file targets what
 * that leaves loose.
 */
class EventPostEventTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
    }

    private function person(string $name, string $role = 'employee'): Employee
    {
        $user = User::create(['name' => $name, 'email' => strtolower($name).'@example.com', 'password' => Hash::make('password')]);
        $user->tenants()->attach($this->tenant->id, ['role' => $role]);

        return Employee::create(['tenant_id' => $this->tenant->id, 'user_id' => $user->id, 'name' => $name, 'status' => 'active', 'workload' => 'green']);
    }

    private function actingInTenantAs(Employee $employee): static
    {
        $this->actingAs($employee->user)->withSession(['current_tenant' => $this->tenant->id]);

        return $this;
    }

    private function pastEvent(): CompanyEvent
    {
        return CompanyEvent::create([
            'tenant_id' => $this->tenant->id,
            'title' => 'Ops Retro',
            'type' => 'meeting',
            'event_date' => '2026-07-01',
            'starts_at' => '2026-07-01 09:00',
            'ends_at' => '2026-07-01 11:00',
            'location' => 'Room 4',
        ]);
    }

    #[Test]
    public function only_an_attendee_may_upload_a_photo_or_a_lesson_after_the_event_has_ended(): void
    {
        Storage::fake('local');
        Carbon::setTestNow('2026-07-02 09:00:00');
        $hr = $this->person('HR', 'hr');
        $attendee = $this->person('Attendee');
        $outsider = $this->person('Outsider');
        $event = $this->pastEvent();
        $this->actingInTenantAs($hr)->postJson("/app/events/{$event->id}/attendees", ['attendees' => [$attendee->id]])->assertSuccessful();

        $this->actingInTenantAs($outsider)
            ->post("/app/events/{$event->id}/photos", ['photos' => [UploadedFile::fake()->image('x.jpg')]])
            ->assertStatus(403);
        $this->actingInTenantAs($outsider)
            ->postJson("/app/events/{$event->id}/lessons", ['learnt' => 'nope'])
            ->assertStatus(403);

        $this->actingInTenantAs($attendee)
            ->post("/app/events/{$event->id}/photos", ['photos' => [UploadedFile::fake()->image('x.jpg')]])
            ->assertSessionHasNoErrors();
        $this->assertSame(1, DB::table('event_photos')->where('company_event_id', $event->id)->count());
    }

    #[Test]
    public function anybody_may_comment_even_a_non_attendee(): void
    {
        Carbon::setTestNow('2026-07-02 09:00:00');
        $hr = $this->person('HR', 'hr');
        $outsider = $this->person('Outsider');
        $event = $this->pastEvent();

        $this->actingInTenantAs($outsider)
            ->postJson("/app/events/{$event->id}/comments", ['body' => 'Nice session!'])
            ->assertSuccessful();

        $this->assertSame(1, DB::table('event_comments')->where('company_event_id', $event->id)->count());
    }

    #[Test]
    public function saving_a_lesson_twice_updates_the_same_knowledge_entry_not_a_second_one(): void
    {
        Carbon::setTestNow('2026-07-02 09:00:00');
        $hr = $this->person('HR', 'hr');
        $attendee = $this->person('Attendee');
        $event = $this->pastEvent();
        $this->actingInTenantAs($hr)->postJson("/app/events/{$event->id}/attendees", ['attendees' => [$attendee->id]])->assertSuccessful();

        $this->actingInTenantAs($attendee)
            ->postJson("/app/events/{$event->id}/lessons", ['learnt' => 'First cut'])
            ->assertSuccessful();
        $lesson = EventLesson::where('company_event_id', $event->id)->where('employee_id', $attendee->id)->firstOrFail();
        $this->assertNotNull($lesson->knowledge_entry_id);
        $firstEntryId = $lesson->knowledge_entry_id;

        $this->actingInTenantAs($attendee)
            ->postJson("/app/events/{$event->id}/lessons", ['learnt' => 'Revised cut'])
            ->assertSuccessful();

        $this->assertSame(1, EventLesson::where('company_event_id', $event->id)->count());
        $this->assertSame(1, DB::table('knowledge_entries')->where('employee_id', $attendee->id)->count());
        $this->assertSame($firstEntryId, $lesson->fresh()->knowledge_entry_id);
        $this->assertStringContainsString('Revised cut', DB::table('knowledge_entries')->find($firstEntryId)->body);
    }

    #[Test]
    public function posting_a_lesson_before_the_event_ends_is_refused(): void
    {
        Carbon::setTestNow('2026-06-30 09:00:00');
        $hr = $this->person('HR', 'hr');
        $attendee = $this->person('Attendee');
        $event = $this->pastEvent();
        $this->actingInTenantAs($hr)->postJson("/app/events/{$event->id}/attendees", ['attendees' => [$attendee->id]])->assertSuccessful();

        $this->actingInTenantAs($attendee)
            ->postJson("/app/events/{$event->id}/lessons", ['learnt' => 'Too soon'])
            ->assertStatus(422);
    }

    #[Test]
    public function the_events_widget_only_shows_within_a_few_days_of_the_event_and_respects_keep_it_plain(): void
    {
        $hr = $this->person('HR', 'hr');
        $staff = $this->person('Staff');
        $event = CompanyEvent::create([
            'tenant_id' => $this->tenant->id, 'title' => 'Ops Retro', 'type' => 'meeting',
            'event_date' => '2026-08-10', 'starts_at' => '2026-08-10 09:00', 'ends_at' => '2026-08-10 11:00',
            'location' => 'Room 4',
        ]);
        $this->actingInTenantAs($hr)->postJson("/app/events/{$event->id}/attendees", ['attendees' => [$staff->id]])->assertSuccessful();

        // Outside the "upcoming or just-past" window (dashboard-slots.md), absent entirely.
        Carbon::setTestNow('2026-07-01 09:00:00');
        $this->actingInTenantAs($staff)->get('/app/dash')->assertOk()->assertDontSee('data-widget="events"', false);

        Carbon::setTestNow('2026-08-10 08:00:00');
        $this->actingInTenantAs($staff)->get('/app/dash')->assertOk()->assertSee('data-widget="events"', false);

        $this->actingInTenantAs($staff)->postJson('/app/dashboard/prefs', ['plain' => true])->assertOk();
        $this->actingInTenantAs($staff)->get('/app/dash')->assertOk()
            ->assertSee('data-widget="events"', false)
            ->assertSee('Ops Retro');
    }

    /** QA F7/F8 (CR-11 scope 3): the page carries a reply form per comment and live reaction buttons. */
    #[Test]
    public function the_event_page_offers_a_reply_form_and_live_reaction_buttons(): void
    {
        Carbon::setTestNow('2026-07-02 09:00:00');
        $hr = $this->person('HR', 'hr');
        $event = $this->pastEvent();
        $this->actingInTenantAs($hr)->postJson("/app/events/{$event->id}/attendees", ['attendees' => [$hr->id]])->assertSuccessful();
        $comment = $this->actingInTenantAs($hr)->postJson("/app/events/{$event->id}/comments", ['body' => 'First'])->json('id');
        $this->actingInTenantAs($hr)->postJson("/app/events/{$event->id}/lessons", ['learnt' => 'Something'])->assertSuccessful();

        $this->actingInTenantAs($hr)->get("/app/events/{$event->id}")
            ->assertOk()
            ->assertSee('data-js="event-reply-form"', false)
            ->assertSee('name="parent_id" value="'.$comment.'"', false)
            ->assertSee('button[data-react-url]', false)
            ->assertSee('data-reaction-key=', false);
    }
}
