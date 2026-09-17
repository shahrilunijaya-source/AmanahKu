<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_invites', function (Blueprint $table) {
            $table->id();
            $table->string('token', 40)->unique();
            $table->string('note', 160)->nullable();
            $table->foreignId('company_category_id')->constrained('company_categories')->restrictOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->foreignId('used_by_tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
            $table->foreignId('created_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_invites');
    }
};
