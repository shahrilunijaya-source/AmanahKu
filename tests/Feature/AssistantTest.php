<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Ai\AiProvider;
use App\Services\Ai\CannedAiProvider;
use App\Services\FeatureManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AssistantTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create(['name' => 'Demo', 'email' => 'demo@example.com', 'password' => Hash::make('password')]);
        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        $this->user->tenants()->attach($this->tenant->id, ['role' => 'employee']);
        Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $this->user->id,
            'name' => 'Demo', 'status' => 'active', 'workload' => 'green',
        ]);
        // ai.assistant ships disabled by default; this suite exercises it working, so
        // turn it on for the tenant (mirrors what the super-admin console does).
        app(FeatureManager::class)->setTenant($this->tenant, 'ai.assistant', true);
    }

    private function actingInTenant(): self
    {
        $this->actingAs($this->user)->withSession(['current_tenant' => $this->tenant->id]);

        return $this;
    }

    public function test_default_ai_provider_is_canned(): void
    {
        $this->assertInstanceOf(CannedAiProvider::class, app(AiProvider::class));
    }

    public function test_assistant_returns_a_reply(): void
    {
        $this->actingInTenant()->postJson('/app/assistant', ['message' => 'Who is overloaded this week?'])
            ->assertOk()
            ->assertJsonStructure(['reply', 'source'])
            ->assertJsonPath('source', 'Rule-based · live data')
            ->assertSee('Acme', false);
    }

    public function test_assistant_requires_a_message(): void
    {
        $this->actingInTenant()->postJson('/app/assistant', ['message' => ''])->assertStatus(422);
    }

    public function test_canned_reply_summarises_live_workforce_data(): void
    {
        $reply = (new CannedAiProvider)->reply('anything', [
            'tenant' => 'Acme', 'headcount' => 12, 'overloaded' => ['Faizal'],
            'pendingLeave' => 2, 'pendingClaims' => 1,
            'you' => ['name' => 'Demo', 'openTasks' => 3],
        ]);

        $this->assertStringContainsString('12 employees in Acme', $reply);
        $this->assertStringContainsString('Faizal', $reply);
        $this->assertStringContainsString('3 open task(s)', $reply);
    }

    public function test_canned_reply_omits_company_figures_when_absent(): void
    {
        $reply = (new CannedAiProvider)->reply('anything', [
            'tenant' => 'Acme', 'you' => ['name' => 'Demo', 'openTasks' => 3],
        ]);

        $this->assertStringContainsString('3 open task(s)', $reply);
        $this->assertStringContainsString('Acme', $reply);
        $this->assertStringContainsString('managers and HR', $reply);
        $this->assertStringNotContainsString('employees in', $reply);
    }

    public function test_plain_employee_reply_has_no_company_figures(): void
    {
        $reply = $this->actingInTenant()->postJson('/app/assistant', ['message' => 'Who is overloaded this week?'])
            ->assertOk()
            ->json('reply');

        $this->assertStringNotContainsString('employees in Acme', $reply);
        $this->assertStringNotContainsString('headcount', $reply);
        $this->assertStringContainsString('managers and HR', $reply);
    }

    public function test_manager_reply_still_includes_company_figures(): void
    {
        $manager = User::create(['name' => 'Boss', 'email' => 'boss@example.com', 'password' => Hash::make('password')]);
        $manager->tenants()->attach($this->tenant->id, ['role' => 'hr']);
        Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $manager->id,
            'name' => 'Boss', 'status' => 'active', 'workload' => 'green',
        ]);

        $reply = $this->actingAs($manager)->withSession(['current_tenant' => $this->tenant->id])
            ->postJson('/app/assistant', ['message' => 'Who is overloaded this week?'])
            ->assertOk()
            ->json('reply');

        $this->assertStringContainsString('employees in Acme', $reply);
    }
}
