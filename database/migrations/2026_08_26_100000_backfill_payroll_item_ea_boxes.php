<?php

use App\Models\PayrollItem;
use App\Models\Tenant;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * The 2026-08-25 migration already ran PayrollItem::seedFor() for every tenant
     * before ea_box got real values in PayrollItem::SYSTEM_ITEMS — every system item on
     * every existing tenant still has ea_box = NULL. Re-running seedFor() (now that it
     * also backfills ea_box, see the method's docblock) gives existing tenants the same
     * boxes a brand-new tenant gets, without ever touching a row HR has customised.
     */
    public function up(): void
    {
        foreach (Tenant::all() as $tenant) {
            PayrollItem::seedFor($tenant);
        }
    }

    public function down(): void
    {
        // Deliberately empty — same reasoning as the 2026-08-25 seeding migration: HR may
        // have relied on these boxes by the time this rolls back.
    }
};
