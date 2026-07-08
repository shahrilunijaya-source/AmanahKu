<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('disciplinary_cases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('opened_by_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->enum('type', ['warning', 'grievance', 'investigation'])->default('warning');
            $table->enum('severity', ['low', 'medium', 'high'])->default('low');
            $table->string('subject');
            $table->text('details');
            $table->enum('status', ['open', 'investigating', 'resolved', 'closed'])->default('open');
            $table->text('outcome')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['employee_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('disciplinary_cases');
    }
};
