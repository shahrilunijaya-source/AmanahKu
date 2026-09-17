<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_work_site', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('work_site_id')->constrained('work_sites')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['employee_id', 'work_site_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_work_site');
    }
};
