<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\EasterEgg;
use App\Models\EasterEggView;
use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkItem;
use App\Services\FeatureManager;
use App\Support\EasterEggBank;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * CR-31 dashboard/board easter eggs: the bank (seed/pick), the once-a-day gate
 * (EasterEggBank::showOnce), the board move() inbox_zero egg, "Keep it plain",
 * and the HR curation routes' tenant check. The end-to-end acceptance shapes
 * (dashboard rendering, the profile switch) are covered by
 * tests/Acceptance/CR31Test.php; this file exercises the pieces underneath it.
 */
class EasterEggTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
    }

    private function signIn(string $role, string $name = 'Aminah'): Employee
    {
        $user = User::create(['name' => $name, 'email' => strtolower($name).$role.'@acme.test', 'password' => Hash::make('password')]);
        $user->tenants()->attach($this->tenant->id, ['role' => $role]);
        $employee = Employee::create(['tenant_id' => $this->tenant->id, 'user_id' => $user->id, 'name' => $name, 'status' => 'active', 'workload' => 'green']);
        $this->actingAs($user)->withSession(['current_tenant' => $this->tenant->id]);

        return $employee;
    }

    // ── the bank ──

    public function test_seed_gives_every_kind_at_least_two_approved_lines_in_both_languages(): void
    {
        EasterEggBank::seed($this->tenant->id);

        foreach (EasterEggBank::KINDS as $kind) {
            $rows = EasterEgg::where('tenant_id', $this->tenant->id)->where('kind', $kind)->get();
            $this->assertGreaterThanOrEqual(2, $rows->count(), "fewer than two '{$kind}' eggs");
            foreach ($rows as $row) {
                $this->assertNotNull($row->approved_at);
                $this->assertNotSame('', $row->text_en);
                $this->assertNotSame('', $row->text_ms);
            }
        }
    }

    public function test_seed_is_a_no_op_when_the_tenant_already_has_lines(): void
    {
        EasterEggBank::seed($this->tenant->id);
        $before = EasterEgg::where('tenant_id', $this->tenant->id)->count();

        EasterEggBank::seed($this->tenant->id);

        $this->assertSame($before, EasterEgg::where('tenant_id', $this->tenant->id)->count());
    }

    public function test_pick_only_returns_approved_lines_for_the_requested_kind(): void
    {
        EasterEgg::create(['tenant_id' => $this->tenant->id, 'kind' => 'friday_late', 'text_en' => 'Pending.', 'text_ms' => 'Belum.', 'approved_at' => null]);
        EasterEgg::create(['tenant_id' => $this->tenant->id, 'kind' => 'friday_late', 'text_en' => 'Approved.', 'text_ms' => 'Diluluskan.', 'approved_at' => now()]);
        EasterEgg::create(['tenant_id' => $this->tenant->id, 'kind' => 'late_night', 'text_en' => 'Wrong kind.', 'text_ms' => 'Jenis salah.', 'approved_at' => now()]);

        for ($i = 0; $i < 5; $i++) {
            $egg = EasterEggBank::pick($this->tenant->id, 'friday_late');
            $this->assertSame('Approved.', $egg?->text_en);
        }
    }

    public function test_pick_returns_null_when_nothing_is_approved(): void
    {
        $this->assertNull(EasterEggBank::pick($this->tenant->id, 'friday_late'));
    }

    // ── once-a-day gate ──

    public function test_show_once_records_a_view_and_refuses_a_second_show_the_same_day(): void
    {
        EasterEggBank::seed($this->tenant->id);
        $employee = $this->signIn('employee');
        $now = Carbon::parse('2026-09-11 17:05:00');

        $first = EasterEggBank::showOnce($this->tenant->id, $employee->id, 'friday_late', $now);
        $this->assertNotNull($first);
        $this->assertDatabaseHas('easter_egg_views', [
            'employee_id' => $employee->id, 'kind' => 'friday_late', 'shown_on' => '2026-09-11',
        ]);

        $second = EasterEggBank::showOnce($this->tenant->id, $employee->id, 'friday_late', $now->copy()->addMinutes(20));
        $this->assertNull($second, 'a second show the same day must return nothing');

        $nextDay = EasterEggBank::showOnce($this->tenant->id, $employee->id, 'friday_late', $now->copy()->addDay());
        $this->assertNotNull($nextDay, 'the next day must show again');
    }

    public function test_show_once_does_not_burn_the_daily_quota_when_nothing_is_approved(): void
    {
        $employee = $this->signIn('employee');
        $now = Carbon::parse('2026-09-11 17:05:00');

        $this->assertNull(EasterEggBank::showOnce($this->tenant->id, $employee->id, 'friday_late', $now));
        $this->assertDatabaseMissing('easter_egg_views', ['employee_id' => $employee->id, 'kind' => 'friday_late']);

        EasterEgg::create(['tenant_id' => $this->tenant->id, 'kind' => 'friday_late', 'text_en' => 'Now approved.', 'text_ms' => 'Kini diluluskan.', 'approved_at' => now()]);

        $egg = EasterEggBank::showOnce($this->tenant->id, $employee->id, 'friday_late', $now->copy()->addMinutes(5));
        $this->assertNotNull($egg, 'a line approved later the same day should still be able to show');
    }

    // ── board move() ──

    private function card(Employee $owner, array $attrs = []): WorkItem
    {
        return $owner->workItems()->create(array_merge([
            'tenant_id' => $this->tenant->id, 'title' => 'Card', 'type' => 'task',
            'priority' => 'low', 'status' => 'todo', 'progress' => 0,
        ], $attrs));
    }

    public function test_moving_the_last_overdue_card_to_done_returns_an_inbox_zero_egg(): void
    {
        EasterEggBank::seed($this->tenant->id);
        $employee = $this->signIn('employee');
        Carbon::setTestNow('2026-09-09 15:00:00');
        $card = $this->card($employee, ['due_at' => '2026-09-01']);

        $response = $this->postJson("/app/board/{$card->id}/move", ['status' => 'done'])->assertOk();

        $this->assertSame('inbox_zero', $response->json('egg.kind'));
        Carbon::setTestNow();
    }

    public function test_moving_a_card_that_was_not_overdue_never_returns_an_inbox_zero_egg(): void
    {
        EasterEggBank::seed($this->tenant->id);
        $employee = $this->signIn('employee');
        Carbon::setTestNow('2026-09-09 15:00:00');
        $card = $this->card($employee, ['due_at' => '2026-09-30']);

        $response = $this->postJson("/app/board/{$card->id}/move", ['status' => 'done'])->assertOk();

        $this->assertNull($response->json('egg'));
        Carbon::setTestNow();
    }

    public function test_an_event_card_never_returns_an_inbox_zero_egg_even_if_overdue(): void
    {
        EasterEggBank::seed($this->tenant->id);
        $employee = $this->signIn('employee');
        Carbon::setTestNow('2026-09-09 15:00:00');
        $card = $this->card($employee, ['type' => 'event', 'due_at' => '2026-09-01']);

        $response = $this->postJson("/app/board/{$card->id}/move", ['status' => 'done'])->assertOk();

        $this->assertNull($response->json('egg'));
        Carbon::setTestNow();
    }

    // ── Keep it plain ──

    public function test_keep_it_plain_returns_no_egg_and_records_no_view(): void
    {
        EasterEggBank::seed($this->tenant->id);
        $employee = $this->signIn('employee');
        $this->postJson('/app/dashboard/prefs', ['plain' => true])->assertOk();
        Carbon::setTestNow('2026-09-09 15:00:00');
        $card = $this->card($employee, ['due_at' => '2026-09-01']);

        $response = $this->postJson("/app/board/{$card->id}/move", ['status' => 'done'])->assertOk();

        $this->assertNull($response->json('egg'));
        $this->assertSame(0, EasterEggView::where('employee_id', $employee->id)->count());
        Carbon::setTestNow();
    }

    // ── late-night shortcut never 404s ──

    public function test_late_night_shortcut_points_at_overtime_when_the_module_is_on(): void
    {
        EasterEggBank::seed($this->tenant->id);
        $this->signIn('employee');
        Carbon::setTestNow('2026-09-09 22:30:00');

        $this->get('/app/dash')->assertOk()->assertSee('href="/app/overtime"', false);
        Carbon::setTestNow();
    }

    public function test_late_night_shortcut_falls_back_to_timesheets_when_overtime_is_off(): void
    {
        EasterEggBank::seed($this->tenant->id);
        app(FeatureManager::class)->setTenant($this->tenant, 'module.overtime', '0');
        $this->signIn('employee');
        Carbon::setTestNow('2026-09-09 22:30:00');

        $response = $this->get('/app/dash')->assertOk();
        $response->assertSee('href="/app/timesheets"', false)->assertDontSee('href="/app/overtime"', false);
        $this->assertStringContainsString('Log your hours on the timesheet?', $response->getContent());
        Carbon::setTestNow();
    }

    // ── settings routes: role gate + tenant check ──

    public function test_employee_cannot_curate_the_bank(): void
    {
        $this->signIn('employee');

        $this->postJson('/app/admin/eggs', ['kind' => 'friday_late', 'text_en' => 'a', 'text_ms' => 'b'])->assertForbidden();
    }

    /**
     * A bound EasterEgg outside the acting tenant is rejected — whether by the
     * tenant global scope failing to resolve it at bind time (404, what this
     * app's actual middleware order produces) or by EasterEggController's own
     * explicit assertTenant() (403, the documented defense-in-depth for a
     * context where the scope doesn't apply). Either way, nothing is touched.
     */
    public function test_hr_cannot_edit_or_delete_another_tenants_egg(): void
    {
        $other = Tenant::create(['slug' => 'other', 'name' => 'Other', 'initials' => 'OT']);
        $foreignEgg = EasterEgg::create(['tenant_id' => $other->id, 'kind' => 'friday_late', 'text_en' => 'Not yours.', 'text_ms' => 'Bukan awak.', 'approved_at' => now()]);

        $this->signIn('hr');

        $update = $this->postJson("/app/admin/eggs/{$foreignEgg->id}", ['kind' => 'friday_late', 'text_en' => 'x', 'text_ms' => 'y']);
        $delete = $this->postJson("/app/admin/eggs/{$foreignEgg->id}/delete");
        $this->assertContains($update->getStatusCode(), [403, 404]);
        $this->assertContains($delete->getStatusCode(), [403, 404]);
        $this->assertDatabaseHas('easter_eggs', ['id' => $foreignEgg->id, 'text_en' => 'Not yours.']);
    }
}
