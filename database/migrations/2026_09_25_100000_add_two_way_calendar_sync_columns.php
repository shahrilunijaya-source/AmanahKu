<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CR-01: two-way Google Calendar sync. The connection learns which calendar is the
 * dedicated "Amanahku" one and where the incremental pull left off; a card learns the
 * calendar's own version stamp of our last push (loop protection) and the last sync
 * failure after retries ran out ("Sync issues").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('google_calendar_connections', function (Blueprint $table) {
            $table->string('calendar_id')->nullable()->after('expires_at');
            $table->text('sync_token')->nullable()->after('calendar_id');
            $table->timestamp('last_pulled_at')->nullable()->after('sync_token');
        });

        Schema::table('work_items', function (Blueprint $table) {
            $table->string('calendar_version', 64)->nullable()->after('google_event_id');
            $table->string('calendar_sync_error', 500)->nullable()->after('calendar_version');
        });
    }

    public function down(): void
    {
        Schema::table('work_items', function (Blueprint $table) {
            $table->dropColumn(['calendar_version', 'calendar_sync_error']);
        });
        Schema::table('google_calendar_connections', function (Blueprint $table) {
            $table->dropColumn(['calendar_id', 'sync_token', 'last_pulled_at']);
        });
    }
};
