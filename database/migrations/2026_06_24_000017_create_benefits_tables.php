<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('benefit_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('type')->default('medical');   // medical|dental|life|other
            $table->string('provider')->nullable();
            $table->text('coverage')->nullable();
            $table->decimal('monthly_cost', 10, 2)->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('benefit_enrollments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('benefit_plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('enrolled');   // enrolled|waived
            $table->unsignedInteger('dependents')->default(0);
            $table->date('enrolled_at')->nullable();
            $table->timestamps();

            // One enrollment record per employee per plan.
            $table->unique(['benefit_plan_id', 'employee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('benefit_enrollments');
        Schema::dropIfExists('benefit_plans');
    }
};
