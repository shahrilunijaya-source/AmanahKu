<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Company onboarding content library. One row = the content shown for a standard
        // checklist item (keyed by item_key). position_id NULL is the company-wide default;
        // a row with a position_id overrides the default for hires in that position. General
        // items only ever use the NULL default; position items may add per-position overrides.
        Schema::create('onboarding_resources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('item_key');                                   // slug of the standard checklist item
            $table->foreignId('position_id')->nullable()                  // NULL = default; else per-position override
                ->constrained('positions')->cascadeOnDelete();
            $table->text('body')->nullable();                             // rich/plain text shown inline
            $table->string('video_url')->nullable();                      // YouTube/Vimeo/link, embedded when possible
            $table->string('file_path')->nullable();                      // uploaded attachment (private disk)
            $table->string('file_name')->nullable();                      // original filename for download
            $table->boolean('requires_ack')->default(false);             // hire must acknowledge to complete
            $table->timestamps();

            // One content record per (tenant, item, position). NULL positions are distinct in
            // MySQL, so the "single default per item" rule is also enforced in code via
            // updateOrCreate keyed on a NULL position_id — this index covers the override rows.
            $table->unique(['tenant_id', 'item_key', 'position_id']);
            $table->index('tenant_id');
        });

        // Link a seeded/standard task row back to its content library entry. Ad-hoc tasks
        // added by HR leave this NULL (no standard content).
        Schema::table('onboarding_tasks', function (Blueprint $table) {
            $table->string('item_key')->nullable()->after('title');
        });
        // The item_key backfill that lived here was removed with the onboarding module
        // (it read the deleted OnboardingService); every existing database already ran it.
    }

    public function down(): void
    {
        Schema::table('onboarding_tasks', function (Blueprint $table) {
            $table->dropColumn('item_key');
        });
        Schema::dropIfExists('onboarding_resources');
    }
};
