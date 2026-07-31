<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The monthly TOT roster. One slot per tenant per calendar month, mirroring the
        // Google Sheet it replaces (Tahun / Bulan / PIC / Tajuk / Link). presenter_name is
        // the fallback for a PIC with no employee record: imported nicknames, "Team", or a
        // non-TOT calendar entry. Everything is tenant-scoped (BelongsToTenant).
        Schema::create('tot_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');           // 1-12
            $table->foreignId('presenter_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('presenter_name', 120)->nullable();
            $table->string('title', 200)->nullable();       // null when the topic is not set yet
            $table->text('description')->nullable();
            $table->string('status', 16)->default('planned'); // planned|confirmed|done|skipped|not_tot
            $table->date('held_on')->nullable();
            $table->json('links')->nullable();              // [{label, url}]
            $table->foreignId('entry_id')->nullable()->constrained('knowledge_entries')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'year', 'month']);
            $table->index(['tenant_id', 'year']);
        });

        Schema::create('tot_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('session_id')->constrained('tot_sessions')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->text('body');
            $table->timestamps();
        });

        // One row per person per emoji per session. The unique key is what makes a repeat
        // POST a toggle rather than a duplicate.
        Schema::create('tot_reactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('session_id')->constrained('tot_sessions')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->string('emoji', 16);
            $table->timestamps();

            $table->unique(['session_id', 'employee_id', 'emoji']);
        });

        // Attendance and rating are the same person against the same session, so they share
        // one row. score stays nullable because people watch without rating.
        Schema::create('tot_participation', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('session_id')->constrained('tot_sessions')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->timestamp('watched_at')->nullable();
            $table->unsignedTinyInteger('score')->nullable();   // 1-5
            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique(['session_id', 'employee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tot_participation');
        Schema::dropIfExists('tot_reactions');
        Schema::dropIfExists('tot_comments');
        Schema::dropIfExists('tot_sessions');
    }
};
