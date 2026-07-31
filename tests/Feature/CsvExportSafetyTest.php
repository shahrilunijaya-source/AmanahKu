<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The employee roster export must neutralise CSV formula injection: a staff name that
 * begins with a spreadsheet formula trigger is streamed with a leading apostrophe so it
 * is read as text, not executed when another user opens the file (AK-SEC-06).
 */
class CsvExportSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_employee_export_neutralises_a_formula_payload_name(): void
    {
        $tenant = Tenant::create(['slug' => 'alpha', 'name' => 'Alpha', 'initials' => 'AL']);
        $hr = User::create(['name' => 'HR', 'email' => 'hr@example.com', 'password' => Hash::make('password')]);
        $hr->tenants()->attach($tenant->id, ['role' => 'hr']);
        Employee::create(['tenant_id' => $tenant->id, 'user_id' => $hr->id, 'name' => 'HR', 'status' => 'active', 'workload' => 'green']);

        // A malicious/compromised import sets a formula payload as a staff name.
        Employee::create([
            'tenant_id' => $tenant->id, 'name' => "=cmd|'/c calc'!A1",
            'email' => 'payload@example.com', 'status' => 'active', 'workload' => 'green',
        ]);

        $content = $this->actingAs($hr)
            ->withSession(['current_tenant' => $tenant->id])
            ->get('/app/reports/export/employees')
            ->assertOk()
            ->streamedContent();

        // Neutralised (quoted) form present; raw executable form absent.
        $this->assertStringContainsString("'=cmd|", $content);
        $this->assertStringNotContainsString("\n=cmd|", $content);
    }
}
