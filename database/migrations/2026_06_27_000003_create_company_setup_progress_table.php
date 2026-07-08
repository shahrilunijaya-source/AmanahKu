<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per company tracking onboarding-wizard progress. `steps` holds the keys a
 * company admin has manually marked complete; auto-detected steps (a branch exists,
 * a department exists, …) are computed live and not stored. `completed_at` is set
 * when the admin finishes the wizard.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_setup_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->unique()->constrained()->cascadeOnDelete();
            $table->json('steps')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_setup_progress');
    }
};
