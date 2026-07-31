<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('courses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('category')->default('Technical');   // Technical|Leadership|Compliance|Soft Skills
            $table->string('provider')->nullable();
            $table->text('description')->nullable();
            $table->string('level')->default('Beginner');        // Beginner|Intermediate|Advanced
            $table->decimal('duration_hours', 5, 1)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('courses');
    }
};
