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
 * Per-user workspace wallpaper: the users.appearance column, the presets, the
 * AppearanceController endpoints and what layouts/app.blade.php renders from them.
 * Spec: docs/superpowers/specs/2026-09-03-custom-backgrounds-design.html
 */
class AppearanceTest extends TestCase
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

    public function test_appearance_column_round_trips_as_array(): void
    {
        $this->user->appearance = ['wallpaper' => 'preset:dawn', 'dim' => 'soft'];
        $this->user->save();

        $this->assertSame(['wallpaper' => 'preset:dawn', 'dim' => 'soft'], $this->user->fresh()->appearance);
        $this->assertNull(User::create(['name' => 'B', 'email' => 'b@example.com', 'password' => Hash::make('x')])->appearance);
    }

    public function test_cover_path_column_exists_on_employees(): void
    {
        $this->employee->update(['cover_path' => 'covers/1/abc.jpg']);

        $this->assertSame('covers/1/abc.jpg', $this->employee->fresh()->cover_path);
    }

    public function test_six_presets_and_three_dims_are_configured(): void
    {
        $this->assertSame(['dawn', 'dusk', 'paper', 'moss', 'slate', 'sand'], array_keys(config('amanahku.wallpaper_presets')));
        $this->assertSame(['none' => 0, 'soft' => 30, 'strong' => 55], config('amanahku.wallpaper_dims'));
    }
}
