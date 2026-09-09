<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('wrapped_stories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->date('month');
            $table->foreignId('employee_id')->nullable()->constrained('employees')->cascadeOnDelete();
            $table->json('cards');
            $table->string('arc_title', 120)->nullable();
            $table->dateTime('shared_at')->nullable();
            $table->dateTime('built_at');
            $table->timestamps();
            $table->index(['tenant_id', 'month', 'employee_id'], 'wrapped_stories_lookup');
        });

        Schema::create('wrapped_arcs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('title', 120);
            $table->string('rule', 20);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('wrapped_reactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('wrapped_story_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('reaction', 40);
            $table->timestamps();
            $table->unique(['wrapped_story_id', 'employee_id'], 'wrapped_reactions_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('wrapped_reactions');
        Schema::dropIfExists('wrapped_arcs');
        Schema::dropIfExists('wrapped_stories');
    }
};
