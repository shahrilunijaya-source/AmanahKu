<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('referrals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('referrer_employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('job_requisition_id')->nullable()->constrained()->nullOnDelete();
            $table->string('candidate_name');
            $table->string('candidate_email');
            $table->string('candidate_phone')->nullable();
            $table->string('resume_url')->nullable();
            $table->text('note')->nullable();
            $table->enum('status', ['submitted', 'reviewing', 'interviewing', 'hired', 'rejected'])->default('submitted');
            $table->boolean('bonus_eligible')->default(false);
            $table->enum('bonus_status', ['none', 'pending', 'paid'])->default('none');
            $table->timestamp('decided_at')->nullable();
            $table->foreignId('decided_by_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['referrer_employee_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referrals');
    }
};
