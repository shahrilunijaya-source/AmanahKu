<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Minimal bootstrap seeder: creates ONLY the platform super-admin and no
 * tenant/demo data. Pair with `migrate:fresh` to get a blank platform where
 * companies are provisioned through /admin/companies (the real onboarding flow).
 *
 *   php artisan migrate:fresh --force
 *   php artisan db:seed --class=PlatformAdminSeeder --force
 *
 * Idempotent — safe to re-run. Ships a known password, so refuse outside
 * local/testing exactly like DatabaseSeeder.
 */
class PlatformAdminSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local', 'testing')) {
            throw new \RuntimeException(
                'PlatformAdminSeeder ships a known-password super-admin; refused outside local/testing.'
            );
        }

        $admin = User::firstOrCreate(
            ['email' => 'superadmin@amanahku.com'],
            ['name' => 'Platform Admin', 'password' => Hash::make('password')]
        );

        $admin->forceFill(['is_super_admin' => true])->save();

        // Restore the GLOBAL Profile Test question bank (working-style + colour).
        // Questions have no tenant_id, so migrate:fresh wipes them and they are
        // not re-created by company onboarding. Seed them here so a blank reset
        // keeps a usable instrument. ProfileTestSeeder is self-guarding (no-op if
        // any question already exists).
        $this->call(ProfileTestSeeder::class);
    }
}
