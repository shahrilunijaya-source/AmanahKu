<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('timesheets', function (Blueprint $table) {
            // Board cards the staffer struck off a given day, keyed by ISO date:
            // {"2026-08-25": [12, 44]}. Without it a removed row comes straight back on
            // the next load, since the prefill rebuilds itself from the card's stints.
            $table->json('dismissed_suggestions')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('timesheets', function (Blueprint $table) {
            $table->dropColumn('dismissed_suggestions');
        });
    }
};
