<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Stage 3 (Intelligent HR) is dropped. Its only stage-3 module, module.ai, ships off
 * by default (Features::OFF) and there is no AI package to sell, so Stage 3 has never
 * switched on anything a Stage 2 company doesn't already get. Every tenant and every
 * signup link on Stage 3 moves to Stage 2 first. This only repoints the category id,
 * it does not touch tenant_features, so nobody's existing feature ticks change.
 */
return new class extends Migration
{
    public function up(): void
    {
        $stage2Id = DB::table('company_categories')->where('key', 'stage-2')->value('id');
        $stage3Id = DB::table('company_categories')->where('key', 'stage-3')->value('id');

        if ($stage3Id === null || $stage2Id === null) {
            return;
        }

        DB::table('tenants')->where('company_category_id', $stage3Id)->update(['company_category_id' => $stage2Id]);
        DB::table('company_invites')->where('company_category_id', $stage3Id)->update(['company_category_id' => $stage2Id]);

        DB::table('company_categories')->where('id', $stage3Id)->delete();
    }

    public function down(): void
    {
        // Only re-creates the row. Tenants and invites that up() repointed to Stage 2
        // are NOT moved back to Stage 3: there is no record of which ones they were.
        if (DB::table('company_categories')->where('key', 'stage-3')->exists()) {
            return;
        }

        $now = now();
        DB::table('company_categories')->insert([
            'key' => 'stage-3', 'level' => 3,
            'name' => 'Stage 3 — Intelligent HR',
            'description' => 'Everything in Stage 1 and 2 plus AI HR assistant, AI insights, workflow automation, predictive analytics and management dashboards.',
            'created_at' => $now, 'updated_at' => $now,
        ]);
    }
};
