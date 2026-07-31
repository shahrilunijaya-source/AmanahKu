<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Company-wide knowledge sharing. Segments form a one-level tree (parent_id
        // self-join); entries hang off a segment (+ optional sub-segment). Monthly
        // contribution + read-receipt tables drive the "owes a lesson" reminder and
        // the unread badge. Everything is tenant-scoped (BelongsToTenant).
        Schema::create('knowledge_segments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('knowledge_segments')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('label');
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('knowledge_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('seg_id')->constrained('knowledge_segments')->cascadeOnDelete();
            $table->foreignId('subseg_id')->nullable()->constrained('knowledge_segments')->nullOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();   // author
            $table->string('title', 200);
            $table->text('body');
            $table->json('tags')->nullable();
            $table->unsignedInteger('helpful_count')->default(0);
            $table->timestamps();
        });

        // One row per employee per calendar month once they've contributed.
        Schema::create('knowledge_monthly_contributions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');   // 1–12
            $table->boolean('submitted')->default(true);
            $table->timestamps();

            $table->unique(['employee_id', 'year', 'month']);
        });

        // Read receipts — drive the unread badge count (entries not yet read).
        Schema::create('knowledge_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('entry_id')->constrained('knowledge_entries')->cascadeOnDelete();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->unique(['employee_id', 'entry_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_reads');
        Schema::dropIfExists('knowledge_monthly_contributions');
        Schema::dropIfExists('knowledge_entries');
        Schema::dropIfExists('knowledge_segments');
    }
};
