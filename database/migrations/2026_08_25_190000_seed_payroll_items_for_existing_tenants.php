<?php

use App\Models\PayrollItem;
use App\Models\Tenant;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Give every tenant that predates the payroll-item catalogue the standard system
     * items, so staging/production get them on the normal `git pull && deploy.sh` cycle
     * instead of needing someone to remember to run PayrollItemSeeder by hand. Idempotent
     * via PayrollItem::seedFor() — safe to run again, and safe on a fresh install with no
     * tenants yet (the loop is just empty).
     */
    public function up(): void
    {
        foreach (Tenant::all() as $tenant) {
            PayrollItem::seedFor($tenant);
        }
    }

    public function down(): void
    {
        // Deliberately empty: by the time this rolls back, HR may have edited these
        // items' flags, and a rollback must not delete data that isn't this migration's
        // to take back.
    }
};
