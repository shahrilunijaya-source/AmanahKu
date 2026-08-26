<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Data backfill (NOT a schema change): `staff_levels.rank` ships nullable and every row
 * in both tenants has been sitting null — nothing in the UI ever wrote it (see the
 * settings.blade.php form fix in the same change) and the CSV-import auto-create path
 * (PositionController::store()) never sets it either.
 *
 * The profile-visibility "outranks" rule (BuildsPeopleData::profileData()) treats a null
 * rank on either side as "rule cannot apply" (fail closed), so a null rank costs nothing
 * — but the six standard level names ARE the common case, and leaving them null makes
 * that rule dead code for every tenant that hasn't manually filled ranks in via the new
 * settings field. This backfills only those six known names, matched by name alone, one
 * tenant or six tenants at a time — a tenant that renamed or replaced these levels with
 * its own set is untouched, and any row that already has a rank (set by hand, or by a
 * previous run of this migration) is left alone.
 */
return new class extends Migration
{
    private const RANKS = [
        'Director' => 1,
        'Sr Manager' => 2,
        'Manager' => 3,
        'Exec' => 4,
        'Jr Exec' => 5,
        'Intern' => 6,
    ];

    public function up(): void
    {
        foreach (self::RANKS as $name => $rank) {
            DB::table('staff_levels')
                ->where('name', $name)
                ->whereNull('rank')
                ->update(['rank' => $rank]);
        }
    }

    public function down(): void
    {
        // Intentionally a no-op. A row this backfilled is indistinguishable from one an
        // admin has since edited by hand via the settings screen (same rank value is
        // plausible either way), so nulling it back out on rollback risks discarding a
        // deliberate later edit. Leaving it in place is non-destructive.
    }
};
