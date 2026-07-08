<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->enum('type', ['mileage', 'medical', 'expense', 'travel', 'other'])->default('expense');
            $table->string('title');
            $table->decimal('amount', 10, 2)->default(0);
            $table->string('currency', 4)->default('RM');
            $table->date('date');
            $table->text('reason')->nullable();
            $table->enum('status', ['submitted', 'approved', 'rejected', 'paid'])->default('submitted');
            $table->timestamps();
        });

        Schema::create('assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->enum('category', ['laptop', 'phone', 'vehicle', 'furniture', 'other'])->default('other');
            $table->string('serial')->nullable();
            $table->enum('status', ['assigned', 'available', 'maintenance', 'retired'])->default('available');
            $table->date('assigned_at')->nullable();
            $table->timestamps();
        });

        Schema::create('training_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('course');
            $table->string('provider')->nullable();
            $table->enum('status', ['not_started', 'in_progress', 'completed'])->default('not_started');
            $table->boolean('mandatory')->default(false);
            $table->date('due_at')->nullable();
            $table->date('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('training_records');
        Schema::dropIfExists('assets');
        Schema::dropIfExists('claims');
    }
};
