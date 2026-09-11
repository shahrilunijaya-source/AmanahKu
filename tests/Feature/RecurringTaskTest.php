<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Position;
use App\Models\RecurringTask;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkItem;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * CR-18 engine details the acceptance file does not pin: period arithmetic per
 * frequency, lead days, the owner fallback chain, and the screen's form path.
 */
class RecurringTaskTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function person(string $name, string $role = 'employee', array $attrs = []): Employee
    {
        $user = User::create(['name' => $name, 'email' => strtolower(preg_replace('/\W+/', '', $name)).'@example.com', 'password' => Hash::make('password')]);
        $user->tenants()->attach($this->tenant->id, ['role' => $role]);

        return Employee::create(array_merge(['tenant_id' => $this->tenant->id, 'user_id' => $user->id, 'name' => $name, 'status' => 'active', 'workload' => 'green'], $attrs));
    }

    private function schedule(array $attrs = []): RecurringTask
    {
        return RecurringTask::create(array_merge([
            'tenant_id' => $this->tenant->id, 'title' => 'Weekly standup notes', 'frequency' => 'weekly', 'interval' => 1,
            'start_on' => '2026-09-07', 'priority' => 'low', 'lead_days' => 0, 'subtasks' => [], 'tagged_employee_ids' => [],
        ], $attrs));
    }

    #[Test]
    public function period_arithmetic_follows_the_frequency(): void
    {
        $weekly = $this->schedule();
        $this->assertSame('2026-09-21', $weekly->periodStart(2)->toDateString());
        $this->assertSame('2026-09-13', $weekly->dueFor($weekly->periodStart(0))->toDateString());

        $monthly = $this->schedule(['frequency' => 'monthly', 'start_on' => '2026-01-31']);
        $this->assertSame('2026-02-28', $monthly->periodStart(1)->toDateString(), 'month-end start must not overflow');
        $this->assertSame('2026-02-28', $monthly->dueFor($monthly->periodStart(1))->toDateString());

        $yearly = $this->schedule(['frequency' => 'yearly', 'start_on' => '2026-03-01']);
        $this->assertSame('2028-03-01', $yearly->periodStart(2)->toDateString());
        $this->assertSame('2028-03-31', $yearly->dueFor($yearly->periodStart(2))->toDateString());

        $bi = $this->schedule(['frequency' => 'every_n_months', 'interval' => 2, 'start_on' => '2026-11-01']);
        $this->assertNull($bi->latestPeriodDueOn(CarbonImmutable::parse('2026-10-31')));
        $this->assertSame('2026-11-01', $bi->latestPeriodDueOn(CarbonImmutable::parse('2026-12-15'))->toDateString());
        $this->assertSame('2027-01-01', $bi->latestPeriodDueOn(CarbonImmutable::parse('2027-02-01'))->toDateString());
    }

    #[Test]
    public function lead_days_bring_the_card_forward_and_the_due_date_stays_at_the_period_end(): void
    {
        $owner = $this->person('Owner');
        $this->schedule(['frequency' => 'monthly', 'start_on' => '2026-10-01', 'lead_days' => 3, 'owner_employee_id' => $owner->id]);

        Carbon::setTestNow('2026-09-28 06:00:00');
        Artisan::call('work:recurring');
        $card = WorkItem::where('title', 'Weekly standup notes')->first();
        $this->assertNotNull($card, Artisan::output());
        $this->assertSame('2026-10-31', $card->due_at?->format('Y-m-d'));
    }

    #[Test]
    public function owner_falls_back_from_person_to_position_to_creator_and_nothing_is_made_without_one(): void
    {
        $creator = $this->person('Creator', 'hr');
        $position = Position::create(['tenant_id' => $this->tenant->id, 'title' => 'Safety Officer', 'status' => 'active']);
        $holder = $this->person('Holder', 'employee', ['position_id' => $position->id]);
        $named = $this->person('Named');

        $s = $this->schedule(['owner_employee_id' => $named->id, 'owner_position_title' => 'Safety Officer', 'created_by_employee_id' => $creator->id]);
        $this->assertSame($named->id, $s->resolveOwner()?->id);

        $named->update(['archived_at' => now()]);
        $this->assertSame($holder->id, $s->fresh()->resolveOwner()?->id);

        $holder->update(['archived_at' => now()]);
        $this->assertSame($creator->id, $s->fresh()->resolveOwner()?->id);

        $creator->update(['archived_at' => now()]);
        $this->assertNull($s->fresh()->resolveOwner());

        Carbon::setTestNow('2026-09-07 06:00:00');
        Artisan::call('work:recurring');
        $this->assertSame(0, WorkItem::count(), 'a card was made with nobody to own it');
        $this->assertSame(0, $s->occurrences()->count(), 'an ownerless period must stay open for the next run');
    }

    #[Test]
    public function the_screen_form_creates_a_schedule_from_one_subtask_per_line(): void
    {
        $hr = $this->person('HR Person', 'hr');
        $this->actingAs($hr->user)->withSession(['current_tenant' => $this->tenant->id])
            ->post('/app/admin/recurring', [
                'title' => 'Quarterly fire drill', 'frequency' => 'every_n_months', 'interval' => 3, 'start_on' => '2026-10-01',
                'owner_employee_id' => $hr->id, 'priority' => 'low', 'subtasks_text' => "Book the assembly point\n\nRun the drill\n",
            ])->assertRedirect();

        $s = RecurringTask::where('title', 'Quarterly fire drill')->first();
        $this->assertNotNull($s);
        $this->assertSame(['Book the assembly point', 'Run the drill'], $s->subtasks);
        $this->assertSame($hr->id, $s->created_by_employee_id);

        $this->actingAs($hr->user)->withSession(['current_tenant' => $this->tenant->id])
            ->post('/app/admin/recurring', ['title' => 'No owner', 'frequency' => 'weekly', 'start_on' => '2026-10-01'])
            ->assertSessionHasErrors('owner_employee_id');
    }

    #[Test]
    public function monthly_ignores_the_hidden_every_n_field(): void
    {
        $hr = $this->person('HR Person', 'hr');
        $this->actingAs($hr->user)->withSession(['current_tenant' => $this->tenant->id])
            ->post('/app/admin/recurring', [
                'title' => 'Monthly check', 'frequency' => 'monthly', 'interval' => 2, 'start_on' => '2026-10-01',
                'owner_employee_id' => $hr->id,
            ])->assertRedirect();

        $this->assertSame(1, RecurringTask::where('title', 'Monthly check')->first()->interval);
    }

    #[Test]
    public function a_schedule_from_another_company_is_not_found(): void
    {
        $hr = $this->person('HR Person', 'hr');
        $other = Tenant::create(['slug' => 'other', 'name' => 'Other', 'initials' => 'OT']);
        $foreign = RecurringTask::create(['tenant_id' => $other->id, 'title' => 'Theirs', 'frequency' => 'weekly', 'interval' => 1, 'start_on' => '2026-09-07']);

        $this->actingAs($hr->user)->withSession(['current_tenant' => $this->tenant->id])
            ->postJson("/app/admin/recurring/{$foreign->id}/pause")->assertStatus(404);
        $this->assertNull($foreign->fresh()->paused_at);
    }
}
