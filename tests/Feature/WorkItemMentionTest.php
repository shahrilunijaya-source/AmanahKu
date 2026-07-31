<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Stage 3 of the T.A.A. board redesign: @-mentions in a card's comment thread,
 * plus the /app/board?card={id} deep link a mention notification lands on.
 *
 * The server re-parses @Name out of the saved comment body against the card's
 * own mentionable set (participants + assigner) rather than trusting anything
 * the client implies — see WorkItemController::notifyMentions() and
 * mentionableEmployees(). Several tests here exist specifically to prove that
 * boundary holds under a hostile body, not just a cooperative one.
 */
class WorkItemMentionTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Tenant $tenant;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create(['name' => 'Demo', 'email' => 'demo@example.com', 'password' => Hash::make('password')]);
        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        $this->user->tenants()->attach($this->tenant->id, ['role' => 'employee']);
        $this->employee = Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $this->user->id,
            'name' => 'Demo', 'status' => 'active', 'workload' => 'green',
        ]);
    }

    private function actingInTenant(): self
    {
        $this->actingAs($this->user)->withSession(['current_tenant' => $this->tenant->id]);

        return $this;
    }

    private function actingAsEmployee(Employee $emp): self
    {
        $this->actingAs(User::find($emp->user_id))->withSession(['current_tenant' => $this->tenant->id]);

        return $this;
    }

    private function card(array $attrs = []): WorkItem
    {
        return $this->employee->workItems()->create(array_merge([
            'tenant_id' => $this->tenant->id, 'title' => 'X', 'type' => 'task',
            'priority' => 'low', 'status' => 'todo', 'progress' => 0,
        ], $attrs));
    }

    /** A distinct employee + user, for use as a participant/outsider. */
    private function person(string $name, string $email): Employee
    {
        $u = User::create(['name' => $name, 'email' => $email, 'password' => Hash::make('password')]);
        $u->tenants()->attach($this->tenant->id, ['role' => 'employee']);

        return Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $u->id,
            'name' => $name, 'status' => 'active', 'workload' => 'green',
        ]);
    }

    private function manager(string $role = 'manager'): Employee
    {
        $u = User::create(['name' => 'Mgr', 'email' => 'mgr@example.com', 'password' => Hash::make('password')]);
        $u->tenants()->attach($this->tenant->id, ['role' => $role]);

        return Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $u->id,
            'name' => 'Mgr', 'status' => 'active', 'workload' => 'green',
        ]);
    }

    private function tac(Employee $mgr, array $attrs = []): WorkItem
    {
        return $this->employee->workItems()->create(array_merge([
            'tenant_id' => $this->tenant->id, 'title' => 'T', 'type' => 'adhoc',
            'priority' => 'medium', 'status' => 'todo', 'progress' => 0,
            'assigned_by_id' => $mgr->id, 'assigned_at' => now(),
        ], $attrs));
    }

    public function test_mentioning_a_participant_notifies_exactly_that_set(): void
    {
        $alice = $this->person('Alice Wong', 'alice@example.com');
        $bob = $this->person('Bob Tan', 'bob@example.com');
        $card = $this->card(['title' => 'Design review']);
        $card->participants()->attach([$alice->id, $bob->id]);

        $this->actingInTenant()->postJson("/app/board/{$card->id}/comments", [
            'body' => 'Looping in @Alice Wong on this.',
        ])->assertCreated();

        $this->assertDatabaseHas('app_notifications', [
            'user_id' => $alice->user_id, 'title' => 'Demo mentioned you on a task', 'body' => 'Design review',
        ]);
        $this->assertDatabaseMissing('app_notifications', ['user_id' => $bob->user_id]);
        // Exactly that set — one row, not one per participant on the card.
        $this->assertSame(1, AppNotification::count());
        // Built with route(), not a hardcoded string — lands back on this card.
        $this->assertStringContainsString('/app/board?card='.$card->id, (string) AppNotification::first()->url);
    }

    /**
     * The attack: a body naming someone who is neither a participant nor the
     * assigner must notify nobody, even though their real full name sits right
     * there in the text. The server only trusts its own re-parse against the
     * card's mentionable set — never a client-supplied id list, and never the
     * tenant's wider roster.
     */
    public function test_mentioning_someone_not_on_the_card_notifies_nobody(): void
    {
        $outsider = $this->person('Outsider Person', 'outsider@example.com');
        $card = $this->card(['title' => 'Private matter']);

        $this->actingInTenant()->postJson("/app/board/{$card->id}/comments", [
            'body' => 'Careful, @Outsider Person should not see this.',
        ])->assertCreated();

        $this->assertSame(0, AppNotification::count());
        $this->assertDatabaseMissing('app_notifications', ['user_id' => $outsider->user_id]);
    }

    public function test_author_mentioning_themselves_is_not_notified(): void
    {
        $alice = $this->person('Alice Wong', 'alice@example.com');
        $card = $this->card(['title' => 'Design review']);
        $card->participants()->attach($alice->id);

        $this->actingAsEmployee($alice)->postJson("/app/board/{$card->id}/comments", [
            'body' => 'Noted, @Alice Wong will handle this.',
        ])->assertCreated();

        $this->assertSame(0, AppNotification::count());
    }

    /** show()'s payload feeds the drawer's picker: participants plus the
     *  assigner, never the owner and never anyone outside the card. */
    public function test_show_returns_mentionable_set_of_participants_plus_assigner(): void
    {
        $mgr = $this->manager();
        $alice = $this->person('Alice Wong', 'alice@example.com');
        $this->person('Outsider Person', 'outsider@example.com');
        $item = $this->tac($mgr, ['title' => 'Do the thing']);
        $item->participants()->attach($alice->id);

        $res = $this->actingInTenant()->getJson("/app/board/{$item->id}")->assertOk();

        $names = collect($res->json('card.mentionable'))->pluck('name');
        $this->assertCount(2, $names);
        $this->assertTrue($names->contains('Alice Wong'));
        $this->assertTrue($names->contains('Mgr'));
        $this->assertFalse($names->contains('Outsider Person'));
        // The owner (assignee of the tac) is not mentionable on their own card.
        $this->assertFalse($names->contains('Demo'));
    }

    public function test_deep_link_renders_board_with_that_cards_drawer_wired_open(): void
    {
        $item = $this->card(['title' => 'Deep-linked card']);

        $this->actingInTenant()->get('/app/board?card='.$item->id)
            ->assertOk()
            ->assertSee('data-deep-link-card="'.$item->id.'"', false);
    }

    /** Not a 403 for the whole screen, and no confirmation the card exists —
     *  the deep-link id is only relayed to the client, which opens it through
     *  the same authorized GET /app/board/{workItem} the drawer always used;
     *  that endpoint's own authorizeAccess() is what actually gates it. */
    public function test_deep_link_for_an_inaccessible_card_renders_the_board_normally(): void
    {
        $colleague = Employee::create(['tenant_id' => $this->tenant->id, 'name' => 'Other', 'status' => 'active', 'workload' => 'green']);
        $foreign = $colleague->workItems()->create([
            'tenant_id' => $this->tenant->id, 'title' => 'Not mine to see', 'type' => 'task',
            'priority' => 'low', 'status' => 'todo', 'progress' => 0,
        ]);

        $this->actingInTenant()->get('/app/board?card='.$foreign->id)
            ->assertOk()
            ->assertDontSee('Not mine to see');
    }

    public function test_deep_link_ignores_a_non_numeric_card_param(): void
    {
        $this->actingInTenant()->get('/app/board?card=drop-table')
            ->assertOk()
            ->assertDontSee('data-deep-link-card', false);
    }
}
