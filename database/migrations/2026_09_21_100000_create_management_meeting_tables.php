<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CR-34 (session S16): the internal half of the Friday management-meeting reminder.
 * `source`/`source_ref` are the generic marker every system-generated card (CR-19, CR-14a)
 * reads to exclude itself from manual-card rules and awards — null on every existing and
 * every future manually-created row. `management_meeting_settings` is the one-row-per-tenant
 * config for the meeting day/time, the two reminder times, the attendee role set and an HR
 * pause; a tenant with no row yet reads the spec's own defaults (Friday, 17:00/15:00/08:00,
 * manager+hr+management+director).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_items', function (Blueprint $table) {
            $table->string('source', 40)->nullable()->after('labels');
            $table->string('source_ref', 40)->nullable()->after('source');
        });

        Schema::create('management_meeting_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete()->unique();
            $table->unsignedTinyInteger('meeting_day')->default(5);
            $table->string('meeting_time', 5)->default('17:00');
            $table->string('reminder_time', 5)->default('15:00');
            $table->string('task_time', 5)->default('08:00');
            $table->json('attendee_roles')->nullable();
            $table->date('paused_until')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::table('work_items', function (Blueprint $table) {
            $table->dropColumn(['source', 'source_ref']);
        });
        Schema::dropIfExists('management_meeting_settings');
    }
};
