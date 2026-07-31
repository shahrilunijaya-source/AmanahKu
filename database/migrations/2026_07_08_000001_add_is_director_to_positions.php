<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Flag a position (rank band) as a director band. Any staff assigned to a
 * director-flagged position is pinned to the org chart's leadership band — no
 * login account or tenant `director` role required, so a directory-only director
 * (a staff record with no login) surfaces up top too. Distinct from is_managerial
 * (a costing/authority hint) and from the tenant `director` login role (which the
 * band still honours in parallel).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('positions', function (Blueprint $table) {
            $table->boolean('is_director')->default(false)->after('is_managerial');
        });
    }

    public function down(): void
    {
        Schema::table('positions', function (Blueprint $table) {
            $table->dropColumn('is_director');
        });
    }
};
