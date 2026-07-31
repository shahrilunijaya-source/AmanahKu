<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exit_interviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('resignation_id')->constrained()->cascadeOnDelete();
            $table->string('reason_category');
            $table->boolean('would_recommend')->default(false);
            $table->json('ratings')->nullable();
            $table->text('feedback')->nullable();
            $table->foreignId('conducted_by_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamp('conducted_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exit_interviews');
    }
};
