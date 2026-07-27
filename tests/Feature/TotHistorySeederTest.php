<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\KnowledgeContribution;
use App\Models\Tenant;
use App\Models\TotSession;
use App\Tenancy\CurrentTenant;
use Database\Seeders\TotHistorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TotHistorySeederTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['slug' => 'unijaya', 'name' => 'Unijaya', 'initials' => 'UJ']);
        app(CurrentTenant::class)->set($this->tenant);
    }

    public function test_it_imports_thirty_six_slots_with_the_expected_statuses(): void
    {
        $this->seed(TotHistorySeeder::class);

        $this->assertSame(36, TotSession::count());
        $this->assertSame(22, TotSession::where('status', 'done')->count());
        $this->assertSame(6, TotSession::where('status', 'skipped')->count());
        $this->assertSame(1, TotSession::where('status', 'not_tot')->count());
        $this->assertSame(7, TotSession::where('status', 'planned')->count());
    }

    public function test_a_multi_link_row_keeps_every_link_with_its_own_label(): void
    {
        $this->seed(TotHistorySeeder::class);

        $selenium = TotSession::where('year', 2024)->where('month', 9)->firstOrFail();

        $this->assertCount(2, $selenium->links);
        $this->assertSame(['Slides', 'Video'], array_column($selenium->links, 'label'));
    }

    public function test_an_unmatched_nickname_falls_back_to_free_text(): void
    {
        $this->seed(TotHistorySeeder::class);

        $team = TotSession::where('year', 2025)->where('month', 1)->firstOrFail();

        $this->assertNull($team->presenter_employee_id);
        $this->assertSame('Team', $team->presenter_name);
    }

    public function test_a_matching_employee_is_linked(): void
    {
        Employee::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Nabil',
            'status' => 'active', 'workload' => 'green',
        ]);

        $this->seed(TotHistorySeeder::class);

        $session = TotSession::where('year', 2026)->where('month', 3)->firstOrFail();

        $this->assertNotNull($session->presenter_employee_id);
        $this->assertNull($session->presenter_name);
    }

    public function test_running_it_twice_does_not_duplicate(): void
    {
        $this->seed(TotHistorySeeder::class);
        $this->seed(TotHistorySeeder::class);

        $this->assertSame(36, TotSession::count());
    }

    public function test_it_never_backfills_knowledge_bank_credit(): void
    {
        $this->seed(TotHistorySeeder::class);

        $this->assertSame(0, KnowledgeContribution::count());
    }
}
