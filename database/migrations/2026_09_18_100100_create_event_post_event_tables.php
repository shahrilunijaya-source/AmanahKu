<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * CR-11 post-event sharing: photos, a comment thread (with replies and an optional
     * link to one lesson), reactions (event- or lesson-level, CR-30 keys), and one
     * lesson-learnt row per attendee (mirrored into Knowledge by the controller, kept
     * via knowledge_entry_id — not a foreign key, the Knowledge module may be off for a
     * tenant and the lesson row must not vanish if that entry is ever removed).
     */
    public function up(): void
    {
        Schema::create('event_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->string('caption')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('event_lessons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->text('learnt');
            $table->text('how_to_use')->nullable();
            $table->json('links')->nullable();
            $table->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->unsignedBigInteger('knowledge_entry_id')->nullable();
            $table->timestamps();

            $table->unique(['company_event_id', 'employee_id']);
        });

        Schema::create('event_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('event_comments')->cascadeOnDelete();
            $table->foreignId('lesson_id')->nullable()->constrained('event_lessons')->cascadeOnDelete();
            $table->text('body');
            $table->timestamps();
        });

        Schema::create('event_reactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lesson_id')->nullable()->constrained('event_lessons')->cascadeOnDelete();
            $table->string('reaction', 40);
            $table->timestamps();

            // One active reaction per person per target (event, or one of its lessons).
            $table->unique(['company_event_id', 'employee_id', 'lesson_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_reactions');
        Schema::dropIfExists('event_comments');
        Schema::dropIfExists('event_lessons');
        Schema::dropIfExists('event_photos');
    }
};
