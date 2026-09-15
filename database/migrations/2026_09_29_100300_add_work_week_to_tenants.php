<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Per-company working week (docs/superpowers/specs/2026-09-15-self-serve-company-signup-design.md,
 * Change 2). `work_days` is a JSON list of ISO weekdays (1 = Monday .. 7 = Sunday) read through
 * App\Support\WorkWeek. It is stored in a varchar rather than a JSON column because MySQL refuses
 * a literal DEFAULT on JSON columns and the Eloquent 'array' cast reads either.
 *
 * `tot_saturday` is Unijaya's first-Saturday-of-the-month half day. It has no UI: this data step
 * turns it on for the Unijaya tenant (the seeded slug and the production dump's slug) and nothing
 * else ever sets it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('work_days', 32)->default('[1,2,3,4,5]')->after('late_grace_minutes');
            $table->boolean('tot_saturday')->default(false)->after('work_days');
        });

        DB::table('tenants')
            ->whereIn('slug', ['unijaya', 'unijaya-resources-sdn-bhd'])
            ->update(['tot_saturday' => true]);
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['work_days', 'tot_saturday']);
        });
    }
};
