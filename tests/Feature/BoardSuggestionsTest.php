<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Project;
use App\Models\PublicHoliday;
use App\Models\Tenant;
use App\Models\Timesheet;
use App\Models\TimesheetCategory;
use App\Models\TimesheetEntry;
use App\Models\User;
use App\Models\WorkItem;
use App\Models\WorkItemProgressStint;
use App\Timesheet\BoardSuggestions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Which In Progress cards belong on which day of a capture week. Read-only: this class
 * proposes rows, the staffer decides. Anything it gets wrong shows up as a row somebody
 * has to delete by hand every week, so the day boundaries matter more than they look.
 */
class BoardSuggestionsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Employee $employee;

    private TimesheetCategory $work;

    private BoardSuggestions $suggestions;

    /** Monday of the test week. Today is Wednesday of that same week. */
    private const WEEK = '2026-08-24';

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-08-26 09:00:00');

        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        $this->work = TimesheetCategory::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Delivery', 'requires_project' => true,
        ]);

        $user = User::create(['name' => 'Staffer', 'email' => 'staffer@example.com', 'password' => Hash::make('password')]);
        $user->tenants()->attach($this->tenant->id, ['role' => 'employee']);
        $this->employee = Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $user->id,
            'name' => 'Staffer', 'status' => 'active', 'workload' => 'green',
        ]);

        $this->actingAs($user)->withSession(['current_tenant' => $this->tenant->id]);
        $this->suggestions = app(BoardSuggestions::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function card(array $attrs = [], ?Employee $owner = null): WorkItem
    {
        $owner ??= $this->employee;

        return $owner->workItems()->create(array_merge([
            'tenant_id' => $owner->tenant_id, 'title' => 'Build the thing', 'type' => 'task',
            'priority' => 'low', 'status' => 'prog', 'progress' => 0,
        ], $attrs));
    }

    private function stint(WorkItem $card, string $from, ?string $to): void
    {
        // Written directly, not through the observer: these tests are about reading
        // stints back, and hand-written ones let a stint start before "now".
        WorkItemProgressStint::withoutGlobalScope('tenant')->create([
            'tenant_id' => $card->tenant_id, 'work_item_id' => $card->id,
            'started_at' => $from, 'ended_at' => $to,
        ]);
    }

    /** @return array<int, int> the work_item_ids suggested for a day */
    private function idsOn(array $result, string $day): array
    {
        return array_column($result[$day] ?? [], 'work_item_id');
    }

    public function test_a_card_is_suggested_on_every_day_of_its_stint(): void
    {
        $card = $this->card();
        $this->stint($card, '2026-08-25 10:00:00', '2026-08-26 08:00:00');

        $result = $this->suggestions->forWeek($this->employee, self::WEEK);

        $this->assertSame([], $this->idsOn($result, '2026-08-24'));
        $this->assertSame([$card->id], $this->idsOn($result, '2026-08-25'));
        $this->assertSame([$card->id], $this->idsOn($result, '2026-08-26'));
        $this->assertSame([], $this->idsOn($result, '2026-08-27'));
    }

    public function test_a_card_closed_the_same_day_it_was_opened_is_suggested_for_that_day(): void
    {
        $card = $this->card(['status' => 'done']);
        WorkItemProgressStint::withoutGlobalScope('tenant')->where('work_item_id', $card->id)->delete();
        $this->stint($card, '2026-08-25 15:00:00', '2026-08-25 15:00:00');

        $result = $this->suggestions->forWeek($this->employee, self::WEEK);

        $this->assertSame([$card->id], $this->idsOn($result, '2026-08-25'));
        $this->assertSame([], $this->idsOn($result, '2026-08-24'));
        $this->assertSame([], $this->idsOn($result, '2026-08-26'));
    }

    public function test_an_open_stint_runs_to_today_and_no_further(): void
    {
        $card = $this->card();
        $this->stint($card, '2026-08-24 09:00:00', null);

        $result = $this->suggestions->forWeek($this->employee, self::WEEK);

        $this->assertSame([$card->id], $this->idsOn($result, '2026-08-24'));
        $this->assertSame([$card->id], $this->idsOn($result, '2026-08-26'));
        // Thursday and Friday have not happened yet.
        $this->assertSame([], $this->idsOn($result, '2026-08-27'));
        $this->assertSame([], $this->idsOn($result, '2026-08-28'));
    }

    public function test_a_card_already_logged_on_a_day_is_not_suggested_again_for_that_day(): void
    {
        $card = $this->card();
        $this->stint($card, '2026-08-24 09:00:00', null);
        $this->logEntry($card, '2026-08-25');

        $result = $this->suggestions->forWeek($this->employee, self::WEEK);

        $this->assertSame([], $this->idsOn($result, '2026-08-25'));
        $this->assertSame([$card->id], $this->idsOn($result, '2026-08-24'));
    }

    public function test_a_public_holiday_receives_no_suggestions(): void
    {
        PublicHoliday::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Merdeka', 'date' => '2026-08-25',
        ]);
        $card = $this->card();
        $this->stint($card, '2026-08-24 09:00:00', null);

        $result = $this->suggestions->forWeek($this->employee, self::WEEK);

        $this->assertSame([], $this->idsOn($result, '2026-08-25'));
        $this->assertSame([$card->id], $this->idsOn($result, '2026-08-24'));
    }

    public function test_a_colleagues_card_is_not_suggested_but_a_shared_one_is(): void
    {
        $otherUser = User::create(['name' => 'Other', 'email' => 'other@example.com', 'password' => Hash::make('password')]);
        $otherUser->tenants()->attach($this->tenant->id, ['role' => 'employee']);
        $other = Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $otherUser->id,
            'name' => 'Other', 'status' => 'active', 'workload' => 'green',
        ]);

        $theirs = $this->card([], $other);
        $this->stint($theirs, '2026-08-24 09:00:00', null);

        $shared = $this->card([], $other);
        $shared->participants()->attach($this->employee->id);
        $this->stint($shared, '2026-08-24 09:00:00', null);

        $result = $this->suggestions->forWeek($this->employee, self::WEEK);

        $this->assertSame([$shared->id], $this->idsOn($result, '2026-08-24'));
    }

    public function test_an_archived_card_is_not_suggested(): void
    {
        $card = $this->card(['archived_at' => Carbon::parse('2026-08-25 12:00:00')]);
        $this->stint($card, '2026-08-24 09:00:00', null);

        $result = $this->suggestions->forWeek($this->employee, self::WEEK);

        $this->assertSame([], $this->idsOn($result, '2026-08-24'));
    }

    public function test_the_category_comes_from_the_card(): void
    {
        $project = Project::create(['tenant_id' => $this->tenant->id, 'name' => 'Apollo']);
        $card = $this->card(['project_id' => $project->id, 'timesheet_category_id' => $this->work->id]);
        $this->stint($card, '2026-08-25 09:00:00', null);

        $result = $this->suggestions->forWeek($this->employee, self::WEEK);
        $row = $result['2026-08-25'][0];

        $this->assertSame($this->work->id, $row['category_id']);
        $this->assertSame($project->id, $row['project_id']);
    }

    public function test_the_cards_own_category_beats_its_projects_one(): void
    {
        $admin = TimesheetCategory::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Admin', 'requires_project' => false,
        ]);
        $project = Project::create(['tenant_id' => $this->tenant->id, 'name' => 'Apollo']);
        $project->categories()->attach($this->work->id);
        $card = $this->card(['project_id' => $project->id, 'timesheet_category_id' => $admin->id]);
        $this->stint($card, '2026-08-25 09:00:00', null);

        $result = $this->suggestions->forWeek($this->employee, self::WEEK);

        $this->assertSame($admin->id, $result['2026-08-25'][0]['category_id']);
    }

    /**
     * There is no automatic overhead bucket any more. Others is where work the company
     * does for itself belongs, and dropping an unanswered card there would quietly put
     * whatever it was into the one column the director reads as overhead. The row comes
     * back uncategorised and the capture screen points at the card, where the picker is.
     */
    public function test_a_card_with_no_category_is_held_back_rather_than_filed_under_others(): void
    {
        TimesheetCategory::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Others', 'requires_project' => false,
        ]);
        $card = $this->card();
        $this->stint($card, '2026-08-25 09:00:00', null);

        $result = $this->suggestions->forWeek($this->employee, self::WEEK);

        $this->assertNull($result['2026-08-25'][0]['category_id']);
    }

    public function test_a_card_struck_off_a_day_is_not_offered_again(): void
    {
        $card = $this->card();
        $this->stint($card, '2026-08-24 09:00:00', null);

        Timesheet::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'week_start' => self::WEEK, 'status' => 'draft', 'total_hours' => 0,
            'dismissed_suggestions' => ['2026-08-25' => [$card->id]],
        ]);

        $result = $this->suggestions->forWeek($this->employee, self::WEEK);

        $this->assertSame([], $this->idsOn($result, '2026-08-25'));
        $this->assertSame([$card->id], $this->idsOn($result, '2026-08-24'));
    }

    public function test_a_card_in_review_keeps_being_suggested(): void
    {
        $card = $this->card();
        $this->stint($card, '2026-08-24 09:00:00', null);
        $card->update(['status' => 'review']);

        $result = $this->suggestions->forWeek($this->employee, self::WEEK);

        $this->assertSame([$card->id], $this->idsOn($result, '2026-08-26'));
    }

    /**
     * A project no longer answers for its cards. The project screen decides which
     * projects a category may be booked to, not which category a card is costed as, so a
     * card booked to a single-category project and never asked still owes an answer.
     */
    public function test_the_project_does_not_answer_for_the_card(): void
    {
        $project = Project::create(['tenant_id' => $this->tenant->id, 'name' => 'Apollo']);
        $project->categories()->attach($this->work->id);
        $card = $this->card(['project_id' => $project->id]);
        $this->stint($card, '2026-08-25 09:00:00', null);

        $result = $this->suggestions->forWeek($this->employee, self::WEEK);
        $row = $result['2026-08-25'][0];

        $this->assertNull($row['category_id']);
        $this->assertSame($project->id, $row['project_id']);
    }

    /** The bulk prefill and the single-card drawer must never disagree about a card. */
    public function test_the_prefill_and_the_cards_own_resolver_agree(): void
    {
        $project = Project::create(['tenant_id' => $this->tenant->id, 'name' => 'Apollo']);
        $project->categories()->attach($this->work->id);
        $card = $this->card(['project_id' => $project->id, 'timesheet_category_id' => $this->work->id]);
        $this->stint($card, '2026-08-25 09:00:00', null);

        $result = $this->suggestions->forWeek($this->employee, self::WEEK);

        $this->assertSame(
            $card->fresh()->effectiveTimesheetCategory()?->id,
            $result['2026-08-25'][0]['category_id'],
        );
    }

    public function test_a_stint_spanning_the_weekend_suggests_no_saturday_or_sunday(): void
    {
        // 2026-08-29 is the last Saturday of August, not the first — not a working day.
        $card = $this->card();
        $this->stint($card, '2026-08-24 09:00:00', null);
        Carbon::setTestNow('2026-08-31 09:00:00');

        $result = $this->suggestions->forWeek($this->employee, self::WEEK);

        $this->assertSame([], $this->idsOn($result, '2026-08-29'));
        $this->assertSame([$card->id], $this->idsOn($result, '2026-08-28'));
    }

    public function test_a_card_with_no_project_and_no_overhead_bucket_is_suggested_uncategorised(): void
    {
        $card = $this->card();
        $this->stint($card, '2026-08-25 09:00:00', null);

        $result = $this->suggestions->forWeek($this->employee, self::WEEK);
        $row = $result['2026-08-25'][0];

        $this->assertNull($row['category_id']);
        $this->assertNull($row['project_id']);
        // The card names the line; the note stays the staffer's to write.
        $this->assertSame('Build the thing', $row['title']);
        $this->assertSame('', $row['description']);
    }

    /** Log a real timesheet entry for a card, so the "already logged" and carry-forward paths have data. */
    private function logEntry(WorkItem $card, string $day, ?int $projectId = null, ?int $categoryId = null): void
    {
        // Not firstOrCreate([week_start => ...]): sqlite stores the `date`-cast column
        // with a " 00:00:00" suffix, so a plain equality search for the bare date never
        // matches an existing row and a second call in the same test would collide on
        // the (employee_id, week_start) unique index instead of reusing the first row.
        $timesheet = Timesheet::query()
            ->forWeek(self::WEEK)
            ->where('tenant_id', $this->tenant->id)
            ->where('employee_id', $this->employee->id)
            ->first() ?? Timesheet::create([
                'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
                'week_start' => self::WEEK, 'status' => 'draft', 'total_hours' => 0,
            ]);

        TimesheetEntry::create([
            'tenant_id' => $this->tenant->id, 'timesheet_id' => $timesheet->id,
            'entry_date' => $day, 'category_id' => $categoryId ?? $this->work->id, 'project_id' => $projectId,
            'percentage' => 100, 'hours' => 8, 'work_item_id' => $card->id,
        ]);
    }
}
