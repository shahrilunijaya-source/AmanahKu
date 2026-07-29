<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * T.A.A. board redesign, Stage 4, Migration B. Runs after Migration A, and
 * after every UI path that read or wrote `estimate_hours` was already removed
 * in earlier stages, so a rollback at any earlier point cannot strand the
 * schema.
 *
 * The column drop is irreversible: 13 of 49 rows carried a value at the time
 * this was written. `down()` re-adds the column (nullable) so the schema
 * comes back, but the values do not — they only exist in the pre-migration
 * `mysqldump` taken as this stage's safety net.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_items', function (Blueprint $table) {
            $table->dropColumn('estimate_hours');
        });
    }

    /**
     * Re-adds the column, nullable, so the schema matches its pre-migration
     * shape. The data does not come back — that only exists in the
     * pre-migration `mysqldump`, not in anything this migration can read.
     */
    public function down(): void
    {
        Schema::table('work_items', function (Blueprint $table) {
            $table->unsignedSmallInteger('estimate_hours')->nullable()->after('due_label');
        });
    }
};
