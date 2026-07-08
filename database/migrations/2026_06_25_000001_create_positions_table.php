<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('positions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            // A cell in the org-chart grid: department column x level row.
            $table->string('department_group');           // Admin, HR, Acc, Marketing, Sales, Operation
            $table->string('level');                       // Director, Sr Manager, Manager, Exec, Jr Exec, Intern
            $table->string('title');                       // e.g. "Project Manager", "Sr Developer"
            $table->decimal('max_salary', 12, 2)->default(0);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();

            $table->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('positions');
    }
};
