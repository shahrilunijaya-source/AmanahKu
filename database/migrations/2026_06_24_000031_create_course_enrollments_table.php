<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('course_enrollments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('enrolled');   // enrolled|in_progress|completed
            $table->unsignedTinyInteger('progress')->default(0);   // 0–100
            $table->date('enrolled_at')->nullable();
            $table->date('completed_at')->nullable();
            $table->timestamps();

            // One enrollment record per employee per course.
            $table->unique(['course_id', 'employee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('course_enrollments');
    }
};
