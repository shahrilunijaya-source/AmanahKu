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
        Schema::create('award_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->date('month');
            $table->string('award_key', 40);
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->decimal('value', 10, 2);
            $table->string('label');
            $table->dateTime('frozen_at');
            $table->timestamps();
            $table->unique(['tenant_id', 'month', 'award_key', 'employee_id'], 'award_snapshots_unique');
        });

        Schema::create('award_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->date('month');
            $table->string('award_key', 40);
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->decimal('value', 10, 2);
            $table->string('label');
            $table->string('source', 10)->default('auto');
            $table->string('reason')->nullable();
            $table->dateTime('published_at');
            $table->timestamps();
            $table->unique(['tenant_id', 'month', 'award_key', 'employee_id'], 'award_results_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('award_results');
        Schema::dropIfExists('award_snapshots');
    }
};
