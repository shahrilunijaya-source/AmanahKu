<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Per-user switch for the timesheet capture screen's board prefill. Default
            // true keeps every existing account on today's behaviour; off drops
            // BoardSuggestions entirely and hands the screen its own Add line button.
            $table->boolean('timesheet_fill_from_board')->default(true)->after('dashboard_prefs');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('timesheet_fill_from_board');
        });
    }
};
