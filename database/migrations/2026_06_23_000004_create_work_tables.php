<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->enum('type', ['assignment', 'task', 'adhoc'])->default('task');
            $table->enum('status', ['todo', 'prog', 'review', 'done'])->default('todo');
            $table->enum('priority', ['high', 'medium', 'low'])->nullable();
            $table->date('due_at')->nullable();
            $table->string('due_label')->nullable();
            $table->unsignedSmallInteger('estimate_hours')->nullable();
            $table->unsignedTinyInteger('progress')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_items');
    }
};
