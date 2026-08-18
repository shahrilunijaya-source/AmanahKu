<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A consuming application, as an API caller in its own right — Track, DevStage 01,
 * SupportOS. Sanctum tokens hang off this row instead of a User, so a key survives
 * the person who issued it leaving, and carries only the scopes it was granted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_clients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            // The super-admin who issued it. Nullable so removing a staff account never
            // takes a live integration's key with it.
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_clients');
    }
};
