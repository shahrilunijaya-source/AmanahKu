<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Data backfill (NOT a schema change): the feature registry split the Overtime screen
 * out of `module.leave` into its own default-true key `module.overtime`. Existing
 * tenants that had explicitly DISABLED Leave & Time-off carry a `tenant_features` row
 * `module.leave = 0` but no `module.overtime` row, so FeatureManager::value() would fall
 * through to the platform/registry default (true) and silently re-enable Overtime for
 * them (nav entry + /app/overtime routes reachable again) — an entitlement regression.
 *
 * This copies each tenant's stored `module.leave` value verbatim into a `module.overtime`
 * row when none exists yet, preserving the tenant admin's original intent: tenants who
 * turned Leave off keep Overtime off; tenants who had it on (or on-by-default) are
 * unaffected. Idempotent — safe to re-run — and touches only tenants with an explicit
 * leave override. Uses the raw query builder because TenantFeature is tenant-scoped by a
 * global scope that is not active in the migration context.
 */
return new class extends Migration
{
    public function up(): void
    {
        $leaveRows = DB::table('tenant_features')
            ->where('key', 'module.leave')
            ->get(['tenant_id', 'value']);

        foreach ($leaveRows as $row) {
            $alreadySet = DB::table('tenant_features')
                ->where('tenant_id', $row->tenant_id)
                ->where('key', 'module.overtime')
                ->exists();

            if ($alreadySet) {
                continue;
            }

            DB::table('tenant_features')->insert([
                'tenant_id' => $row->tenant_id,
                'key' => 'module.overtime',
                'value' => $row->value,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Intentionally a no-op. Rows created here are indistinguishable from a
        // module.overtime override a tenant admin may have set (or changed) afterwards,
        // so removing them on rollback risks deleting a deliberate later choice. Leaving
        // them in place is non-destructive and keeps the resolved entitlement stable.
    }
};
