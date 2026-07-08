<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('reports_to_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('position')->nullable();
            $table->string('level', 8)->nullable();
            $table->enum('status', ['active', 'probation', 'on_leave', 'resigned'])->default('active');
            $table->enum('workload', ['green', 'amber', 'red', 'grey'])->default('green');
            $table->string('workload_label')->nullable();
            $table->string('avatar_color', 9)->default('#3a6ea5');
            $table->string('photo')->nullable();
            $table->string('initials', 4)->nullable();
            $table->string('staff_id')->nullable();
            $table->date('joined_at')->nullable();
            $table->decimal('leave_balance', 5, 1)->default(0);
            $table->unsignedTinyInteger('kpi_pct')->default(0);
            // Lightweight one-to-one data kept inline as JSON.
            $table->json('skills')->nullable();
            $table->json('interests')->nullable();
            $table->json('personality')->nullable();
            $table->timestamps();
        });

        Schema::create('career_timeline', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('date_label')->nullable();
            $table->enum('category', ['green', 'info', 'muted'])->default('muted');
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('career_timeline');
        Schema::dropIfExists('employees');
    }
};
