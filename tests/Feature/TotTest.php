<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\KnowledgeContribution;
use App\Models\Tenant;
use App\Models\TotSession;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Feature coverage for the TOT sessions board.
 * Harness (setUp / actingInTenant / hrActor) copied from IdeaTest.
 */
class TotTest extends TestCase
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

    private function hrActor(): User
    {
        $hr = User::create(['name' => 'Boss', 'email' => 'boss@example.com', 'password' => Hash::make('password')]);
        $hr->tenants()->attach($this->tenant->id, ['role' => 'hr']);
        Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $hr->id,
            'name' => 'Boss', 'status' => 'active', 'workload' => 'green',
        ]);

        return $hr;
    }

    private function makeSession(array $overrides = []): TotSession
    {
        return TotSession::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'year' => 2026,
            'month' => 3,
            'presenter_employee_id' => $this->employee->id,
            'title' => 'Install git on our own server',
            'status' => 'done',
            'links' => [['label' => 'Slides', 'url' => 'https://example.com/slides']],
        ], $overrides));
    }

    // ── Schema ────────────────────────────────────────────────────

    public function test_a_session_stores_its_links_as_an_array(): void
    {
        $session = $this->makeSession();

        $this->assertSame('Slides', $session->fresh()->links[0]['label']);
    }

    public function test_one_slot_per_tenant_year_and_month(): void
    {
        $this->makeSession();

        $this->expectException(QueryException::class);

        $this->makeSession(['title' => 'Duplicate slot']);
    }

    public function test_first_saturday_is_computed_per_month(): void
    {
        $this->assertSame('2026-03-07', TotSession::firstSaturday(2026, 3)->toDateString());
        $this->assertSame('2026-08-01', TotSession::firstSaturday(2026, 8)->toDateString());
        $this->assertSame('2027-01-02', TotSession::firstSaturday(2027, 1)->toDateString());
    }

    // ── Contribution credit helper ────────────────────────────────

    public function test_mark_credits_the_month_it_is_given_not_the_current_month(): void
    {
        app(CurrentTenant::class)->set($this->tenant);

        KnowledgeContribution::mark($this->employee, 2026, 3);

        $this->assertDatabaseHas('knowledge_monthly_contributions', [
            'employee_id' => $this->employee->id,
            'year' => 2026,
            'month' => 3,
            'submitted' => true,
        ]);
    }

    public function test_mark_is_idempotent_for_the_same_month(): void
    {
        app(CurrentTenant::class)->set($this->tenant);

        KnowledgeContribution::mark($this->employee, 2026, 3);
        KnowledgeContribution::mark($this->employee, 2026, 3);

        $this->assertSame(1, KnowledgeContribution::where('employee_id', $this->employee->id)->count());
    }

    // ── Screen ────────────────────────────────────────────────────

    public function test_the_screen_renders_twelve_slots_for_the_requested_year(): void
    {
        $this->makeSession(['month' => 3, 'title' => 'Install git on our own server']);

        $response = $this->actingInTenant()->get('/app/tot?year=2026');

        $response->assertOk();
        $response->assertViewHas('sessions', fn ($sessions) => count($sessions) === 12);
        $response->assertSee('Install git on our own server');
    }

    public function test_the_screen_defaults_to_the_current_year(): void
    {
        $response = $this->actingInTenant()->get('/app/tot');

        $response->assertOk();
        $response->assertViewHas('year', (int) now()->year);
    }

    // ── Roster permissions ────────────────────────────────────────

    public function test_an_employee_cannot_create_a_slot(): void
    {
        $response = $this->actingInTenant()->post('/app/tot', [
            'year' => 2026, 'month' => 9, 'status' => 'planned',
        ]);

        $response->assertForbidden();
    }

    public function test_hr_creates_a_slot(): void
    {
        $hr = $this->hrActor();

        $response = $this->actingAs($hr)->withSession(['current_tenant' => $this->tenant->id])
            ->post('/app/tot', [
                'year' => 2026, 'month' => 9,
                'presenter_employee_id' => $this->employee->id,
                'title' => 'Queue workers in production',
                'status' => 'confirmed',
            ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('tot_sessions', [
            'year' => 2026, 'month' => 9, 'title' => 'Queue workers in production', 'status' => 'confirmed',
        ]);
    }

    public function test_hr_cannot_overwrite_an_existing_slot_by_posting_store_again(): void
    {
        $session = $this->makeSession(['year' => 2026, 'month' => 9, 'title' => 'Original title']);
        $hr = $this->hrActor();

        $response = $this->actingAs($hr)->withSession(['current_tenant' => $this->tenant->id])
            ->post('/app/tot', [
                'year' => 2026, 'month' => 9,
                'title' => 'Overwritten title',
                'status' => 'planned',
            ]);

        $response->assertStatus(422);
        $this->assertSame('Original title', $session->fresh()->title);
    }

    public function test_the_presenter_edits_their_own_slot(): void
    {
        $session = $this->makeSession(['status' => 'confirmed', 'title' => null]);

        $response = $this->actingInTenant()->post("/app/tot/{$session->id}", [
            'title' => 'Install git on our own server',
        ]);

        $response->assertRedirect();
        $this->assertSame('Install git on our own server', $session->fresh()->title);
    }

    public function test_an_employee_cannot_edit_a_slot_they_do_not_present(): void
    {
        $other = Employee::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Someone else',
            'status' => 'active', 'workload' => 'green',
        ]);
        $session = $this->makeSession(['presenter_employee_id' => $other->id]);

        $response = $this->actingInTenant()->post("/app/tot/{$session->id}", ['title' => 'Hijacked']);

        $response->assertForbidden();
    }

    public function test_the_presenter_cannot_change_the_status(): void
    {
        $session = $this->makeSession(['status' => 'confirmed']);

        $this->actingInTenant()->post("/app/tot/{$session->id}", [
            'title' => 'Install git on our own server',
            'status' => 'done',
        ]);

        $this->assertSame('confirmed', $session->fresh()->status);
    }

    public function test_an_unknown_status_is_rejected(): void
    {
        $hr = $this->hrActor();
        $session = $this->makeSession();

        $response = $this->actingAs($hr)->withSession(['current_tenant' => $this->tenant->id])
            ->post("/app/tot/{$session->id}", ['status' => 'cancelled']);

        $response->assertSessionHasErrors('status');
    }

    public function test_only_privileged_roles_delete_a_slot(): void
    {
        $session = $this->makeSession();

        $this->actingInTenant()->post("/app/tot/{$session->id}/delete")->assertForbidden();

        $hr = $this->hrActor();
        $this->actingAs($hr)->withSession(['current_tenant' => $this->tenant->id])
            ->post("/app/tot/{$session->id}/delete")->assertRedirect();

        $this->assertDatabaseMissing('tot_sessions', ['id' => $session->id]);
    }

    // ── Knowledge Bank contribution credit ────────────────────────

    public function test_marking_a_session_done_credits_the_presenter_for_that_month(): void
    {
        $hr = $this->hrActor();
        $session = $this->makeSession(['year' => 2026, 'month' => 3, 'status' => 'confirmed']);

        // Deliberately act in a different month to prove the credit follows the session.
        $this->travelTo('2026-04-20 09:00:00');

        $this->actingAs($hr)->withSession(['current_tenant' => $this->tenant->id])
            ->post("/app/tot/{$session->id}", ['status' => 'done'])
            ->assertRedirect();

        $this->assertDatabaseHas('knowledge_monthly_contributions', [
            'employee_id' => $this->employee->id, 'year' => 2026, 'month' => 3, 'submitted' => true,
        ]);
        $this->assertDatabaseMissing('knowledge_monthly_contributions', [
            'employee_id' => $this->employee->id, 'year' => 2026, 'month' => 4,
        ]);
    }

    public function test_reverting_a_session_out_of_done_keeps_the_credit(): void
    {
        $hr = $this->hrActor();
        $session = $this->makeSession(['status' => 'confirmed']);

        $this->actingAs($hr)->withSession(['current_tenant' => $this->tenant->id])
            ->post("/app/tot/{$session->id}", ['status' => 'done']);
        $this->actingAs($hr)->withSession(['current_tenant' => $this->tenant->id])
            ->post("/app/tot/{$session->id}", ['status' => 'planned']);

        $this->assertDatabaseHas('knowledge_monthly_contributions', [
            'employee_id' => $this->employee->id, 'year' => 2026, 'month' => 3,
        ]);
    }

    public function test_a_session_with_no_employee_presenter_credits_nobody(): void
    {
        $hr = $this->hrActor();
        $session = $this->makeSession([
            'presenter_employee_id' => null, 'presenter_name' => 'Team', 'status' => 'confirmed',
        ]);

        $this->actingAs($hr)->withSession(['current_tenant' => $this->tenant->id])
            ->post("/app/tot/{$session->id}", ['status' => 'done'])
            ->assertRedirect();

        $this->assertSame(0, KnowledgeContribution::count());
    }

    public function test_a_not_tot_entry_never_credits_a_month(): void
    {
        $hr = $this->hrActor();
        $session = $this->makeSession(['status' => 'not_tot', 'title' => 'Jamuan raya']);

        $this->actingAs($hr)->withSession(['current_tenant' => $this->tenant->id])
            ->post("/app/tot/{$session->id}", ['status' => 'not_tot'])
            ->assertRedirect();

        $this->assertSame(0, KnowledgeContribution::count());
    }
}
