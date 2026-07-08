<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-user permission overrides within a tenant — the "user permission override" leg
 * of the access formula (§9). A row grants (granted=true) or denies (granted=false)
 * a single permission for one member, on top of what their role already implies.
 * Absence of a row means "inherit from the role".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('permission');
            $table->boolean('granted')->default(true); // true = grant, false = deny
            $table->timestamps();
            $table->unique(['tenant_id', 'user_id', 'permission']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_permissions');
    }
};
