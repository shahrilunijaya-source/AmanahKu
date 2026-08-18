<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_timesheet_category', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('timesheet_category_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['project_id', 'timesheet_category_id'], 'project_timesheet_category_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_timesheet_category');
    }
};
