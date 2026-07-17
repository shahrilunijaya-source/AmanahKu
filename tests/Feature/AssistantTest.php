<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Ai\AiProvider;
use App\Services\Ai\CannedAiProvider;
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
}
