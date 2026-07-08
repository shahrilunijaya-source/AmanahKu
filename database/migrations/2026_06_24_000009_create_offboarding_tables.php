<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offboarding_cases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->date('last_day');
            $table->enum('reason', ['resignation', 'end_of_contract', 'termination', 'retirement'])->default('resignation');
            $table->enum('status', ['in_progress', 'completed'])->default('in_progress');
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('clearance_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('offboarding_case_id')->constrained()->cascadeOnDelete();
            $table->enum('department', ['IT', 'HR', 'Finance', 'Manager', 'Admin'])->default('HR');
            $table->string('title');
            $table->boolean('done')->default(false);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clearance_items');
        Schema::dropIfExists('offboarding_cases');
    }
};
