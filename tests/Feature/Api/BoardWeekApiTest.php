<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Employee;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\Timesheet;
use App\Models\TimesheetEntry;
use App\Models\User;
use App\Models\WorkItem;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * GET /api/v1/board-week — the per-project, per-day board feed Track pulls to fill
 * its Last Week card (CR-07): what was planned, what happened, and events, without a
 * PM retyping any of it.
 */
class BoardWeekApiTest extends TestCase
{
    use RefreshDatabase;

    private const WEEK = '2026-08-03'; // a Monday

    private Tenant $tenant;

    private User $hr;

    private User $staff;

    private Employee $employee;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['slug' => 'alpha', 'name' => 'Alpha', 'initials' => 'AL']);
        app(CurrentTenant::class)->set($this->tenant);

        $this->hr = User::create(['name' => 'HR Ann', 'email' => 'hr@example.com', 'password' => Hash::make('password')]);
        $this->hr->tenants()->attach($this->tenant->id, ['role' => 'hr']);
        $this->staff = User::create(['name' => 'Staff Sam', 'email' => 'staff@example.com', 'password' => Hash::make('password')]);
        $this->staff->tenants()->attach($this->tenant->id, ['role' => 'employee']);

        $this->employee = Employee::create(['tenant_id' => $this->tenant->id, 'name' => 'Ali', 'status' => 'active', 'workload' => 'green']);
        $this->project = Project::create(['tenant_id' => $this->tenant->id, 'code' => 'KPT', 'name' => 'KPT: RMS', 'is_active' => true]);

        app(CurrentTenant::class)->set(null);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        app(CurrentTenant::class)->set(null);
        parent::tearDown();
    }

    public function test_a_card_due_on_tuesday_is_planned_under_tuesday(): void
    {
        $card = $this->card(['title' => 'Write spec', 'due_at' => '2026-08-04']);

        $tue = $this->day($this->fetch(), 1);

        $this->assertSame('2026-08-04', $tue['date']);
        $this->assertCount(1, $tue['planned']);
        $this->assertSame($card->id, $tue['planned'][0]['card_id']);
        $this->assertSame('Write spec', $tue['planned'][0]['title']);
        $this->assertStringContainsString("/app/board/{$card->id}", $tue['planned'][0]['url']);
    }

    public function test_completing_a_card_on_tuesday_shows_under_tuesday_happened(): void
    {
        Carbon::setTestNow('2026-07-20 09:00:00');
        $card = $this->card(['title' => 'Ship it', 'due_at' => '2026-08-20']);

        Carbon::setTestNow('2026-08-04 15:00:00');
        $card->update(['status' => 'done', 'done_at' => now()]);

        $tue = $this->day($this->fetch(), 1);
        $done = collect($tue['happened'])->firstWhere('what', 'done');

        $this->assertNotNull($done);
        $this->assertSame($card->id, $done['card_id']);
        $this->assertSame([], $this->day($this->fetch(), 0)['happened']);
    }

    public function test_a_column_move_is_listed_as_moved_with_from_and_to(): void
    {
        Carbon::setTestNow('2026-07-20 09:00:00');
        $card = $this->card(['title' => 'Review PR', 'due_at' => '2026-08-20']);

        Carbon::setTestNow('2026-08-05 10:00:00');
        $card->update(['status' => 'prog']);

        $wed = $this->day($this->fetch(), 2);
        $moved = collect($wed['happened'])->firstWhere('what', 'moved');

        $this->assertNotNull($moved);
        $this->assertSame('todo', $moved['from']);
        $this->assertSame('prog', $moved['to']);
    }

    public function test_a_card_created_this_week_is_listed_as_created(): void
    {
        Carbon::setTestNow('2026-08-06 11:00:00');
        $this->card(['title' => 'New idea', 'due_at' => '2026-08-20']);

        $thu = $this->day($this->fetch(), 3);

        $this->assertSame('created', $thu['happened'][0]['what']);
    }

    public function test_timesheet_time_logged_against_a_card_is_listed(): void
    {
        $card = $this->card(['title' => 'Build form', 'due_at' => '2026-08-20']);
        app(CurrentTenant::class)->set($this->tenant);
        $sheet = Timesheet::create(['tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id, 'week_start' => self::WEEK, 'status' => 'submitted']);
        TimesheetEntry::create([
            'tenant_id' => $this->tenant->id, 'timesheet_id' => $sheet->id, 'entry_date' => '2026-08-07',
            'project_id' => $this->project->id, 'work_item_id' => $card->id, 'percentage' => 50,
        ]);
        app(CurrentTenant::class)->set(null);

        $fri = $this->day($this->fetch(), 4);
        $logged = collect($fri['happened'])->firstWhere('what', 'logged');

        $this->assertSame($card->id, $logged['card_id']);
        $this->assertSame('Ali', $logged['by']);
        $this->assertEquals(50, $logged['percentage']);
    }

    public function test_an_event_card_on_wednesday_is_under_wednesday_events(): void
    {
        $event = $this->card(['title' => 'Client demo', 'type' => 'event', 'due_at' => '2026-08-05']);

        $wed = $this->day($this->fetch(), 2);

        $this->assertSame($event->id, $wed['events'][0]['card_id']);
        $this->assertSame([], $wed['planned']);
    }

    public function test_every_project_answers_seven_days_monday_first(): void
    {
        $this->card(['title' => 'Anything', 'due_at' => '2026-08-09']);

        $days = $this->fetch()->json('data.projects.0.days');

        $this->assertCount(7, $days);
        $this->assertSame('2026-08-03', $days[0]['date']);
        $this->assertSame('2026-08-09', $days[6]['date']);
    }

    public function test_cards_outside_the_week_and_archived_cards_are_excluded(): void
    {
        Carbon::setTestNow('2026-07-01 09:00:00');
        $this->card(['title' => 'Old', 'due_at' => '2026-07-10']);
        $archived = $this->card(['title' => 'Gone', 'due_at' => '2026-08-04']);
        $archived->update(['archived_at' => now()]);

        $this->assertSame([], $this->fetch()->json('data.projects'));
    }

    public function test_it_requires_a_monday(): void
    {
        $this->getJson('/api/v1/board-week?week_start=2026-08-04', $this->bearer($this->hr))->assertStatus(422);
        $this->getJson('/api/v1/board-week', $this->bearer($this->hr))->assertStatus(422);
    }

    public function test_it_rejects_a_plain_employee_token(): void
    {
        $this->getJson('/api/v1/board-week?week_start='.self::WEEK, $this->bearer($this->staff))->assertStatus(403);
    }

    public function test_it_is_tenant_isolated(): void
    {
        $other = Tenant::create(['slug' => 'beta', 'name' => 'Beta', 'initials' => 'BE']);
        app(CurrentTenant::class)->set($other);
        $otherProject = Project::create(['tenant_id' => $other->id, 'code' => 'B1', 'name' => 'Beta Project', 'is_active' => true]);
        $otherEmployee = Employee::create(['tenant_id' => $other->id, 'name' => 'Beta Bob', 'status' => 'active', 'workload' => 'green']);
        WorkItem::create(['tenant_id' => $other->id, 'employee_id' => $otherEmployee->id, 'project_id' => $otherProject->id, 'title' => 'Beta card', 'status' => 'todo', 'due_at' => '2026-08-04']);
        app(CurrentTenant::class)->set(null);

        $this->card(['title' => 'Mine', 'due_at' => '2026-08-04']);

        $ids = collect($this->fetch()->json('data.projects'))->pluck('project_id');
        $this->assertTrue($ids->contains($this->project->id));
        $this->assertFalse($ids->contains($otherProject->id));
    }

    // --- helpers -----------------------------------------------------------

    private function fetch(): TestResponse
    {
        return $this->getJson('/api/v1/board-week?week_start='.self::WEEK, $this->bearer($this->hr))->assertOk();
    }

    /** @return array{date: string, planned: array, happened: array, events: array} */
    private function day(TestResponse $response, int $offset): array
    {
        $project = collect($response->json('data.projects'))->firstWhere('project_id', $this->project->id);
        $this->assertNotNull($project, 'project missing from feed');

        return $project['days'][$offset];
    }

    private function card(array $attrs): WorkItem
    {
        app(CurrentTenant::class)->set($this->tenant);
        $item = WorkItem::create($attrs + [
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id, 'project_id' => $this->project->id,
            'status' => 'todo', 'type' => 'task',
        ]);
        app(CurrentTenant::class)->set(null);

        return $item;
    }

    private function bearer(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->mintApiToken($this->tenant, 'test')->plainTextToken];
    }
}
