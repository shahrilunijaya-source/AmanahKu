<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Placeholder title-only table, replaced by employee_progressions. Dev-seeded only. */
    public function up(): void
    {
        Schema::dropIfExists('career_timeline');
    }

    public function down(): void
    {
        Schema::create('career_timeline', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('date_label')->nullable();
            $table->string('category', 8)->default('muted');
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });
    }
};
