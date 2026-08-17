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
        // 1. Release the foreign key pinning sub_pillar_id, whichever table it
        //    currently references, and clear any leftover `sub_pillars` from a
        //    partial run. MySQL has no transactional DDL: every abort path below
        //    (the orphan check, the name self-check, a duplicate key on the new
        //    unique index) leaves `sub_pillars` created and partly filled, and no
        //    row in `migrations`. The FK drop is conditional rather than the
        //    plain array-form `dropForeign` this used to be: most of those abort
        //    paths leave no FK here at all (dropForeign throws when there's
        //    nothing to drop, which would otherwise block a re-run), but step 6
        //    below is two separate ALTER TABLE statements — a crash between them
        //    (killed deploy, lock timeout) can leave one table's FK already
        //    pointing at `sub_pillars` instead of `project_sub_pillars`.
        foreach (['timesheet_entries', 'timesheet_templates'] as $table) {
            $this->dropSubPillarForeignIfExists($table);
        }
        Schema::dropIfExists('sub_pillars');

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
        //    row was active; sort is the lowest source sort. Plain Eloquent/
        //    query-builder so the migration runs on sqlite too — 72 rows, speed
        //    is irrelevant.
        $newIdByKey = [];   // "tenantId|folded name" => new id
        $newIdByOldId = []; // old project_sub_pillars.id => new sub_pillars.id
        $oldNameByOldId = [];
        $sortByKey = [];    // "tenantId|folded name" => lowest sort seen so far

        foreach (DB::table('project_sub_pillars')->orderBy('id')->get() as $old) {
            $name = trim($old->name);
            $key = $old->tenant_id.'|'.$this->foldName($name);
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
                $sortByKey[$key] = $old->sort;
            } else {
                $updates = [];

                if ($old->is_active) {
                    $updates['is_active'] = true;
                }

                if ($old->sort < $sortByKey[$key]) {
                    $sortByKey[$key] = $old->sort;
                    $updates['sort'] = $old->sort;
                }

                if ($updates !== []) {
                    DB::table('sub_pillars')->where('id', $newIdByKey[$key])->update($updates);
                }
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
     * Reverse by name, matched through each row's own project_id — both tables
     * carry one, so this is not a name-only lookup. A name-only match would
     * collapse every one of Unijaya's 24 projects' "Management" onto whichever
     * project happened to have the lowest old id, and the restored project-
     * scoped check in the old code would then reject re-saving any of those
     * entries.
     *
     * A row whose project_id is null falls back to the tenant's lowest old id
     * for that name — there is no per-project ancestor to prefer. A row whose
     * own project has no old sub-pillar of that name is left null rather than
     * guessed from a different project. That is also what happens to a
     * `sub_pillars` row created after `up()` ran: it has no ancestor in
     * `project_sub_pillars` at all, so every entry pointing at it nulls out
     * here too. Both are the honest boundary of what a name-based reverse can
     * recover, not a bug.
     */
    public function down(): void
    {
        Schema::table('timesheet_entries', function (Blueprint $table) {
            $table->dropForeign(['sub_pillar_id']);
        });
        Schema::table('timesheet_templates', function (Blueprint $table) {
            $table->dropForeign(['sub_pillar_id']);
        });

        $oldIdByProjectKey = []; // "tenantId|projectId|folded name" => old id
        $oldIdByNameKey = [];    // "tenantId|folded name" => old id (project_id IS NULL rows only)

        foreach (DB::table('project_sub_pillars')->orderBy('id')->get() as $old) {
            $folded = $this->foldName(trim($old->name));
            $oldIdByProjectKey["{$old->tenant_id}|{$old->project_id}|{$folded}"] ??= $old->id;
            $oldIdByNameKey["{$old->tenant_id}|{$folded}"] ??= $old->id;
        }

        foreach (['timesheet_entries', 'timesheet_templates'] as $table) {
            $rows = DB::table($table)->whereNotNull('sub_pillar_id')->get(['id', 'sub_pillar_id', 'project_id']);

            foreach ($rows as $row) {
                $new = DB::table('sub_pillars')->where('id', $row->sub_pillar_id)->first();
                $oldId = null;

                if ($new) {
                    $folded = $this->foldName(trim($new->name));
                    $oldId = $row->project_id !== null
                        ? ($oldIdByProjectKey["{$new->tenant_id}|{$row->project_id}|{$folded}"] ?? null)
                        : ($oldIdByNameKey["{$new->tenant_id}|{$folded}"] ?? null);
                }

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

    /**
     * Drop the FK on {$table}.sub_pillar_id if one currently exists, regardless
     * of which table it references. Plain `dropForeign(['sub_pillar_id'])`
     * throws when there is no such FK, which is exactly the state left by every
     * abort path in up() except a crash mid-way through step 6 — see the step 1
     * comment above.
     */
    private function dropSubPillarForeignIfExists(string $table): void
    {
        $hasForeign = collect(Schema::getForeignKeys($table))
            ->contains(fn (array $fk) => $fk['columns'] === ['sub_pillar_id']);

        if ($hasForeign) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropForeign(['sub_pillar_id']);
            });
        }
    }

    /**
     * Lowercase AND strip accents, so this PHP-side dedup/match key groups the
     * same names the database will. `sub_pillars.name`'s unique index runs
     * under utf8mb4_unicode_ci, which is accent-insensitive; mb_strtolower
     * alone is not, and two names differing only by an accent would pass a
     * plain-lowercase dedup and then collide on insert at that index.
     */
    private function foldName(string $name): string
    {
        $decomposed = normalizer_normalize($name, Normalizer::FORM_D) ?: $name;

        return mb_strtolower(preg_replace('/\p{Mn}/u', '', $decomposed) ?? $decomposed);
    }
};
