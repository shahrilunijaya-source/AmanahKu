<?php

declare(strict_types=1);

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Special work days: a weekday that works only on certain weeks of the month, with its own
 * hours (e.g. Unijaya's TOT first Saturday, 10:00-14:00). One rule per weekday per company,
 * so two rules can never claim the same date.
 *
 * The data step moves every tenant's old `tot_saturday` flag into a rule and clears the flag,
 * so real data has one source of truth. The rule runs 4 hours from the tenant's first branch's
 * normal start (09:00 when it has no branch), the same half day the flag used to mean.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_day_rules', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->unsignedTinyInteger('weekday');
            $t->json('weeks');
            $t->time('start_time');
            $t->time('end_time');
            $t->string('counts', 8)->default('half');
            $t->timestamps();
            $t->unique(['tenant_id', 'weekday']);
        });

        $now = now();

        foreach (DB::table('tenants')->where('tot_saturday', true)->pluck('id') as $tenantId) {
            $start = Carbon::createFromTimeString(
                DB::table('branches')->where('tenant_id', $tenantId)->whereNotNull('work_start')->orderBy('id')->value('work_start') ?? '09:00:00'
            );

            DB::table('work_day_rules')->insertOrIgnore([
                'tenant_id' => $tenantId,
                'weekday' => 6,
                'weeks' => json_encode([1]),
                'start_time' => $start->format('H:i:s'),
                'end_time' => $start->copy()->addHours(4)->format('H:i:s'),
                'counts' => 'half',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        DB::table('tenants')->where('tot_saturday', true)->update(['tot_saturday' => false]);
    }

    public function down(): void
    {
        $tenantIds = DB::table('work_day_rules')
            ->where('weekday', 6)->where('weeks', json_encode([1]))->where('counts', 'half')
            ->pluck('tenant_id');

        DB::table('tenants')->whereIn('id', $tenantIds)->update(['tot_saturday' => true]);

        Schema::dropIfExists('work_day_rules');
    }
};
