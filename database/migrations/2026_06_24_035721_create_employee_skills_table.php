<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_skills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('skill_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('level')->default(1);   // 1 (Novice) – 5 (Expert)
            $table->boolean('verified')->default(false);
            $table->foreignId('verified_by_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamp('self_rated_at')->nullable();
            $table->timestamps();

            // One rating row per employee per skill.
            $table->unique(['skill_id', 'employee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_skills');
    }
};
