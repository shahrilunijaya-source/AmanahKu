<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('mystery_awards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->date('month');
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('category', 80);
            $table->string('explanation', 500);
            $table->foreignId('picked_by')->constrained('employees')->cascadeOnDelete();
            $table->dateTime('published_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'month'], 'mystery_awards_unique');
        });

        Schema::create('mystery_committee', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->date('month');
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['tenant_id', 'month', 'employee_id'], 'mystery_committee_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mystery_committee');
        Schema::dropIfExists('mystery_awards');
    }
};
