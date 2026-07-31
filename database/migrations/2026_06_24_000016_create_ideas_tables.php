<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ideas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();   // author
            $table->string('title');
            $table->text('body');
            $table->string('category')->nullable();   // Process|Workplace|Tech|Other
            $table->string('status')->default('new'); // new|reviewing|accepted|done|declined
            $table->timestamps();
        });

        Schema::create('idea_votes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('idea_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            // One vote per employee per idea.
            $table->unique(['idea_id', 'employee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idea_votes');
        Schema::dropIfExists('ideas');
    }
};
