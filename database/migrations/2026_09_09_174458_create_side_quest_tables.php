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
        Schema::create('side_quests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('blurb')->nullable();
            $table->string('status', 20)->default('live');
            $table->foreignId('suggested_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('side_quest_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('quest_id')->constrained('side_quests')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->text('note')->nullable();
            $table->string('photo_path')->nullable();
            $table->timestamps();
            $table->unique(['quest_id', 'employee_id']);
        });

        Schema::create('side_quest_badges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('quest_id')->constrained('side_quests')->cascadeOnDelete();
            $table->foreignId('post_id')->constrained('side_quest_posts')->cascadeOnDelete();
            $table->dateTime('earned_at');
            $table->dateTime('expires_at');
        });

        Schema::create('side_quest_reactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained('side_quest_posts')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('reaction', 40);
            $table->timestamps();
            $table->unique(['post_id', 'employee_id', 'reaction']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('side_quest_reactions');
        Schema::dropIfExists('side_quest_badges');
        Schema::dropIfExists('side_quest_posts');
        Schema::dropIfExists('side_quests');
    }
};
