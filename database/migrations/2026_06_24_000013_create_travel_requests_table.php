<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('travel_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('destination');
            $table->text('purpose');
            $table->date('depart_date');
            $table->date('return_date');
            $table->enum('transport', ['flight', 'car', 'train', 'other'])->default('flight');
            $table->decimal('estimated_cost', 10, 2)->nullable();
            $table->enum('status', ['submitted', 'approved', 'rejected'])->default('submitted');
            $table->foreignId('approved_by_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('travel_requests');
    }
};
