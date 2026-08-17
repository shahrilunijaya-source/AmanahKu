<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sub-pillars stop belonging to a project. Every one of Unijaya's 24 project
     * records carried the identical three (Management / Meeting / Technical), so
     * the per-project shape was 24 copies of one company-wide list.
     *
     * Existing timesheet history is repointed by NAME, never by id: old ids run
     * 1..~72 and new ids run 1..~6, so they overlap and an id-based remap would
     * silently rewrite history. `project_sub_pillars` is left in place, unused —
     * it is the rollback, and a later migration drops it once staging and
     * production have both run clean.
     */
    public function up(): void
    {
        // 1. Release the foreign keys that pin these columns to the old table.
        //    Array form (not the string name) — the string form throws on sqlite.
        Schema::table('timesheet_entries', function (Blueprint $table) {
            $table->dropForeign(['sub_pillar_id']);
        });
        Schema::table('timesheet_templates', function (Blueprint $table) {
            $table->dropForeign(['sub_pillar_id']);
        });

        // 2. The new home.
        Schema::create('sub_pillars', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 160);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'name']);
        });

        // 3. Backfill: one row per distinct name per tenant. Active if ANY source
        //    row was active; earliest sort wins. Plain Eloquent/query-builder so
        //    the migration runs on sqlite too — 72 rows, speed is irrelevant.
        $newIdByKey = [];   // "tenantId|lowered name" => new id
        $newIdByOldId = []; // old project_sub_pillars.id => new sub_pillars.id
        $oldNameByOldId = [];

        foreach (DB::table('project_sub_pillars')->orderBy('id')->get() as $old) {
            $name = trim($old->name);
            $key = $old->tenant_id.'|'.mb_strtolower($name);
            $oldNameByOldId[$old->id] = $name;

            if (! isset($newIdByKey[$key])) {
                $newIdByKey[$key] = DB::table('sub_pillars')->insertGetId([
                    'tenant_id' => $old->tenant_id,
                    'name' => $name,
                    'is_active' => (bool) $old->is_active,
                    'sort' => $old->sort,
                    'created_at' => $old->created_at,
                    'updated_at' => $old->updated_at,
                ]);
            } elseif ($old->is_active) {
                DB::table('sub_pillars')->where('id', $newIdByKey[$key])->update(['is_active' => true]);
            }

            $newIdByOldId[$old->id] = $newIdByKey[$key];
        }

        // 4. Repoint history. Read every old value BEFORE writing any new one and
        //    update row by primary key, so a row already carrying new id 3 is never
        //    caught again when old id 3 comes round.
        foreach (['timesheet_entries', 'timesheet_templates'] as $table) {
            $rows = DB::table($table)->whereNotNull('sub_pillar_id')->get(['id', 'sub_pillar_id']);

            foreach ($rows as $row) {
                if (! isset($newIdByOldId[$row->sub_pillar_id])) {
                    throw new RuntimeException(
                        "{$table}.id={$row->id} points at sub_pillar_id={$row->sub_pillar_id}, which has no row in project_sub_pillars. Aborting before any data is lost."
                    );
                }

                DB::table($table)->where('id', $row->id)
                    ->update(['sub_pillar_id' => $newIdByOldId[$row->sub_pillar_id]]);
            }

            // 5. Self-check: every repointed row still names the same sub-pillar.
            foreach ($rows as $row) {
                $newName = DB::table($table)
                    ->join('sub_pillars', 'sub_pillars.id', '=', $table.'.sub_pillar_id')
                    ->where($table.'.id', $row->id)
                    ->value('sub_pillars.name');

                if ($newName !== $oldNameByOldId[$row->sub_pillar_id]) {
                    throw new RuntimeException(
                        "{$table}.id={$row->id} was '{$oldNameByOldId[$row->sub_pillar_id]}' and is now '".($newName ?? 'NULL')."'. Aborting."
                    );
                }
            }
        }

        // 6. Pin the columns to the new table.
        Schema::table('timesheet_entries', function (Blueprint $table) {
            $table->foreign('sub_pillar_id')->references('id')->on('sub_pillars')->nullOnDelete();
        });
        Schema::table('timesheet_templates', function (Blueprint $table) {
            $table->foreign('sub_pillar_id')->references('id')->on('sub_pillars')->nullOnDelete();
        });
    }

    /**
     * Reverse by name — both tables still exist, so every new row can find the
     * old row it came from within the same tenant.
     */
    public function down(): void
    {
        Schema::table('timesheet_entries', function (Blueprint $table) {
            $table->dropForeign(['sub_pillar_id']);
        });
        Schema::table('timesheet_templates', function (Blueprint $table) {
            $table->dropForeign(['sub_pillar_id']);
        });

        $oldIdByKey = [];
        foreach (DB::table('project_sub_pillars')->orderBy('id')->get() as $old) {
            $oldIdByKey[$old->tenant_id.'|'.mb_strtolower(trim($old->name))] ??= $old->id;
        }

        foreach (['timesheet_entries', 'timesheet_templates'] as $table) {
            $rows = DB::table($table)->whereNotNull('sub_pillar_id')->get(['id', 'sub_pillar_id']);

            foreach ($rows as $row) {
                $new = DB::table('sub_pillars')->where('id', $row->sub_pillar_id)->first();
                $oldId = $new ? ($oldIdByKey[$new->tenant_id.'|'.mb_strtolower(trim($new->name))] ?? null) : null;

                DB::table($table)->where('id', $row->id)->update(['sub_pillar_id' => $oldId]);
            }
        }

        Schema::dropIfExists('sub_pillars');

        Schema::table('timesheet_entries', function (Blueprint $table) {
            $table->foreign('sub_pillar_id')->references('id')->on('project_sub_pillars')->nullOnDelete();
        });
        Schema::table('timesheet_templates', function (Blueprint $table) {
            $table->foreign('sub_pillar_id')->references('id')->on('project_sub_pillars')->nullOnDelete();
        });
    }
};
