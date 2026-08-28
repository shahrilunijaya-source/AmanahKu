<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Tenant;
use App\Models\TimesheetCategory;
use App\Models\TimesheetEntry;
use App\Models\User;
use App\Models\WorkItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * A saved row remembers the board card it came from. That link is what stops the same
 * card being suggested twice for a day, and what carries its category to the rest of
 * the week — so it has to survive a second save of the same week, and it has to be
 * refused when it points at somebody else's card.
 */
class TimesheetWorkItemLinkTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Employee $employee;

    private User $user;

    private TimesheetCategory $work;

    protected function setUp(): void
    {
        parent::setUp();

        // A Wednesday, so the whole test week is in the past and inside the backfill window.
        Carbon::setTestNow('2026-08-26 09:00:00');

        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        $this->work = TimesheetCategory::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Others', 'requires_project' => false,
        ]);

        $this->user = User::create(['name' => 'Staffer', 'email' => 'staffer@example.com', 'password' => Hash::make('password')]);
        $this->user->tenants()->attach($this->tenant->id, ['role' => 'employee']);
        $this->employee = Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $this->user->id,
            'name' => 'Staffer', 'status' => 'active', 'workload' => 'green',
        ]);

        $this->actingAs($this->user)->withSession(['current_tenant' => $this->tenant->id]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function card(?Employee $owner = null, array $attrs = []): WorkItem
    {
        $owner ??= $this->employee;

        return $owner->workItems()->create(array_merge([
            'tenant_id' => $owner->tenant_id, 'title' => 'Card', 'type' => 'task',
            'priority' => 'low', 'status' => 'prog', 'progress' => 0,
        ], $attrs));
    }

    /** @param array<int, array<string, mixed>> $entries */
    private function save(array $entries): TestResponse
    {
        return $this->post('/app/timesheets', [
            'week_start' => '2026-08-24',
            'entries' => $entries,
        ]);
    }

    public function test_a_saved_row_keeps_the_card_it_came_from(): void
    {
        $card = $this->card();

        $this->save([
            ['entry_date' => '2026-08-25', 'category_id' => $this->work->id, 'percentage' => 100, 'work_item_id' => $card->id],
        ])->assertSessionHasNoErrors();

        $this->assertSame($card->id, TimesheetEntry::sole()->work_item_id);
    }

    public function test_the_link_survives_a_second_save_of_the_same_week(): void
    {
        $card = $this->card();

        $this->save([
            ['entry_date' => '2026-08-25', 'category_id' => $this->work->id, 'percentage' => 60, 'work_item_id' => $card->id],
        ])->assertSessionHasNoErrors();

        // The capture screen reloads the week and saves it back whole. Rebuild the payload
        // the way the screen does — from what the server hands back — not from the card.
        $reloaded = TimesheetEntry::sole();
        $this->save([
            [
                'entry_date' => '2026-08-25', 'category_id' => $reloaded->category_id,
                'percentage' => 80, 'work_item_id' => $reloaded->work_item_id,
            ],
        ])->assertSessionHasNoErrors();

        $this->assertSame($card->id, TimesheetEntry::sole()->work_item_id);
    }

    public function test_two_cards_on_the_same_category_and_project_are_not_a_duplicate_line(): void
    {
        $one = $this->card();
        $two = $this->card();

        $this->save([
            ['entry_date' => '2026-08-25', 'category_id' => $this->work->id, 'percentage' => 50, 'work_item_id' => $one->id],
            ['entry_date' => '2026-08-25', 'category_id' => $this->work->id, 'percentage' => 50, 'work_item_id' => $two->id],
        ])->assertSessionHasNoErrors();

        $this->assertSame(2, TimesheetEntry::count());
    }

    public function test_the_same_card_twice_on_one_day_is_still_a_duplicate_line(): void
    {
        $card = $this->card();

        $response = $this->save([
            ['entry_date' => '2026-08-25', 'category_id' => $this->work->id, 'percentage' => 50, 'work_item_id' => $card->id],
            ['entry_date' => '2026-08-25', 'category_id' => $this->work->id, 'percentage' => 50, 'work_item_id' => $card->id],
        ]);

        $response->assertSessionHasErrors('entries');
    }

    public function test_a_card_belonging_to_another_employee_is_refused(): void
    {
        $other = User::create(['name' => 'Other', 'email' => 'other@example.com', 'password' => Hash::make('password')]);
        $other->tenants()->attach($this->tenant->id, ['role' => 'employee']);
        $otherEmployee = Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $other->id,
            'name' => 'Other', 'status' => 'active', 'workload' => 'green',
        ]);

        $foreign = $this->card($otherEmployee);

        $this->save([
            ['entry_date' => '2026-08-25', 'category_id' => $this->work->id, 'percentage' => 100, 'work_item_id' => $foreign->id],
        ])->assertSessionHasErrors('entries.0.work_item_id');

        $this->assertSame(0, TimesheetEntry::count());
    }

    public function test_a_card_from_another_tenant_is_refused(): void
    {
        $otherTenant = Tenant::create(['slug' => 'other', 'name' => 'Other Co', 'initials' => 'OC']);
        $otherUser = User::create(['name' => 'Far', 'email' => 'far@example.com', 'password' => Hash::make('password')]);
        $otherUser->tenants()->attach($otherTenant->id, ['role' => 'employee']);
        $farEmployee = Employee::create([
            'tenant_id' => $otherTenant->id, 'user_id' => $otherUser->id,
            'name' => 'Far', 'status' => 'active', 'workload' => 'green',
        ]);

        $foreign = $farEmployee->workItems()->create([
            'tenant_id' => $otherTenant->id, 'title' => 'Theirs', 'type' => 'task',
            'priority' => 'low', 'status' => 'prog', 'progress' => 0,
        ]);

        $this->save([
            ['entry_date' => '2026-08-25', 'category_id' => $this->work->id, 'percentage' => 100, 'work_item_id' => $foreign->id],
        ])->assertSessionHasErrors('entries.0.work_item_id');
    }

    public function test_a_card_the_person_participates_in_is_accepted(): void
    {
        $other = User::create(['name' => 'Owner', 'email' => 'owner@example.com', 'password' => Hash::make('password')]);
        $other->tenants()->attach($this->tenant->id, ['role' => 'employee']);
        $owner = Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $other->id,
            'name' => 'Owner', 'status' => 'active', 'workload' => 'green',
        ]);

        $shared = $this->card($owner);
        $shared->participants()->attach($this->employee->id);

        $this->save([
            ['entry_date' => '2026-08-25', 'category_id' => $this->work->id, 'percentage' => 100, 'work_item_id' => $shared->id],
        ])->assertSessionHasNoErrors();

        $this->assertSame($shared->id, TimesheetEntry::sole()->work_item_id);
    }

    public function test_a_row_with_no_card_still_saves(): void
    {
        $this->save([
            ['entry_date' => '2026-08-25', 'category_id' => $this->work->id, 'percentage' => 100],
        ])->assertSessionHasNoErrors();

        $this->assertNull(TimesheetEntry::sole()->work_item_id);
    }
}
