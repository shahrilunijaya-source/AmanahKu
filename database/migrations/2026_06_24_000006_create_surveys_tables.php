<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('surveys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('question');
            $table->string('type')->default('scale');   // scale|enps|text
            $table->string('status')->default('open');   // open|closed
            $table->foreignId('created_by_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('survey_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('survey_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('score')->nullable();
            $table->text('comment')->nullable();
            $table->timestamps();

            // One response per employee per survey.
            $table->unique(['survey_id', 'employee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('survey_responses');
        Schema::dropIfExists('surveys');
    }
};
