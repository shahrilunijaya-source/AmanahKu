<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kpi_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->enum('category', ['results', 'execution', 'behaviour', 'development'])->default('results');
            $table->string('target')->nullable();
            $table->string('actual')->nullable();
            $table->unsignedTinyInteger('progress')->default(0);
            $table->string('weight', 8)->nullable();
            $table->enum('status', ['green', 'amber', 'red'])->default('amber');
            $table->timestamps();
        });

        Schema::create('onboarding_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('mentor_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('manager_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->date('start_date');
            $table->unsignedSmallInteger('day_number')->default(0);
            $table->unsignedSmallInteger('total_days')->default(90);
            $table->timestamps();
        });

        Schema::create('onboarding_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('onboarding_profile_id')->constrained()->cascadeOnDelete();
            $table->enum('track', ['general', 'position'])->default('general');
            $table->string('title');
            $table->boolean('done')->default(false);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('onboarding_tasks');
        Schema::dropIfExists('onboarding_profiles');
        Schema::dropIfExists('kpi_items');
    }
};
