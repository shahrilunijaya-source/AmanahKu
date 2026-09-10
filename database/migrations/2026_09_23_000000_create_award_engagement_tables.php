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
        Schema::create('award_nominations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->date('month');
            $table->string('award_key', 40);
            $table->foreignId('nominator_employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('nominee_employee_id')->constrained('employees')->cascadeOnDelete();
            $table->string('reason');
            $table->timestamps();
            $table->unique(['tenant_id', 'month', 'award_key', 'nominator_employee_id'], 'award_nominations_unique');
        });

        Schema::create('award_reactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('award_result_id')->constrained('award_results')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->string('emoji', 40);
            $table->timestamps();
            $table->unique(['award_result_id', 'employee_id', 'emoji']);
        });

        Schema::create('award_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('award_result_id')->constrained('award_results')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->text('body');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('award_comments');
        Schema::dropIfExists('award_reactions');
        Schema::dropIfExists('award_nominations');
    }
};
