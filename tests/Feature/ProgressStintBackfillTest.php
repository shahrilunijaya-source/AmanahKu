<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Tenant;
use App\Models\WorkItemProgressStint;
use App\Timesheet\BoardSuggestions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A stint is only opened when a card MOVES into In Progress or In Review, so every card
 * already parked in one of those columns when this shipped has no stint and is invisible
 * to the timesheet. The backfill starts their clock at deploy time.
 */
class ProgressStintBackfillTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
    }

    private function runBackfill(): void
    {
        $migration = require base_path('database/migrations/2026_08_31_121505_backfill_progress_stints_for_live_cards.php');
        $migration->up();
    }

    /**
     * Cards created through the model open their own stint via the observer, which is
     * exactly what the backfill exists to work around — so the pre-migration state has
     * to be written straight to the table.
     */
    private function parkedCard(string $title, string $status, ?Employee $owner = null): int
    {
        return DB::table('work_items')->insertGetId([
            'tenant_id' => $this->tenant->id,
            'employee_id' => $owner?->id,
            'title' => $title,
            'type' => 'task',
            'priority' => 'medium',
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_it_starts_the_clock_on_cards_already_in_progress_or_review(): void
    {
        $prog = $this->parkedCard('Parked in progress', 'prog');
        $review = $this->parkedCard('Parked in review', 'review');

        $this->runBackfill();

        foreach ([$prog, $review] as $id) {
            $this->assertDatabaseHas('work_item_progress_stints', [
                'work_item_id' => $id,
                'tenant_id' => $this->tenant->id,
                'ended_at' => null,
            ]);
        }
    }

    public function test_it_leaves_alone_the_cards_that_are_not_being_worked(): void
    {
        $todo = $this->parkedCard('Not started', 'todo');
        $done = $this->parkedCard('Finished', 'done');

        $archived = $this->parkedCard('Parked then archived', 'prog');
        DB::table('work_items')->where('id', $archived)->update(['archived_at' => now()]);

        $this->runBackfill();

        foreach ([$todo, $done, $archived] as $id) {
            $this->assertDatabaseMissing('work_item_progress_stints', ['work_item_id' => $id]);
        }
    }

    public function test_it_does_not_double_count_a_card_whose_clock_is_already_running(): void
    {
        $card = $this->parkedCard('Moved between deploy and migration', 'prog');
        WorkItemProgressStint::create([
            'tenant_id' => $this->tenant->id,
            'work_item_id' => $card,
            'started_at' => now()->subDay(),
        ]);

        $this->runBackfill();
        $this->runBackfill();

        $this->assertSame(1, WorkItemProgressStint::withoutGlobalScope('tenant')
            ->where('work_item_id', $card)->count());
    }

    /**
     * The point of the whole thing: after the backfill the card actually reaches the
     * capture grid. The stint it writes starts on the backfill day, and a stint that
     * touches the week offers the card on every working day of that week up to today
     * (1.7.4), so Monday and Tuesday get it too, and the days still ahead do not.
     */
    public function test_a_backfilled_card_is_offered_on_the_whole_week_up_to_today(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-26 09:00:00')); // a Wednesday

        $employee = Employee::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Shazwan', 'status' => 'active', 'workload' => 'green',
        ]);
        $this->parkedCard('Parked in progress', 'prog', $employee);

        $this->runBackfill();

        $week = app(BoardSuggestions::class)->forWeek($employee, '2026-08-24');

        $this->assertArrayHasKey('2026-08-24', $week, 'The stint touches this week, so Monday is offered too.');
        $this->assertArrayHasKey('2026-08-26', $week);
        $this->assertArrayNotHasKey('2026-08-27', $week, 'Thursday is still ahead, nothing is offered for it yet.');
        $this->assertSame('Parked in progress', $week['2026-08-26'][0]['title']);

        Carbon::setTestNow();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }
}
