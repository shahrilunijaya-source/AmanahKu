<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('birthday_wish_reactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('wish_id')->constrained('birthday_wishes')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->string('emoji', 8);
            $table->timestamps();

            // One reaction per person per wish — pressing the same emoji again undoes it,
            // a different emoji replaces it (BirthdayWishController mirrors TotController::react).
            $table->unique(['wish_id', 'employee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('birthday_wish_reactions');
    }
};
