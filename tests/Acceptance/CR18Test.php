<?php

namespace Tests\Acceptance;

use App\Models\AuditLog;
use App\Models\CompanyEvent;
use App\Models\Employee;
use App\Models\EventRsvp;
use App\Models\Position;
use App\Models\Project;
use App\Models\PublicHoliday;
use App\Models\WorkItem;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Acceptance for docs/specs/CR-18.md (session S06, the recurring task engine and the
 * social-activity schedule). Items 3b, 3c and 3d are the spec's own sub-items.
 *
 * Shapes this file fixes for the generator (recorded in docs/build/OPEN.md):
 * - a schedule is a `recurring_tasks` row: `title`, `frequency` (weekly | monthly |
 *   every_n_months | yearly), `interval` (the N), `start_on`, `owner_employee_id` or
 *   `owner_position_title` (a role, resolved to the active non-archived employee whose
 *   Position title matches, at creation time of each occurrence), `tagged_employee_ids`
 *   (json, tagged as Helper), `project_id`, `priority`, `lead_days`, `subtasks` (json list
 *   of titles), `min_attended` (default 1), `paused_at`. One occurrence per period is a
 *   `recurring_task_occurrences` row: `recurring_task_id`, `period` ('YYYY-MM-DD' = the
 *   period's first day), `work_item_id` (null when skipped), `skipped_reason`.
 * - `php artisan work:recurring` creates every occurrence whose period has started, on the
 *   app clock, and is registered daily in the scheduler. It creates a card on the first
 *   working day on or after the period start (a weekend or a `public_holidays` row is not a
 *   working day) and never a second card for the same period. The card: type task, the
 *   schedule's title, priority, project, label `recurring`, owner resolved from the role,
 *   tagged people as `helper`, due on the last day of the period, subtasks from the template
 *   in order, each with the parent's due date.
 * - HR and management manage schedules at `GET /app/recurring` (the screen lists them),
 *   `POST /app/admin/recurring` (create), `POST /app/admin/recurring/{id}/skip {period, reason}`,
 *   `POST /app/admin/recurring/{id}/pause`, `POST /app/admin/recurring/{id}/resume`.
 *   Employees and managers get 403 on all of them. Create, skip, pause and resume each write
 *   an audit row.
 * - Linking the social event: `POST /app/board/{card}/link-event {company_event_id}` by someone
 *   who may edit the card stores the link on the parent card (`work_items.company_event_id`)
 *   and ticks the 'Create Event' subtask. It is 422 unless every active employee of the tenant
 *   has an `event_rsvps` row on that event.
 * - Events gain the CR-11 states early, in the smallest form: `company_events.status`
 *   (draft | approved | held | cancelled, default draft), `approved_at`,
 *   `approved_by_employee_id`, and `evidence_note` (text, the post-event evidence until CR-11
 *   builds photos and lessons). `event_rsvps.response` accepts `attended`.
 * - Done rule: moving a card that belongs to a schedule with a linked event to `done` is 422
 *   unless the event is approved (approved_at set by a manager tier or PM), status held, dated
 *   before today, has at least `min_attended` rsvps with response attended, and has evidence.
 * - Off-boarding: an archived owner (`employees.archived_at`) never receives a new occurrence;
 *   HR reassigns an open card with `POST /app/board/{card}/reassign {employee_id, reason}`,
 *   which writes an `employee_id` audit row. Employees get 403.
 */
class CR18Test extends TestCase
{
    use AlwaysChecks;
    use RefreshDatabase;

    private const SUBTASKS = [
        'Propose 2 to 3 ideas with budget',
        'Director approval',
        'Create Event in The Playground with all staff as attendees',
        'Run the activity',
        'Post photos and lessons learnt',
    ];

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    #[Test]
    public function test_acceptance_1_on_1_nov_the_task_appears_on_mns_board_tagged_and_due_30_nov(): void
    {
        [$hr, $mn, $admins] = $this->adminTeam();
        $project = $this->project();
        $this->socialSchedule($hr, $project);

        // Before the period starts nothing exists.
        Carbon::setTestNow('2026-10-31 09:00:00');
        Artisan::call('work:recurring');
        $this->assertSame(0, WorkItem::where('tenant_id', $this->tenant()->id)->count(), 'created before the 1st');

        Carbon::setTestNow('2026-11-01 06:00:00');
        Artisan::call('work:recurring');

        $card = WorkItem::where('tenant_id', $this->tenant()->id)->where('title', 'Organise company social activity')->first();
        $this->assertNotNull($card, 'no card on 1 Nov');
        $this->assertSame($mn->id, $card->employee_id, 'owner is not the Finance Manager');
        $this->assertSame('2026-11-30', $card->due_at?->format('Y-m-d'));
        $this->assertSame('task', $card->type);
        $this->assertSame('medium', $card->priority);
        $this->assertSame($project->id, $card->project_id);
        $this->assertContains('recurring', $card->labels ?? [], 'label Recurring missing');

        $tagged = DB::table('work_item_participant')->where('work_item_id', $card->id)->get();
        $this->assertEqualsCanonicalizing(collect($admins)->pluck('id')->all(), $tagged->pluck('employee_id')->all());
        $this->assertSame(['helper'], $tagged->pluck('role')->unique()->values()->all());

        // On MN's board, with the four visible as tagged.
        $board = $this->actingInTenantAs($mn)->get('/app/board')->assertOk();
        $board->assertSee('Organise company social activity');
        foreach ($admins as $a) {
            $board->assertSee($a->name);
        }

        // Written as an occurrence, audited as a creation by the engine.
        $this->assertDatabaseHas('recurring_task_occurrences', ['work_item_id' => $card->id, 'period' => '2026-11-01']);
        $this->assertTrue(
            AuditLog::where('tenant_id', $this->tenant()->id)->where('subject_type', WorkItem::class)->where('subject_id', $card->id)->exists(),
            'no audit row for the engine-created card'
        );
    }

    #[Test]
    public function test_acceptance_2_subtasks_are_prefilled_and_create_event_links_an_event_with_all_staff(): void
    {
        [$hr, $mn] = $this->adminTeam();
        $this->socialSchedule($hr, $this->project());
        $card = $this->occurrence('2026-11-01');

        $children = WorkItem::withoutGlobalScopes()->where('parent_id', $card->id)->orderBy('id')->get();
        $this->assertSame(self::SUBTASKS, $children->pluck('title')->all(), 'subtasks not pre-filled in order');
        foreach ($children as $child) {
            $this->assertSame('2026-11-30', $child->due_at?->format('Y-m-d'), 'subtask due date differs from the parent');
            $this->assertSame($mn->id, $child->employee_id);
        }
        $createEvent = $children->firstWhere('title', self::SUBTASKS[2]);

        // An event without everyone on it is refused.
        $partial = $this->event('Bowling night', '2026-11-20');
        EventRsvp::create(['tenant_id' => $this->tenant()->id, 'company_event_id' => $partial->id, 'employee_id' => $mn->id, 'response' => 'going']);
        $this->actingInTenantAs($mn)->postJson("/app/board/{$card->id}/link-event", ['company_event_id' => $partial->id])->assertStatus(422);
        $this->assertSame('todo', $createEvent->fresh()->status);

        // With all active staff as attendees it links and ticks the subtask.
        $full = $this->event('Bowling night', '2026-11-20');
        $this->rsvpEveryone($full);
        $this->actingInTenantAs($mn)->postJson("/app/board/{$card->id}/link-event", ['company_event_id' => $full->id])->assertOk();

        $this->assertSame($full->id, $card->fresh()->company_event_id);
        $this->assertSame('done', $createEvent->fresh()->status, "'Create Event' subtask not ticked by the link");
        $this->assertSame(
            Employee::where('tenant_id', $this->tenant()->id)->whereNull('archived_at')->count(),
            EventRsvp::where('company_event_id', $full->id)->count(),
            'not every staff member is an attendee'
        );

        // A stranger cannot link.
        $stranger = $this->person('Stranger');
        $this->actingInTenantAs($stranger)->postJson("/app/board/{$card->id}/link-event", ['company_event_id' => $full->id])->assertStatus(403);
    }

    #[Test]
    public function test_acceptance_3_only_approved_held_attended_evidenced_event_closes_the_task(): void
    {
        [$hr, $mn] = $this->adminTeam();
        $director = $this->person('Director', 'director');
        $this->socialSchedule($hr, $this->project());
        $card = $this->occurrence('2026-11-01');
        $event = $this->event('Bowling night', '2026-11-20');
        $this->rsvpEveryone($event);
        $this->actingInTenantAs($mn)->postJson("/app/board/{$card->id}/link-event", ['company_event_id' => $event->id])->assertOk();
        // Every other subtask ticked, so only the done rule stands between the card and Done.
        WorkItem::withoutGlobalScopes()->where('parent_id', $card->id)->update(['status' => 'done', 'done_at' => now()]);

        Carbon::setTestNow('2026-11-25 10:00:00');
        $attempt = fn () => $this->actingInTenantAs($mn)->postJson("/app/board/{$card->id}/move", ['status' => 'done']);

        // Draft, past-dated, no attendance.
        $attempt()->assertStatus(422);
        $this->assertNotSame('done', $card->fresh()->status);

        // Cancelled, even with attendance and evidence.
        $event->update(['status' => 'cancelled', 'approved_at' => now(), 'approved_by_employee_id' => $director->id, 'evidence_note' => 'Photos in Drive']);
        EventRsvp::where('company_event_id', $event->id)->update(['response' => 'attended']);
        $attempt()->assertStatus(422);

        // Approved + held, but nobody attended.
        $event->update(['status' => 'held']);
        EventRsvp::where('company_event_id', $event->id)->update(['response' => 'going']);
        $attempt()->assertStatus(422);

        // Approved + held + attended, but no evidence.
        EventRsvp::where('company_event_id', $event->id)->where('employee_id', $mn->id)->update(['response' => 'attended']);
        $event->update(['evidence_note' => null]);
        $attempt()->assertStatus(422);

        // Approved + held + attended + evidence, but the date has not passed yet.
        $event->update(['evidence_note' => 'Photos in Drive', 'event_date' => '2026-11-28']);
        $attempt()->assertStatus(422);

        // Held but never approved.
        $event->update(['event_date' => '2026-11-20', 'approved_at' => null, 'approved_by_employee_id' => null]);
        $attempt()->assertStatus(422);
        $this->assertNotSame('done', $card->fresh()->status, 'closed without the full rule');

        // All five conditions hold.
        $event->update(['approved_at' => now(), 'approved_by_employee_id' => $director->id]);
        $attempt()->assertOk();
        $this->assertSame('done', $card->fresh()->status);
        $this->assertNotNull($card->fresh()->done_at);
    }

    #[Test]
    public function test_acceptance_3b_a_departed_owner_is_replaced_by_the_current_finance_manager_and_hr_reassigns_the_open_card(): void
    {
        [$hr, $mn] = $this->adminTeam();
        $this->socialSchedule($hr, $this->project());
        $open = $this->occurrence('2026-11-01');
        $this->assertSame($mn->id, $open->employee_id);

        // MN leaves; a new Finance Manager holds the position.
        $mn->update(['status' => 'resigned', 'archived_at' => '2026-12-15 00:00:00']);
        $newFm = $this->person('New FM', 'manager', ['position_id' => $mn->position_id]);

        Carbon::setTestNow('2027-01-01 06:00:00');
        Artisan::call('work:recurring');
        $jan = $this->cardForPeriod('2027-01-01');
        $this->assertSame($newFm->id, $jan->employee_id, 'January occurrence did not go to the current Finance Manager');

        // The open November card still belongs to the departed owner until HR reassigns it.
        $this->assertSame($mn->id, $open->fresh()->employee_id);
        $staff = $this->person('Just Staff');
        $this->actingInTenantAs($staff)->postJson("/app/board/{$open->id}/reassign", ['employee_id' => $newFm->id, 'reason' => 'off-boarding'])->assertStatus(403);
        $this->actingInTenantAs($hr)->postJson("/app/board/{$open->id}/reassign", ['employee_id' => $newFm->id, 'reason' => 'off-boarding'])->assertOk();
        $this->assertSame($newFm->id, $open->fresh()->employee_id);
        $this->assertSame('2026-11-30', $open->fresh()->due_at?->format('Y-m-d'), 'reassignment moved the locked due date');
        $this->assertDatabaseHas('audit_logs', [
            'subject_type' => WorkItem::class, 'subject_id' => $open->id, 'field' => 'employee_id', 'new_value' => (string) $newFm->id,
        ]);
    }

    #[Test]
    public function test_acceptance_3c_skip_with_reason_and_pause_resume(): void
    {
        [$hr] = $this->adminTeam();
        $schedule = $this->socialSchedule($hr, $this->project());
        $staff = $this->person('Just Staff');
        $manager = $this->person('A Manager', 'manager');

        // Skip November with a reason, before it is created.
        Carbon::setTestNow('2026-10-20 10:00:00');
        $this->actingInTenantAs($staff)->postJson("/app/admin/recurring/{$schedule}/skip", ['period' => '2026-11-01', 'reason' => 'Year-end close'])->assertStatus(403);
        $this->actingInTenantAs($manager)->postJson("/app/admin/recurring/{$schedule}/skip", ['period' => '2026-11-01', 'reason' => 'Year-end close'])->assertStatus(403);
        $this->actingInTenantAs($hr)->postJson("/app/admin/recurring/{$schedule}/skip", ['period' => '2026-11-01'])->assertStatus(422);
        $this->actingInTenantAs($hr)->postJson("/app/admin/recurring/{$schedule}/skip", ['period' => '2026-11-01', 'reason' => 'Year-end close'])->assertOk();
        $this->assertDatabaseHas('recurring_task_occurrences', ['recurring_task_id' => $schedule, 'period' => '2026-11-01', 'work_item_id' => null, 'skipped_reason' => 'Year-end close']);
        $this->assertTrue(AuditLog::where('tenant_id', $this->tenant()->id)->where('action', 'like', '%kip%')->exists(), 'skip not audited');

        Carbon::setTestNow('2026-11-01 06:00:00');
        Artisan::call('work:recurring');
        Artisan::call('work:recurring');
        $this->assertNull($this->cardForPeriod('2026-11-01', false), 'a skipped period still created a card');

        // January is created as usual.
        Carbon::setTestNow('2027-01-01 06:00:00');
        Artisan::call('work:recurring');
        $this->assertNotNull($this->cardForPeriod('2027-01-01', false), 'the occurrence after a skip was not created');

        // Pause: nothing until Resume.
        $this->actingInTenantAs($staff)->postJson("/app/admin/recurring/{$schedule}/pause")->assertStatus(403);
        $this->actingInTenantAs($hr)->postJson("/app/admin/recurring/{$schedule}/pause")->assertOk();
        Carbon::setTestNow('2027-03-01 06:00:00');
        Artisan::call('work:recurring');
        $this->assertNull($this->cardForPeriod('2027-03-01', false), 'a paused schedule created a card');
        Carbon::setTestNow('2027-05-01 06:00:00');
        Artisan::call('work:recurring');
        $this->assertNull($this->cardForPeriod('2027-05-01', false));

        $this->actingInTenantAs($hr)->postJson("/app/admin/recurring/{$schedule}/resume")->assertOk();
        Carbon::setTestNow('2027-07-01 06:00:00');
        Artisan::call('work:recurring');
        $this->assertNotNull($this->cardForPeriod('2027-07-01', false), 'nothing created after resume');
        $this->assertNull($this->cardForPeriod('2027-03-01', false), 'resume back-filled a paused period');
        $this->assertTrue(AuditLog::where('tenant_id', $this->tenant()->id)->where('action', 'like', '%ause%')->exists(), 'pause not audited');
        $this->assertTrue(AuditLog::where('tenant_id', $this->tenant()->id)->where('action', 'like', '%esume%')->exists(), 'resume not audited');
    }

    #[Test]
    public function test_acceptance_3d_holiday_on_the_1st_moves_creation_to_the_next_working_day_and_never_doubles(): void
    {
        [$hr] = $this->adminTeam();
        $this->socialSchedule($hr, $this->project());
        // 1 Jan 2027 is a Friday and a public holiday; 2 and 3 Jan are the weekend.
        PublicHoliday::create(['tenant_id' => $this->tenant()->id, 'name' => "New Year's Day", 'date' => '2027-01-01']);

        Carbon::setTestNow('2027-01-01 06:00:00');
        Artisan::call('work:recurring');
        $this->assertNull($this->cardForPeriod('2027-01-01', false), 'created on a public holiday');
        Carbon::setTestNow('2027-01-02 06:00:00');
        Artisan::call('work:recurring');
        Carbon::setTestNow('2027-01-03 06:00:00');
        Artisan::call('work:recurring');
        $this->assertNull($this->cardForPeriod('2027-01-01', false), 'created on the weekend');

        Carbon::setTestNow('2027-01-04 06:00:00');
        Artisan::call('work:recurring');
        Artisan::call('work:recurring');
        Carbon::setTestNow('2027-01-05 06:00:00');
        Artisan::call('work:recurring');

        $cards = WorkItem::where('tenant_id', $this->tenant()->id)->where('title', 'Organise company social activity')->get();
        $this->assertCount(1, $cards, 'more than one card for the same period');
        $this->assertSame('2027-01-31', $cards->first()->due_at?->format('Y-m-d'), 'due date is not the end of the period');
        $this->assertSame(1, DB::table('recurring_task_occurrences')->where('period', '2027-01-01')->count());
    }

    #[Test]
    public function test_acceptance_4_on_1_jan_2027_the_next_occurrence_is_created_automatically(): void
    {
        [$hr, $mn] = $this->adminTeam();
        $this->socialSchedule($hr, $this->project());
        $nov = $this->occurrence('2026-11-01');
        // The November card stays open; the next one is created regardless.

        // Registered with the scheduler, daily, so nobody has to remember it.
        $events = collect(app(Schedule::class)->events())->filter(fn ($e) => str_contains($e->command ?? '', 'work:recurring'));
        $this->assertCount(1, $events, 'work:recurring is not in the scheduler');
        $this->assertStringContainsString('* * *', $events->first()->expression, 'work:recurring does not run daily');

        Carbon::setTestNow('2027-01-01 06:00:00');
        Artisan::call('work:recurring');

        $jan = $this->cardForPeriod('2027-01-01');
        $this->assertNotSame($nov->id, $jan->id);
        $this->assertSame($mn->id, $jan->employee_id);
        $this->assertSame('2027-01-31', $jan->due_at?->format('Y-m-d'));
        $this->assertSame('todo', $nov->fresh()->status, 'the previous occurrence was touched');
        $this->assertSame(5, WorkItem::withoutGlobalScopes()->where('parent_id', $jan->id)->count());

        // Even months never get one.
        Carbon::setTestNow('2027-02-01 06:00:00');
        Artisan::call('work:recurring');
        $this->assertSame(2, WorkItem::where('tenant_id', $this->tenant()->id)->where('title', 'Organise company social activity')->count());
    }

    #[Test]
    public function test_acceptance_5_hr_sets_up_a_second_recurring_task_on_the_same_screen(): void
    {
        [$hr, $mn] = $this->adminTeam();
        $this->socialSchedule($hr, $this->project());
        $safety = $this->person('Safety Officer');
        $staff = $this->person('Just Staff');

        $this->actingInTenantAs($staff)->get('/app/recurring')->assertStatus(403);
        $this->actingInTenantAs($staff)->postJson('/app/admin/recurring', $this->fireDrill($safety))->assertStatus(403);

        $screen = $this->actingInTenantAs($hr)->get('/app/recurring')->assertOk();
        $screen->assertSee('Organise company social activity');

        $this->actingInTenantAs($hr)->postJson('/app/admin/recurring', ['title' => 'Quarterly fire drill'])->assertStatus(422);
        $this->actingInTenantAs($hr)->postJson('/app/admin/recurring', $this->fireDrill($safety))->assertOk();
        $this->assertDatabaseHas('recurring_tasks', ['tenant_id' => $this->tenant()->id, 'title' => 'Quarterly fire drill', 'frequency' => 'every_n_months', 'interval' => 3]);
        $this->assertTrue(AuditLog::where('tenant_id', $this->tenant()->id)->where('action', 'like', '%ecurring%')->exists(), 'schedule creation not audited');

        $this->actingInTenantAs($hr)->get('/app/recurring')->assertOk()->assertSee('Quarterly fire drill');

        Carbon::setTestNow('2026-10-01 06:00:00');
        Artisan::call('work:recurring');
        $drill = WorkItem::where('tenant_id', $this->tenant()->id)->where('title', 'Quarterly fire drill')->first();
        $this->assertNotNull($drill, 'the second schedule created nothing');
        $this->assertSame($safety->id, $drill->employee_id);
        $this->assertSame('2026-10-31', $drill->due_at?->format('Y-m-d'));
        $this->assertSame(['Book the assembly point', 'Run the drill'], WorkItem::withoutGlobalScopes()->where('parent_id', $drill->id)->orderBy('id')->pluck('title')->all());
        $this->assertSame(0, WorkItem::where('tenant_id', $this->tenant()->id)->where('title', 'Organise company social activity')->count(), 'the social schedule fired outside its odd months');

        Carbon::setTestNow('2027-01-04 06:00:00');
        Artisan::call('work:recurring');
        $this->assertSame(2, WorkItem::where('tenant_id', $this->tenant()->id)->where('title', 'Quarterly fire drill')->count(), 'no January drill');
    }

    #[Test]
    public function test_always_the_four_cross_cutting_checks(): void
    {
        $this->assertDueDateLocked();
        $this->assertAuditLogImmutable();
        $this->assertDashboardUnchanged();
        $this->assertKeepItPlainHonoured();
    }

    // ── fixtures ────────────────────────────────────────────────────

    /** @return array{0: Employee, 1: Employee, 2: list<Employee>} HR, the Finance Manager, the four admin staff */
    private function adminTeam(): array
    {
        $hr = $this->person('Hidayah HR', 'hr');
        $fm = Position::create(['tenant_id' => $this->tenant()->id, 'title' => 'Finance Manager', 'status' => 'active']);
        $mn = $this->person('MN', 'manager', ['position_id' => $fm->id]);
        $admins = [
            $this->person('Ain Akilah'), $this->person('Alya'), $this->person('Aminah'), $this->person('Hidayah'),
        ];

        return [$hr, $mn, $admins];
    }

    private function project(): Project
    {
        return Project::create(['tenant_id' => $this->tenant()->id, 'code' => 'URSB', 'name' => 'URSB : Culture']);
    }

    /** The social-activity schedule as HR would set it up. Returns the schedule id. */
    private function socialSchedule(Employee $hr, Project $project): int
    {
        $tagged = Employee::where('tenant_id', $this->tenant()->id)->whereIn('name', ['Ain Akilah', 'Alya', 'Aminah', 'Hidayah'])->pluck('id')->all();

        $response = $this->actingInTenantAs($hr)->postJson('/app/admin/recurring', [
            'title' => 'Organise company social activity',
            'frequency' => 'every_n_months',
            'interval' => 2,
            'start_on' => '2026-11-01',
            'owner_position_title' => 'Finance Manager',
            'tagged_employee_ids' => $tagged,
            'project_id' => $project->id,
            'priority' => 'medium',
            'lead_days' => 0,
            'subtasks' => self::SUBTASKS,
            'min_attended' => 1,
        ])->assertOk();

        return (int) $response->json('id');
    }

    /** @return array<string, mixed> */
    private function fireDrill(Employee $owner): array
    {
        return [
            'title' => 'Quarterly fire drill',
            'frequency' => 'every_n_months',
            'interval' => 3,
            'start_on' => '2026-10-01',
            'owner_employee_id' => $owner->id,
            'tagged_employee_ids' => [],
            'priority' => 'low',
            'lead_days' => 0,
            'subtasks' => ['Book the assembly point', 'Run the drill'],
        ];
    }

    /** Runs the engine on the period's first day and returns the card it made. */
    private function occurrence(string $period): WorkItem
    {
        Carbon::setTestNow($period.' 06:00:00');
        Artisan::call('work:recurring');

        return $this->cardForPeriod($period);
    }

    private function cardForPeriod(string $period, bool $mustExist = true): ?WorkItem
    {
        $id = DB::table('recurring_task_occurrences')->where('period', $period)->whereNotNull('work_item_id')->value('work_item_id');
        $card = $id ? WorkItem::find($id) : null;
        if ($mustExist) {
            $this->assertNotNull($card, "no card for period {$period}");
        }

        return $card;
    }

    private function event(string $title, string $date): CompanyEvent
    {
        return CompanyEvent::create([
            'tenant_id' => $this->tenant()->id, 'title' => $title, 'type' => 'social', 'event_date' => $date,
        ]);
    }

    private function rsvpEveryone(CompanyEvent $event): void
    {
        foreach (Employee::where('tenant_id', $this->tenant()->id)->whereNull('archived_at')->get() as $e) {
            EventRsvp::firstOrCreate(
                ['company_event_id' => $event->id, 'employee_id' => $e->id],
                ['tenant_id' => $this->tenant()->id, 'response' => 'going'],
            );
        }
    }
}
