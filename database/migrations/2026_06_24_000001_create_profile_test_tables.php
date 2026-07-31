<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Profile Test — a self-service personality instrument ported from the Hiring
 * platform (working-style animal archetype + colour icebreakers + self-declared
 * fields). Questions/options are a GLOBAL instrument (one set for everyone, same
 * as Hiring) so they are not tenant-scoped. Results are per-employee.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('profile_test_questions', function (Blueprint $table) {
            $table->id();
            $table->enum('section', ['working_style', 'colour']);
            $table->text('prompt_en')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('profile_test_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_test_question_id')->constrained()->cascadeOnDelete();
            $table->string('label_en')->nullable();
            $table->enum('animal', ['rabbit', 'tortoise', 'fox', 'sloth'])->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('profile_test_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->unique()->constrained()->cascadeOnDelete();
            $table->text('self_goal')->nullable();
            $table->text('self_strengths')->nullable();
            $table->text('self_weaknesses')->nullable();
            $table->text('self_interests')->nullable();
            $table->string('self_mbti')->nullable();
            $table->json('working_style_answers')->nullable();
            $table->json('colour_answers')->nullable();
            $table->enum('animal_archetype', ['rabbit', 'tortoise', 'fox', 'sloth'])->nullable();
            $table->json('totals')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('profile_test_results');
        Schema::dropIfExists('profile_test_options');
        Schema::dropIfExists('profile_test_questions');
    }
};
