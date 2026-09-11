<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('victory_bells', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('work_item_id')->constrained('work_items')->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->foreignId('rung_by')->constrained('employees')->cascadeOnDelete();
            $table->string('line', 160)->nullable();
            $table->dateTime('rung_at');
            $table->timestamps();
        });

        Schema::create('victory_bell_reactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('victory_bell_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->string('reaction', 40);
            $table->timestamps();
            $table->unique(['victory_bell_id', 'employee_id', 'reaction'], 'victory_bell_reactions_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('victory_bell_reactions');
        Schema::dropIfExists('victory_bells');
    }
};
