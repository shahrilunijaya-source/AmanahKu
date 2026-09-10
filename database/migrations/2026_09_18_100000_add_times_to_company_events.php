<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * CR-11: an event needs a real start/end moment so "after the event" (post-event
     * sharing) and the Google Calendar sync both have something exact to work from.
     * `start_time` (free text) stays for legacy rows; `event_date` stays the display
     * date and is derived from `starts_at` by the controller when one is given.
     */
    public function up(): void
    {
        Schema::table('company_events', function (Blueprint $table) {
            $table->dateTime('starts_at')->nullable()->after('start_time');
            $table->dateTime('ends_at')->nullable()->after('starts_at');
        });
    }

    public function down(): void
    {
        Schema::table('company_events', function (Blueprint $table) {
            $table->dropColumn(['starts_at', 'ends_at']);
        });
    }
};
