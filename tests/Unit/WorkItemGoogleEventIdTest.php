<?php

namespace Tests\Unit;

use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class WorkItemGoogleEventIdTest extends TestCase
{
    use RefreshDatabase;

    public function test_google_event_id_is_nullable_and_storable(): void
    {
        $user = User::create(['name' => 'Demo', 'email' => 'demo@example.com', 'password' => Hash::make('password')]);
        $tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        $user->tenants()->attach($tenant->id, ['role' => 'employee']);
        $employee = Employee::create([
            'tenant_id' => $tenant->id, 'user_id' => $user->id,
            'name' => 'Demo', 'status' => 'active', 'workload' => 'green',
        ]);

        $item = $employee->workItems()->create([
            'tenant_id' => $tenant->id, 'title' => 'X', 'type' => 'task',
            'priority' => 'low', 'status' => 'todo', 'progress' => 0,
        ]);
        $this->assertNull($item->google_event_id);

        $item->update(['google_event_id' => 'evt_123']);
        $this->assertSame('evt_123', $item->fresh()->google_event_id);
    }
}
