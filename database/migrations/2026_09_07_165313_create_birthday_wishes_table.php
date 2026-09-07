<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('birthday_wishes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('author_id')->constrained('employees')->cascadeOnDelete();
            $table->string('body', 280);
            // The recipient's own thank-you reply, pinned above the wishes it answers.
            $table->boolean('is_thanks')->default(false);
            // The real birthday date (this year), not "today" — lets the profile Wall
            // group wishes by year even though the band that produced them showed on
            // the last working day before a weekend/holiday birthday.
            $table->date('celebrated_on');
            $table->timestamps();

            $table->index(['tenant_id', 'employee_id', 'celebrated_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('birthday_wishes');
    }
};
