<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Private wellness pulse entries. Sensitive — only queried for own rows
        // or anonymized aggregates; the note is never surfaced to HR.
        Schema::create('wellness_checkins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('mood');     // 1–5
            $table->unsignedTinyInteger('stress');   // 1–5
            $table->text('note')->nullable();        // private to the employee
            $table->date('checkin_date');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wellness_checkins');
    }
};
