<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // In-app 1-to-1 direct messaging. One conversation row per UNORDERED employee
        // pair — participants are stored canonically (low id / high id) so (A,B) and
        // (B,A) collapse to a single row, and a unique index prevents duplicates per
        // pair. Read state for 1-to-1 is simply the recipient's read_at on each message,
        // so no separate receipts table is needed. Everything tenant-scoped
        // (BelongsToTenant), tenant_id first FK on every table like knowledge_*.
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_low_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('employee_high_id')->constrained('employees')->cascadeOnDelete();
            $table->timestamp('last_message_at')->nullable();   // drives panel / list ordering
            $table->timestamps();

            // Employee ids are globally unique (single employees table), so the pair
            // alone is sufficient; both members are same-tenant by construction (resolved
            // from Employee::active(), which the tenant global scope restricts).
            $table->unique(['employee_low_id', 'employee_high_id']);
            $table->index(['tenant_id', 'last_message_at']);
        });

        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained('conversations')->cascadeOnDelete();
            $table->foreignId('sender_id')->constrained('employees')->cascadeOnDelete();
            $table->text('body');
            $table->timestamp('read_at')->nullable();   // set when the OTHER party opens it
            $table->timestamps();

            $table->index(['conversation_id', 'id']);              // thread fetch / pagination
            $table->index(['tenant_id', 'read_at', 'sender_id']);  // unread-badge count
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
        Schema::dropIfExists('conversations');
    }
};
