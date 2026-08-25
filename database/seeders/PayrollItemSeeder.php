<?php

namespace Database\Seeders;

use App\Models\PayrollItem;
use App\Models\Tenant;
use Illuminate\Database\Seeder;

class PayrollItemSeeder extends Seeder
{
    /**
     * Seed the standard Malaysian pay-item catalogue for every tenant. Thin wrapper
     * around PayrollItem::seedFor(), which is idempotent (creates only missing codes,
     * never overwrites an existing row) and also called on tenant creation and by a
     * migration for tenants that predate this catalogue — see PayrollItem::SYSTEM_ITEMS.
     */
    public function run(): void
    {
        foreach (Tenant::all() as $tenant) {
            PayrollItem::seedFor($tenant);
        }
    }
}
