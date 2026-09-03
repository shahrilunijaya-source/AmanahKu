<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkItem;
use App\Models\WorkItemProgressStint;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * A card's time in the In Progress and In Review columns is the only record of which
 * days it was worked — the timesheet's prefill reads nothing else. In Review counts:
 * the card is out of the writer's hands, but reviewing it is still work. Moving between
 * those two columns changes nothing; only To Do, Done and archive stop the clock.
 */
class WorkItemProgressStintTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-08-26 09:00:00');

        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);

        $user = User::create(['name' => 'Demo', 'email' => 'demo@example.com', 'password' => Hash::make('password')]);
        $user->tenants()->attach($this->tenant->id, ['role' => 'employee']);
        $this->employee = Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $user->id,
            'name' => 'Demo', 'status' => 'active', 'workload' => 'green',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function card(array $attrs = []): WorkItem
    {
        return $this->employee->workItems()->create(array_merge([
            'tenant_id' => $this->tenant->id, 'title' => 'X', 'type' => 'task',
            'priority' => 'low', 'status' => 'todo', 'progress' => 0,
        ], $attrs));
    }

    public function test_moving_a_card_into_in_progress_opens_a_stint(): void
    {
        $card = $this->card();

        $card->update(['status' => 'prog']);

        $stints = WorkItemProgressStint::where('work_item_id', $card->id)->get();
        $this->assertCount(1, $stints);
        $this->assertNull($stints->first()->ended_at);
        $this->assertSame('2026-08-26 09:00:00', $stints->first()->started_at->toDateTimeString());
    }

    public function test_moving_a_card_out_of_the_worked_columns_closes_the_stint(): void
    {
        $card = $this->card(['status' => 'prog']);

        Carbon::setTestNow('2026-08-27 17:00:00');
        $card->update(['status' => 'done']);

        $stint = WorkItemProgressStint::where('work_item_id', $card->id)->sole();
        $this->assertSame('2026-08-27 17:00:00', $stint->ended_at->toDateTimeString());
    }

    public function test_moving_a_card_into_in_review_keeps_its_stint_open(): void
    {
        $card = $this->card(['status' => 'prog']);

        Carbon::setTestNow('2026-08-27 17:00:00');
        $card->update(['status' => 'review']);

        $stint = WorkItemProgressStint::where('work_item_id', $card->id)->sole();
        $this->assertNull($stint->ended_at);
    }

    public function test_moving_a_card_straight_into_in_review_opens_a_stint(): void
    {
        $card = $this->card();

        $card->update(['status' => 'review']);

        $stint = WorkItemProgressStint::where('work_item_id', $card->id)->sole();
        $this->assertNull($stint->ended_at);
    }

    public function test_a_card_that_bounces_back_gets_a_second_stint(): void
    {
        $card = $this->card(['status' => 'prog']);
        $card->update(['status' => 'todo']);

        Carbon::setTestNow('2026-08-28 09:00:00');
        $card->update(['status' => 'prog']);

        $stints = WorkItemProgressStint::where('work_item_id', $card->id)->orderBy('started_at')->get();
        $this->assertCount(2, $stints);
        $this->assertNotNull($stints[0]->ended_at);
        $this->assertNull($stints[1]->ended_at);
    }

    /**
     * The cards already sitting In Review when In Review started counting: their stint was
     * closed under the old rule when they left In Progress. Moving between the two worked
     * columns is not "entering" either of them, so nothing would reopen a stint and the card
     * would stay invisible to the timesheet forever. A move within the worked columns with
     * no stint running self-heals, the same way closeOpenStints() heals a dangling one.
     */
    public function test_a_move_between_the_worked_columns_with_no_stint_running_opens_one(): void
    {
        $card = $this->card(['status' => 'review']);
        WorkItemProgressStint::where('work_item_id', $card->id)->delete();

        Carbon::setTestNow('2026-08-28 09:00:00');
        $card->update(['status' => 'prog']);

        $stint = WorkItemProgressStint::where('work_item_id', $card->id)->sole();
        $this->assertNull($stint->ended_at);
        $this->assertSame('2026-08-28 09:00:00', $stint->started_at->toDateTimeString());
    }

    public function test_a_move_between_the_worked_columns_does_not_split_a_running_stint(): void
    {
        $card = $this->card(['status' => 'prog']);

        Carbon::setTestNow('2026-08-28 09:00:00');
        $card->update(['status' => 'review']);
        $card->update(['status' => 'prog']);

        $stint = WorkItemProgressStint::where('work_item_id', $card->id)->sole();
        $this->assertNull($stint->ended_at);
        $this->assertSame('2026-08-26 09:00:00', $stint->started_at->toDateTimeString());
    }

    public function test_creating_a_card_straight_into_in_progress_opens_a_stint(): void
    {
        $card = $this->card(['status' => 'prog']);

        $this->assertSame(1, WorkItemProgressStint::where('work_item_id', $card->id)->count());
    }

    public function test_archiving_an_in_progress_card_closes_its_stint(): void
    {
        $card = $this->card(['status' => 'prog']);

        Carbon::setTestNow('2026-08-28 12:00:00');
        $card->update(['archived_at' => Carbon::now()]);

        $stint = WorkItemProgressStint::where('work_item_id', $card->id)->sole();
        $this->assertSame('2026-08-28 12:00:00', $stint->ended_at->toDateTimeString());
    }

    public function test_a_card_closed_without_ever_being_worked_gets_a_one_day_stint(): void
    {
        $card = $this->card();

        Carbon::setTestNow('2026-08-27 15:00:00');
        $card->update(['status' => 'done']);

        $stint = WorkItemProgressStint::where('work_item_id', $card->id)->sole();
        $this->assertSame('2026-08-27 15:00:00', $stint->started_at->toDateTimeString());
        $this->assertSame('2026-08-27 15:00:00', $stint->ended_at->toDateTimeString());
    }

    public function test_creating_a_card_straight_into_done_gets_a_one_day_stint(): void
    {
        $card = $this->card(['status' => 'done']);

        $stint = WorkItemProgressStint::where('work_item_id', $card->id)->sole();
        $this->assertSame('2026-08-26 09:00:00', $stint->started_at->toDateTimeString());
        $this->assertSame('2026-08-26 09:00:00', $stint->ended_at->toDateTimeString());
    }

    public function test_closing_a_card_that_was_worked_before_adds_no_extra_stint(): void
    {
        $card = $this->card(['status' => 'prog']);
        $card->update(['status' => 'todo']);

        Carbon::setTestNow('2026-08-28 15:00:00');
        $card->update(['status' => 'done']);

        $this->assertSame(1, WorkItemProgressStint::where('work_item_id', $card->id)->count());
    }

    public function test_a_status_change_between_the_idle_columns_writes_no_stint(): void
    {
        $card = $this->card(['status' => 'done']);
        WorkItemProgressStint::where('work_item_id', $card->id)->delete();

        $card->update(['status' => 'todo']);

        $this->assertSame(0, WorkItemProgressStint::where('work_item_id', $card->id)->count());
    }

    public function test_a_stint_carries_the_cards_tenant_even_with_no_active_tenant_context(): void
    {
        $card = $this->card();

        app(CurrentTenant::class)->set(null);
        $card->update(['status' => 'prog']);

        $stint = WorkItemProgressStint::withoutGlobalScope('tenant')
            ->where('work_item_id', $card->id)->sole();
        $this->assertSame($this->tenant->id, $stint->tenant_id);
    }
}
