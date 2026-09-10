<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * S10 fix-up: the `2026_09_15_100000_create_project_variations` migration had already
 * been applied to the dev database with `delta decimal(14,2)` before the column type was
 * changed to `string(20)` in that migration's source (see docs/build/OPEN.md "S10 / CR-06b
 * / delta column type deviates from the QA-fixed shape" for why: sqlite's NUMERIC affinity
 * silently strips the ".00" off a whole-number decimal(14,2) value, which the frozen
 * tests/Acceptance/CR06bTest.php reads back raw and asserts exactly). Editing an
 * already-ran migration's file does not change what is on disk, so this migration brings
 * the dev database's column in line with the (now-corrected) source file. The table was
 * empty at the time this ran (verified via a read-only query before writing it), so there
 * is nothing to preserve on the way across.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_variations', function (Blueprint $table): void {
            $table->string('delta', 20)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('project_variations', function (Blueprint $table): void {
            $table->decimal('delta', 14, 2)->nullable()->change();
        });
    }
};
