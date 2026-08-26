<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * audit_logs.target is a varchar(255) and every caller builds it from user-supplied
 * names. A long value used to throw mid-write — it killed a staging deploy from inside
 * a migration. Recording an action must never fail because of the length of its note.
 */
class AuditLogTruncationTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_over_long_target_is_trimmed_rather_than_throwing(): void
    {
        $tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        $user = User::create(['name' => 'Boss', 'email' => 'boss@example.com', 'password' => Hash::make('password')]);
        $user->tenants()->attach($tenant->id, ['role' => 'hr']);

        $this->actingAs($user);
        app(CurrentTenant::class)->set($tenant);

        AuditLog::record('Payroll profile reconciled — please review', str_repeat('Aminah binti Rahim (NRIC), ', 40));

        $row = AuditLog::latest('id')->firstOrFail();
        $this->assertSame(255, mb_strlen((string) $row->target));
        $this->assertStringStartsWith('Aminah binti Rahim (NRIC)', (string) $row->target);
    }

    public function test_a_normal_target_is_stored_untouched(): void
    {
        $tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        $user = User::create(['name' => 'Boss', 'email' => 'boss@example.com', 'password' => Hash::make('password')]);
        $user->tenants()->attach($tenant->id, ['role' => 'hr']);

        $this->actingAs($user);
        app(CurrentTenant::class)->set($tenant);

        AuditLog::record('Finalized payroll run', 'June 2026 · 6 payslips issued');

        $this->assertSame('June 2026 · 6 payslips issued', AuditLog::latest('id')->firstOrFail()->target);
    }
}
