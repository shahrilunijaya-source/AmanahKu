<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The live setup guide (dock, sidebar ring, coachmark pointer) shows on every screen
 * while company_setup_progress.completed_at is null. Every company that already runs
 * on Amanahku — Unijaya and any live tenant — would otherwise get walked through a
 * setup it finished long ago. "Already has staff" is the same threshold the Launch
 * Center's staff step uses (more than one non-archived employee: the first HR alone
 * does not count), so a company freshly provisioned by a superadmin still gets the
 * guide. Not reversible: down() cannot know which rows it stamped.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $liveTenantIds = DB::table('employees')
            ->whereNull('archived_at')
            ->select('tenant_id')
            ->groupBy('tenant_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('tenant_id');

        if ($liveTenantIds->isEmpty()) {
            return;
        }

        DB::table('company_setup_progress')
            ->whereIn('tenant_id', $liveTenantIds)
            ->whereNull('completed_at')
            ->update(['completed_at' => $now, 'updated_at' => $now]);

        $existing = DB::table('company_setup_progress')->whereIn('tenant_id', $liveTenantIds)->pluck('tenant_id');
        $rows = $liveTenantIds->diff($existing)->map(fn ($tenantId) => [
            'tenant_id' => $tenantId,
            'steps' => '[]',
            'completed_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ])->values()->all();

        if ($rows !== []) {
            DB::table('company_setup_progress')->insert($rows);
        }
    }

    public function down(): void
    {
        // Intentionally empty: which rows were stamped here is not recoverable.
    }
};
