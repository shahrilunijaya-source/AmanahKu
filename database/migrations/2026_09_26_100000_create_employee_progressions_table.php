<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_progressions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('type', 16); // hired | confirmed | updated | resigned | rehired
            $table->date('effective_on');
            $table->json('snapshot');
            $table->json('changed_fields');
            $table->text('remark')->nullable();
            $table->foreignId('recorded_by_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamps();
            $table->index(['employee_id', 'effective_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_progressions');
    }
};
