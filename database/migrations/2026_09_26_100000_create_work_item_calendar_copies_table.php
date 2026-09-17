<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A tagged Helper/FYI's own copy of a card in their Google Calendar. The owner's entry
 * stays on work_items; this holds everyone else's, one row per person per card.
 * `revoked_at` marks a connection Google has stopped honouring (invalid_grant).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_item_calendar_copies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('work_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('google_event_id')->nullable();
            $table->string('calendar_version', 64)->nullable();
            $table->string('sync_error', 500)->nullable();
            $table->timestamps();
            $table->unique(['work_item_id', 'employee_id']);
            $table->index(['employee_id', 'google_event_id']);
        });

        Schema::table('google_calendar_connections', function (Blueprint $table) {
            $table->timestamp('revoked_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('google_calendar_connections', function (Blueprint $table) {
            $table->dropColumn('revoked_at');
        });
        Schema::dropIfExists('work_item_calendar_copies');
    }
};
