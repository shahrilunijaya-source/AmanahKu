<?php

namespace Tests\Feature;

use App\Models\CompanyEvent;
use App\Models\Employee;
use App\Models\EventRsvp;
use App\Models\KnowledgeContribution;
use App\Models\Tenant;
use App\Models\Ticket;
use App\Models\TotSession;
use App\Models\User;
use App\Models\WorkItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The sidebar dot for things addressed to a person by name — an assigned task, a ticket
 * parked on them, an event that tags them, the knowledge contribution they owe, their own
 * TOT slot coming up. Each case checks both that the dot appears AND that it clears, since
 * a dot that never goes away is the failure mode worth guarding.
 */
class NavAttentionDotsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
    }

    private function member(string $name, string $role = 'employee'): Employee
    {
        $this->seq++;
        $user = User::create(['name' => $name, 'email' => "user{$this->seq}@example.com", 'password' => Hash::make('password')]);
        $user->tenants()->attach($this->tenant->id, ['role' => $role]);

        return Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $user->id,
            'name' => $name, 'status' => 'active', 'workload' => 'green',
        ]);
    }

    private function dash(Employee $e): TestResponse
    {
        return $this->actingAs($e->user)->withSession(['current_tenant' => $this->tenant->id])->get('/app/dash');
    }

    /** The knowledge-bank dot is on for everyone until they log a lesson, so silence it. */
    private function settleKnowledge(Employee $e): void
    {
        KnowledgeContribution::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $e->id,
            'year' => (int) now()->year, 'month' => (int) now()->month, 'submitted' => true,
        ]);
    }

    public function test_a_task_someone_assigned_to_you_dots_the_board_until_you_start_it(): void
    {
        $me = $this->member('Me');
        $boss = $this->member('Boss', 'manager');
        $this->settleKnowledge($me);

        $this->dash($me)->assertOk()->assertDontSee('uj-nav-dot');

        $card = WorkItem::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $me->id,
            'title' => 'Write the report', 'status' => 'todo',
            'assigned_by_id' => $boss->id, 'assigned_at' => now(),
        ]);

        $this->dash($me)->assertOk()->assertSee('uj-nav-dot');

        // Picking it up is the acknowledgement — the dot goes.
        $card->update(['status' => 'prog']);
        $this->dash($me)->assertOk()->assertDontSee('uj-nav-dot');
    }

    public function test_a_card_you_made_yourself_never_dots(): void
    {
        $me = $this->member('Me');
        $this->settleKnowledge($me);

        WorkItem::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $me->id,
            'title' => 'My own note', 'status' => 'todo',
        ]);

        $this->dash($me)->assertOk()->assertDontSee('uj-nav-dot');
    }

    public function test_a_ticket_assigned_to_you_dots_helpdesk_until_it_is_picked_up(): void
    {
        $me = $this->member('Me', 'hr');
        $raiser = $this->member('Raiser');
        $this->settleKnowledge($me);

        $this->dash($me)->assertOk()->assertDontSee('uj-nav-dot');

        $ticket = Ticket::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $raiser->id,
            'assignee_employee_id' => $me->id, 'subject' => 'Laptop dead',
            'description' => 'Will not boot.', 'category' => 'IT', 'status' => 'open',
        ]);

        $this->dash($me)->assertOk()->assertSee('uj-nav-dot');

        $ticket->update(['status' => 'in_progress']);
        $this->dash($me)->assertOk()->assertDontSee('uj-nav-dot');
    }

    public function test_an_event_that_tags_you_dots_until_you_rsvp(): void
    {
        $me = $this->member('Me');
        $this->settleKnowledge($me);

        $event = CompanyEvent::create([
            'tenant_id' => $this->tenant->id, 'title' => 'Townhall',
            'event_date' => now()->addWeek()->toDateString(),
            'tagged_employee_ids' => [$me->id],
        ]);

        $this->dash($me)->assertOk()->assertSee('uj-nav-dot');

        EventRsvp::create([
            'tenant_id' => $this->tenant->id, 'company_event_id' => $event->id,
            'employee_id' => $me->id, 'response' => 'going',
        ]);

        $this->dash($me)->assertOk()->assertDontSee('uj-nav-dot');
    }

    public function test_an_event_that_does_not_tag_you_is_silent(): void
    {
        $me = $this->member('Me');
        $other = $this->member('Other');
        $this->settleKnowledge($me);

        CompanyEvent::create([
            'tenant_id' => $this->tenant->id, 'title' => 'Townhall',
            'event_date' => now()->addWeek()->toDateString(),
            'tagged_employee_ids' => [$other->id],
        ]);

        $this->dash($me)->assertOk()->assertDontSee('uj-nav-dot');
    }

    public function test_a_past_event_is_silent(): void
    {
        $me = $this->member('Me');
        $this->settleKnowledge($me);

        CompanyEvent::create([
            'tenant_id' => $this->tenant->id, 'title' => 'Last month',
            'event_date' => now()->subMonth()->toDateString(),
            'tagged_employee_ids' => [$me->id],
        ]);

        $this->dash($me)->assertOk()->assertDontSee('uj-nav-dot');
    }

    public function test_the_knowledge_bank_dots_in_the_last_week_until_you_log_a_lesson(): void
    {
        $me = $this->member('Me');

        // Six days left in the month — the nudge window.
        $this->travelTo(now()->endOfMonth()->subDays(6)->setTime(9, 0));
        $this->dash($me)->assertOk()->assertSee('uj-nav-dot');

        $this->settleKnowledge($me);
        $this->dash($me)->assertOk()->assertDontSee('uj-nav-dot');
    }

    public function test_the_knowledge_bank_stays_quiet_earlier_in_the_month(): void
    {
        $me = $this->member('Me');

        // Owed but not yet due — mid-month is nobody's emergency.
        $this->travelTo(now()->startOfMonth()->addDays(9)->setTime(9, 0));
        $this->dash($me)->assertOk()->assertDontSee('uj-nav-dot');
    }

    public function test_your_own_tot_slot_dots_once_it_is_inside_a_fortnight(): void
    {
        $me = $this->member('Me');
        $this->settleKnowledge($me);

        // A slot far out is not yet anybody's problem.
        $far = now()->addMonths(4);
        $session = TotSession::create([
            'tenant_id' => $this->tenant->id, 'year' => (int) $far->year, 'month' => (int) $far->month,
            'status' => 'planned', 'presenter_employee_id' => $me->id,
        ]);
        $this->dash($me)->assertOk()->assertDontSee('uj-nav-dot');

        // Move it to whichever month holds a first Saturday inside the next fortnight.
        $soon = $this->slotInsideAFortnight();
        $session->update(['year' => $soon[0], 'month' => $soon[1]]);
        $this->dash($me)->assertOk()->assertSee('uj-nav-dot');

        // Once it has been delivered there is nothing left to prepare for.
        $session->update(['status' => 'done']);
        $this->dash($me)->assertOk()->assertDontSee('uj-nav-dot');
    }

    public function test_your_tot_slot_still_dots_on_the_day_itself(): void
    {
        $me = $this->member('Me');
        $this->settleKnowledge($me);

        // Pretend today IS the first Saturday of this month.
        $this->travelTo(TotSession::firstSaturday((int) now()->year, (int) now()->month)->setTime(9, 0));

        TotSession::create([
            'tenant_id' => $this->tenant->id, 'year' => (int) now()->year, 'month' => (int) now()->month,
            'status' => 'confirmed', 'presenter_employee_id' => $me->id,
        ]);

        $this->dash($me)->assertOk()->assertSee('uj-nav-dot');
    }

    public function test_somebody_elses_tot_slot_is_silent(): void
    {
        $me = $this->member('Me');
        $other = $this->member('Other');
        $this->settleKnowledge($me);

        $soon = $this->slotInsideAFortnight();
        TotSession::create([
            'tenant_id' => $this->tenant->id, 'year' => $soon[0], 'month' => $soon[1],
            'status' => 'planned', 'presenter_employee_id' => $other->id,
        ]);

        $this->dash($me)->assertOk()->assertDontSee('uj-nav-dot');
    }

    /**
     * A TOT slot is the first Saturday of its month, so "inside a fortnight" depends on
     * today's date, and on many real dates (any first Saturday itself, or the days right
     * after it) no slot falls in the window at all. Travel to a week before the next
     * first Saturday so the window always holds exactly that slot.
     *
     * @return array{int, int}
     */
    private function slotInsideAFortnight(): array
    {
        $cursor = now()->startOfMonth();
        for ($i = 0; $i < 24; $i++) {
            $date = TotSession::firstSaturday((int) $cursor->year, (int) $cursor->month);
            if ($date->isFuture()) {
                $this->travelTo($date->copy()->subWeek()->setTime(9, 0));

                return [(int) $cursor->year, (int) $cursor->month];
            }
            $cursor->addMonth();
        }

        $this->fail('No TOT slot falls in the next two years.');
    }
}
