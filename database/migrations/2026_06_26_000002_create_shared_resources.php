<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shared_resources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->enum('category', ['email', 'design', 'comms', 'system', 'storage', 'other'])->default('other');
            $table->string('url')->nullable();
            $table->string('username')->nullable();
            // Stored encrypted at rest via the model's `encrypted` cast — the column
            // therefore holds ciphertext, never the plaintext credential.
            $table->text('password')->nullable();
            $table->text('notes')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shared_resources');
    }
};
