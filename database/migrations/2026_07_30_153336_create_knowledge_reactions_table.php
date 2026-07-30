<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per person per emoji per Knowledge Bank entry — mirrors tot_reactions.
        // The unique key is what makes a repeat POST a toggle rather than a duplicate.
        Schema::create('knowledge_reactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('entry_id')->constrained('knowledge_entries')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->string('emoji', 16);
            $table->timestamps();

            $table->unique(['entry_id', 'employee_id', 'emoji']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_reactions');
    }
};
