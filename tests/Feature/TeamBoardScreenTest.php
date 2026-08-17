<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Team board screen (2026-07-29 reshape): one table, one line per person,
 * replacing the earlier summary-strip-above-a-task-table shape. Every task
 * still renders — inside the floating window a person's line opens, filtered
 * client-side (resources/js/team-board.js) rather than a separate table.
 * Fixture pattern follows TeamBoardDataTest, which pins the data shape this
 * screen renders from.
 */
class TeamBoardScreenTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $managerUser;

    private Employee $managerEmployee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);

        $this->managerUser = User::create(['name' => 'Boss', 'email' => 'boss@example.com', 'password' => Hash::make('password')]);
        $this->managerUser->tenants()->attach($this->tenant->id, ['role' => 'manager']);
        $this->managerEmployee = Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $this->managerUser->id,
            'name' => 'Boss', 'status' => 'active', 'workload' => 'green',
        ]);
    }

    private function actingAsManager(): self
    {
        $this->actingAs($this->managerUser)->withSession(['current_tenant' => $this->tenant->id]);

        return $this;
    }

    /** Create an employee (no user account needed for the team board). */
    private function makeEmployee(string $name, array $attrs = []): Employee
    {
        return Employee::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'name' => $name, 'status' => 'active', 'workload' => 'green',
        ], $attrs));
    }

    /** Create a work item for a given employee. */
    private function makeCard(Employee $employee, array $attrs = []): WorkItem
    {
        return $employee->workItems()->create(array_merge([
            'tenant_id' => $this->tenant->id, 'title' => 'Card', 'type' => 'task',
            'priority' => 'low', 'status' => 'todo', 'progress' => 0,
        ], $attrs));
    }

    /**
     * One line per $teamPeople entry — the whole point of the reshape: no
     * more per-task rows sitting in the page's own table, exactly one row
     * per person.
     */
    public function test_renders_one_line_per_person(): void
    {
        $alice = $this->makeEmployee('Alice');
        $bob = $this->makeEmployee('Bob');
        $this->makeCard($alice);
        $this->makeCard($alice);
        $this->makeCard($bob);
        $this->makeCard($this->managerEmployee);

        $response = $this->actingAsManager()->get('/app/team-board');
        $response->assertOk();

        $teamPeople = $response->viewData('teamPeople');
        $this->assertGreaterThan(0, $teamPeople->count());

        $html = $response->getContent();

        foreach ($teamPeople as $person) {
            $this->assertStringContainsString('data-person-id="'.$person['id'].'"', $html);
        }

        // Exactly one line per teamPeople entry — no duplicates, nothing extra.
        $this->assertSame($teamPeople->count(), substr_count($html, 'data-person-id="'));
    }

    /**
     * Every task still renders — it lives inside the floating window now,
     * filtered client-side to whichever person is open, rather than in a
     * page-level table. The test only inspects raw markup (no JS execution),
     * so this proves the data is on the page with no fetch required.
     */
    public function test_every_task_is_present_in_the_markup(): void
    {
        $alice = $this->makeEmployee('Alice');
        $this->makeCard($alice, ['title' => 'Card one']);
        $this->makeCard($alice, ['title' => 'Card two']);
        $this->makeCard($this->managerEmployee, ['title' => 'Boss card']);

        $response = $this->actingAsManager()->get('/app/team-board');
        $response->assertOk();

        $teamRows = $response->viewData('teamRows');
        $this->assertGreaterThan(0, $teamRows->count());

        $html = $response->getContent();

        foreach ($teamRows as $row) {
            $this->assertStringContainsString('data-id="'.$row['item']->id.'"', $html);
            $this->assertStringContainsString('data-owner-id="'.$row['owner_id'].'"', $html);
        }

        // Exactly one card per teamRows entry.
        $this->assertSame($teamRows->count(), substr_count($html, 'data-owner-id="'));
    }

    /**
     * Grouping happens by status — a card assigned to the "review" column
     * must not also render (or get miscounted) under another column.
     */
    public function test_window_groups_cards_by_status(): void
    {
        $alice = $this->makeEmployee('Alice');
        $reviewCard = $this->makeCard($alice, ['title' => 'In review card', 'status' => 'review']);

        $response = $this->actingAsManager()->get('/app/team-board');
        $response->assertOk();

        $html = $response->getContent();
        $this->assertMatchesRegularExpression(
            '/data-id="'.$reviewCard->id.'"\s+data-status="review"/',
            $html
        );
    }

    public function test_guide_copy_no_longer_mentions_lanes(): void
    {
        $this->makeCard($this->managerEmployee);

        $response = $this->actingAsManager()->get('/app/team-board');
        $response->assertOk();

        $html = strtolower($response->getContent());
        $this->assertStringNotContainsString('lane', $html);
    }

    /**
     * Person lines must stay keyboard-reachable buttons for the floating
     * window to open without a mouse — no data-person-id, tabindex, or role
     * means no way to reach a person's window from the keyboard.
     */
    public function test_person_lines_carry_the_attributes_the_window_needs_to_open_them(): void
    {
        $alice = $this->makeEmployee('Alice');
        $this->makeCard($alice, ['title' => 'Reachable card']);

        $response = $this->actingAsManager()->get('/app/team-board');
        $response->assertOk();

        $html = $response->getContent();
        $this->assertStringContainsString('data-person-id="'.$alice->id.'"', $html);
        $this->assertStringContainsString('tabindex="0"', $html);
        $this->assertStringContainsString('role="button"', $html);
    }

    /**
     * This is the bug being fixed: a task row used to set window.location.href
     * to the personal board's `?card=` deep link, which threw the viewer onto
     * their OWN board and discarded this screen's search/sort/filter state.
     * That string must not appear anywhere now that a person opens a floating
     * window in place, with no navigation at all.
     */
    public function test_screen_no_longer_navigates_to_the_personal_board(): void
    {
        $alice = $this->makeEmployee('Alice');
        $this->makeCard($alice);

        $response = $this->actingAsManager()->get('/app/team-board');
        $response->assertOk();

        $this->assertStringNotContainsString('/app/board?card=', $response->getContent());
    }

    /**
     * A task card must be keyboard-reachable too, same as a person row, so
     * the drawer can open without a mouse.
     */
    public function test_task_cards_are_keyboard_reachable(): void
    {
        $alice = $this->makeEmployee('Alice');
        $card = $this->makeCard($alice, ['title' => 'Reachable task']);

        $response = $this->actingAsManager()->get('/app/team-board');
        $response->assertOk();

        $html = $response->getContent();
        $this->assertMatchesRegularExpression(
            '/data-id="'.$card->id.'"[^>]*tabindex="0"[^>]*role="button"/s',
            $html
        );
    }

    /**
     * The card drawer renders in its view + comment only shape here — no
     * editable title, no status buttons, no delete/archive menu — while the
     * comment composer stays fully present. See partials.work-drawer's
     * $interactive flag and resources/js/team-board.js's drawer.locked.
     */
    public function test_card_drawer_renders_view_and_comment_only(): void
    {
        $this->makeCard($this->managerEmployee);

        $response = $this->actingAsManager()->get('/app/team-board');
        $response->assertOk();

        $html = $response->getContent();
        // The read-only title has no contenteditable attribute at all.
        $this->assertStringNotContainsString('contenteditable', $html);
        // The personal board's status segmented control and "..." actions menu
        // are absent here.
        $this->assertStringNotContainsString('setStatus(', $html);
        $this->assertStringNotContainsString('archiveCard()', $html);
        $this->assertStringNotContainsString('deleteCard()', $html);
        // The comment composer is present and wired up.
        $this->assertStringContainsString('addComment()', $html);
        $this->assertStringContainsString('wd-post', $html);
    }

    public function test_plain_employee_with_no_direct_reports_gets_403(): void
    {
        $plainUser = User::create(['name' => 'Plain', 'email' => 'plain@example.com', 'password' => Hash::make('password')]);
        $plainUser->tenants()->attach($this->tenant->id, ['role' => 'employee']);
        Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $plainUser->id,
            'name' => 'Plain', 'status' => 'active', 'workload' => 'green',
        ]);

        $response = $this->actingAs($plainUser)
            ->withSession(['current_tenant' => $this->tenant->id])
            ->get('/app/team-board');

        $response->assertForbidden();
    }
}
