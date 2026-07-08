<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-tenant feature overrides. A row exists only when a tenant has explicitly
 * set a value; absence means "inherit the platform default". Ignored entirely
 * when the platform has locked the key.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_features', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('key');
            $table->string('value');
            $table->timestamps();
            $table->unique(['tenant_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_features');
    }
};
