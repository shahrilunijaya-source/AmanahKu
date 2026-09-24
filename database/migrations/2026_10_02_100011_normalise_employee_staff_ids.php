<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One-time renumbering so every staff number reads the way Worksy prints it (UR00004) and
 * runs in join order: the longest-serving person in each company is 00001, ties broken
 * by who was added first, anyone with no join date last. The letters stay the company's
 * own (UR for Unijaya). Old numbers are replaced, including the hand-picked ones.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            foreach (DB::table('employees')->distinct()->pluck('tenant_id') as $tenantId) {
                // Letters the company already uses ("UR" from "UR-0001"), else its initials.
                $prefix = DB::table('employees')->where('tenant_id', $tenantId)->pluck('staff_id')
                    ->map(fn (?string $id) => preg_match('/^\s*([A-Za-z]+)[\s-]*\d+\s*$/', (string) $id, $m) ? strtoupper($m[1]) : null)
                    ->filter()->countBy()->sortDesc()->keys()->first()
                    ?? strtoupper((string) DB::table('tenants')->where('id', $tenantId)->value('initials'));

                DB::table('employees')->where('tenant_id', $tenantId)
                    ->orderByRaw('joined_at is null')->orderBy('joined_at')->orderBy('id')->pluck('id')
                    ->each(fn (int $id, int $i) => DB::table('employees')->where('id', $id)
                        ->update(['staff_id' => $prefix.str_pad((string) ($i + 1), 5, '0', STR_PAD_LEFT)]));
            }
        });
    }

    /** The old numbers aren't kept, so there is nothing to put back. */
    public function down(): void {}
};
