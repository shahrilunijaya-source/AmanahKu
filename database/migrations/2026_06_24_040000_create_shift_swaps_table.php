<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shift_swaps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shift_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requester_employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('counterpart_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->enum('status', ['requested', 'accepted', 'approved', 'rejected', 'cancelled'])->default('requested');
            $table->timestamp('decided_at')->nullable();
            $table->foreignId('decided_by_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index('shift_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shift_swaps');
    }
};
