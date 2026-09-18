<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every employee's own copy of a company event in their Google Calendar, whether or
 * not they RSVP'd. An RSVP'd attendee already gets an entry through their event card
 * (work_item_calendar_copies / work_items), so this table only ever holds one row per
 * non-attending recipient per event — see App\Support\Calendar\CompanyEventCopies.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_event_calendar_copies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('google_event_id')->nullable();
            $table->string('calendar_version', 64)->nullable();
            $table->string('sync_error', 500)->nullable();
            $table->timestamps();
            $table->unique(['company_event_id', 'employee_id'], 'company_event_copies_event_employee_unique');
            $table->index(['employee_id', 'google_event_id'], 'company_event_copies_employee_google_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_event_calendar_copies');
    }
};
