<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Worksy Family tab: parents, spouse, children, other dependents. One row each. */
    public function up(): void
    {
        Schema::create('employee_family_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('relation', 16); // father, mother, spouse, child, dependent
            $table->string('name', 160);
            $table->string('phone', 40)->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('nric', 40)->nullable();
            $table->string('occupation', 16)->nullable(); // student, unemployed, working
            $table->string('employer_name', 160)->nullable();
            $table->date('marriage_date')->nullable();
            $table->string('education', 80)->nullable();
            $table->string('gender', 10)->nullable();
            $table->string('nationality', 80)->nullable();
            $table->boolean('deceased')->default(false);
            $table->string('address', 255)->nullable();
            $table->text('remark')->nullable();
            $table->timestamps();
            $table->index(['employee_id', 'relation']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_family_members');
    }
};
