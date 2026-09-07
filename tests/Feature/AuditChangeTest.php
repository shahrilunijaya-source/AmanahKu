<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkItem;
use App\Support\AuditContext;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuditChangeTest extends TestCase
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
        app(CurrentTenant::class)->set($this->tenant);

        return $this;
    }

    private function card(array $attrs = []): WorkItem
    {
        return $this->employee->workItems()->create(array_merge([
            'tenant_id' => $this->tenant->id, 'title' => 'X', 'type' => 'task',
            'priority' => 'low', 'status' => 'todo', 'progress' => 0,
        ], $attrs));
    }

    public function test_change_stores_json_old_new_subject_morph_actor_and_default_source(): void
    {
        $this->actingInTenant();
        $item = $this->card();

        AuditLog::change($item, 'priority', 'low', 'high');

        $row = AuditLog::latest('id')->firstOrFail();
        $this->assertSame($item->getMorphClass(), $row->subject_type);
        $this->assertSame($item->id, $row->subject_id);
        $this->assertSame('priority', $row->field);
        $this->assertSame('low', json_decode($row->old_value));
        $this->assertSame('high', json_decode($row->new_value));
        $this->assertSame($this->user->id, $row->user_id);
        $this->assertSame('Demo', $row->actor_name);
        // No HTTP request is active in this direct-call test, so the default source
        // resolves to 'job' (see AuditContext::source()) — the acceptance test covers
        // the 'ui' path through a real PATCH request.
        $this->assertSame('job', $row->source);
    }

    public function test_updating_an_audited_field_writes_one_row_per_field_and_ignores_unaudited_fields(): void
    {
        $this->actingInTenant();
        $item = $this->card(['due_at' => null]);

        $item->update(['due_at' => '2026-10-01', 'description' => 'Not audited']);

        $fields = AuditLog::where('subject_id', $item->id)->pluck('field')->all();
        $this->assertContains('due_at', $fields);
        $this->assertNotContains('description', $fields);
    }

    public function test_creating_a_subtask_writes_a_created_row(): void
    {
        $this->actingInTenant();
        $parent = $this->card();

        $child = $parent->children()->create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'title' => 'Sub', 'type' => 'task', 'priority' => 'low', 'status' => 'todo', 'progress' => 0,
        ]);

        $row = AuditLog::where('subject_id', $child->id)->where('field', 'created')->first();
        $this->assertNotNull($row);
        $this->assertSame((string) $child->id, (string) json_decode($row->new_value));
    }

    public function test_record_still_works_and_carries_source(): void
    {
        $this->actingInTenant();

        AuditLog::record('Did a thing', 'target');

        $row = AuditLog::latest('id')->firstOrFail();
        $this->assertSame('Did a thing', $row->action);
        $this->assertSame('job', $row->source);
    }

    public function test_export_route_is_forbidden_for_employee_and_returns_csv_for_hr(): void
    {
        $this->actingInTenant();
        $item = $this->card(['priority' => 'low']);
        $item->update(['priority' => 'high']);

        $this->get('/app/audit/export')->assertStatus(403);

        $hrUser = User::create(['name' => 'HR', 'email' => 'hr@example.com', 'password' => Hash::make('password')]);
        $hrUser->tenants()->attach($this->tenant->id, ['role' => 'hr']);

        $response = $this->actingAs($hrUser)->withSession(['current_tenant' => $this->tenant->id])->get('/app/audit/export');
        $response->assertOk();

        $csv = $response->streamedContent();
        $this->assertStringContainsString('id,created_at,actor_name,action,target', $csv);
        $this->assertStringContainsString('priority', $csv);
    }

    public function test_audit_screen_shows_field_old_new_and_reason(): void
    {
        $this->actingInTenant();
        $item = $this->card(['title' => 'Prototype POC']);
        AuditContext::reason('Client agreed the date');
        try {
            $item->update(['due_at' => '2026-10-01']);
        } finally {
            AuditContext::reset();
        }

        $hrUser = User::create(['name' => 'HR', 'email' => 'hr@example.com', 'password' => Hash::make('password')]);
        $hrUser->tenants()->attach($this->tenant->id, ['role' => 'hr']);

        $this->actingAs($hrUser)->withSession(['current_tenant' => $this->tenant->id])->get('/app/audit')->assertOk()
            ->assertSee('due_at: <span style="color:var(--muted);">—</span> &rarr; 2026-10-01 00:00:00', false)
            ->assertSee('Reason</span>: Client agreed the date', false)
            ->assertSee('Prototype POC');
    }
}
