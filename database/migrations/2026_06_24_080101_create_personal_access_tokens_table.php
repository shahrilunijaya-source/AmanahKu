<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sanctum personal access tokens, extended with a nullable tenant_id so an API
 * token is bound to exactly one tenant. The bearer middleware reads this column
 * to activate the token's tenant for the request, which makes every /api/v1 call
 * tenant-scoped through the same BelongsToTenant global scope the web app uses.
 *
 * `token` stores the sha256 hash of the plaintext (Sanctum default); the plaintext
 * is shown once at mint time and never persisted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable');
            // Tenant the token is scoped to. Nullable to keep the column generic for
            // any non-tenant tokenable, but every token minted by api:token sets it.
            $table->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->text('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_access_tokens');
    }
};
