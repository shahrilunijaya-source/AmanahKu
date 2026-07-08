<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Replace the denormalised free-text positions.department_group / positions.level
 * with real foreign keys so a position's department and level names always reflect
 * the live lookup rows (no more stale copies when an admin renames them).
 *
 * staff_level_id already exists (added in 2026_06_27_000004) but was never populated;
 * department_id is new. Both are backfilled by matching the old text against the
 * tenant's departments / staff_levels, creating any lookup row that doesn't exist yet
 * so no existing position loses its grouping. The text columns are then dropped.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('positions', function (Blueprint $table) {
            $table->foreignId('department_id')->nullable()->after('department_group')
                ->constrained('departments')->nullOnDelete();
        });

        foreach (DB::table('positions')->get() as $pos) {
            DB::table('positions')->where('id', $pos->id)->update([
                'department_id' => $this->resolveLookupId('departments', $pos->tenant_id, $pos->department_group),
                // Honour any staff_level_id already set; otherwise derive it from the text column.
                'staff_level_id' => $pos->staff_level_id
                    ?: $this->resolveLookupId('staff_levels', $pos->tenant_id, $pos->level),
            ]);
        }

        Schema::table('positions', function (Blueprint $table) {
            $table->dropColumn(['department_group', 'level']);
        });
    }

    public function down(): void
    {
        Schema::table('positions', function (Blueprint $table) {
            $table->string('department_group')->default('')->after('tenant_id');
            $table->string('level')->default('')->after('department_group');
        });

        foreach (DB::table('positions')->get() as $pos) {
            DB::table('positions')->where('id', $pos->id)->update([
                'department_group' => DB::table('departments')->where('id', $pos->department_id)->value('name') ?? '',
                'level' => DB::table('staff_levels')->where('id', $pos->staff_level_id)->value('name') ?? '',
            ]);
        }

        Schema::table('positions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('department_id');
        });
    }

    /** Find a tenant's lookup row by name, creating it when missing. Returns null for blank names. */
    private function resolveLookupId(string $table, int $tenantId, ?string $name): ?int
    {
        $name = trim((string) $name);
        if ($name === '') {
            return null;
        }

        $id = DB::table($table)->where('tenant_id', $tenantId)->where('name', $name)->value('id');
        if ($id !== null) {
            return (int) $id;
        }

        return (int) DB::table($table)->insertGetId([
            'tenant_id' => $tenantId,
            'name' => $name,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
};
