<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('compliance_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->enum('type', ['license', 'certification', 'permit'])->default('license');
            $table->string('name', 160);
            $table->string('identifier', 120)->nullable(); // e.g. licence / cert number
            $table->string('issuer', 120)->nullable();
            $table->date('issued_at')->nullable();
            $table->date('expires_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compliance_items');
    }
};
