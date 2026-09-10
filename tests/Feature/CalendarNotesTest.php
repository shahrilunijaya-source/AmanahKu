<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CalendarNote;
use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkItem;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Personal-tab additions on the dashboard calendar widget: private day notes
 * and the viewer's own open cards pinned to a day.
 */
class CalendarNotesTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        $this->travelTo(CarbonImmutable::parse('2026-09-10 10:00:00'));
    }

    private function signIn(string $name = 'Emysha'): Employee
    {
        $user = User::create(['name' => $name, 'email' => strtolower($name).'@acme.test', 'password' => Hash::make('password')]);
        $user->tenants()->attach($this->tenant->id, ['role' => 'employee']);
        $employee = Employee::create(['tenant_id' => $this->tenant->id, 'user_id' => $user->id, 'name' => $name, 'status' => 'active', 'workload' => 'green']);
        $this->actingAs($user)->withSession(['current_tenant' => $this->tenant->id]);

        return $employee;
    }

    private function card(Employee $employee, string $title, string $status = 'todo'): WorkItem
    {
        return WorkItem::create(['tenant_id' => $this->tenant->id, 'employee_id' => $employee->id, 'title' => $title, 'type' => 'task', 'priority' => 'medium', 'status' => $status, 'due_at' => '2026-09-20']);
    }

    public function test_a_note_is_saved_and_the_widget_comes_back_on_that_day(): void
    {
        $employee = $this->signIn();

        $response = $this->postJson('/app/dashboard/calendar-notes', [
            'date' => '2026-09-12', 'title' => 'Dentist', 'starts_at' => '09:00', 'ends_at' => '10:30', 'body' => 'Bring the card',
        ]);

        $response->assertOk();
        $response->assertSee('Dentist');
        $response->assertSee('09:00 – 10:30');
        $response->assertSee("sel: '2026-09-12'", false);

        $this->assertDatabaseHas('calendar_notes', ['employee_id' => $employee->id, 'title' => 'Dentist', 'body' => 'Bring the card']);
    }

    public function test_a_note_needs_a_title_and_an_end_after_its_start(): void
    {
        $this->signIn();

        $this->postJson('/app/dashboard/calendar-notes', ['date' => '2026-09-12'])
            ->assertStatus(422)->assertJsonValidationErrors('title');

        $this->postJson('/app/dashboard/calendar-notes', ['date' => '2026-09-12', 'title' => 'X', 'starts_at' => '10:00', 'ends_at' => '09:00'])
            ->assertStatus(422)->assertJsonValidationErrors('ends_at');
    }

    public function test_a_note_shows_on_the_dashboard_only_to_its_owner(): void
    {
        $owner = $this->signIn();
        CalendarNote::create(['tenant_id' => $this->tenant->id, 'employee_id' => $owner->id, 'date' => '2026-09-12', 'title' => 'Private thing']);

        $this->get('/app/dash')->assertOk()->assertSee('Private thing');

        $this->signIn('Ahmad');
        $this->get('/app/dash')->assertOk()->assertDontSee('Private thing');
    }

    public function test_a_note_can_be_edited_and_removed_by_its_owner_only(): void
    {
        $owner = $this->signIn();
        $note = CalendarNote::create(['tenant_id' => $this->tenant->id, 'employee_id' => $owner->id, 'date' => '2026-09-12', 'title' => 'Old']);

        $this->patchJson('/app/dashboard/calendar-notes/'.$note->id, ['title' => 'New', 'starts_at' => '14:00'])
            ->assertOk()->assertSee('New')->assertSee('14:00');
        $this->assertSame('New', $note->fresh()->title);

        $this->signIn('Ahmad');
        $this->patchJson('/app/dashboard/calendar-notes/'.$note->id, ['title' => 'Stolen'])->assertNotFound();
        $this->deleteJson('/app/dashboard/calendar-notes/'.$note->id)->assertNotFound();
        $this->assertSame('New', $note->fresh()->title);

        $this->actingAs($owner->user)->withSession(['current_tenant' => $this->tenant->id]);
        $this->deleteJson('/app/dashboard/calendar-notes/'.$note->id)->assertOk();
        $this->assertDatabaseMissing('calendar_notes', ['id' => $note->id]);
    }

    public function test_an_open_card_can_be_pinned_to_a_day_once(): void
    {
        $employee = $this->signIn();
        $card = $this->card($employee, 'Write the report');

        $this->postJson('/app/dashboard/calendar-notes', ['date' => '2026-09-15', 'work_item_id' => $card->id])
            ->assertOk()->assertSee('Write the report')->assertSee('Due 20 Sep');
        $this->postJson('/app/dashboard/calendar-notes', ['date' => '2026-09-15', 'work_item_id' => $card->id])->assertOk();

        $this->assertSame(1, CalendarNote::where('work_item_id', $card->id)->count());
    }

    public function test_only_your_own_open_cards_are_offered_or_accepted(): void
    {
        $employee = $this->signIn();
        $done = $this->card($employee, 'Finished already', 'done');
        $other = Employee::create(['tenant_id' => $this->tenant->id, 'name' => 'Ahmad', 'status' => 'active', 'workload' => 'green']);
        $theirs = $this->card($other, 'Somebody else card');
        $mine = $this->card($employee, 'Mine and open');

        $page = $this->get('/app/dash')->assertOk();
        $page->assertSee('Mine and open');
        $page->assertDontSee('Finished already');
        $page->assertDontSee('Somebody else card');

        $this->postJson('/app/dashboard/calendar-notes', ['date' => '2026-09-15', 'work_item_id' => $done->id])->assertNotFound();
        $this->postJson('/app/dashboard/calendar-notes', ['date' => '2026-09-15', 'work_item_id' => $theirs->id])->assertNotFound();
        $this->postJson('/app/dashboard/calendar-notes', ['date' => '2026-09-15', 'work_item_id' => $mine->id])->assertOk();
    }

    public function test_a_pin_whose_card_was_archived_falls_off_the_calendar(): void
    {
        $employee = $this->signIn();
        $card = $this->card($employee, 'Soon gone');
        CalendarNote::create(['tenant_id' => $this->tenant->id, 'employee_id' => $employee->id, 'work_item_id' => $card->id, 'date' => '2026-09-15']);

        $this->get('/app/dashboard/widget/calendar')->assertOk()->assertSee('Soon gone');

        $card->update(['archived_at' => now()]);

        $this->get('/app/dashboard/widget/calendar')->assertOk()->assertDontSee('Soon gone');
    }
}
