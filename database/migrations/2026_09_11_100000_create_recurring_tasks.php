<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CR-18: the recurring task engine. A schedule (recurring_tasks) spawns one card per
 * period, tracked in recurring_task_occurrences so a period is never created twice and a
 * skipped one is remembered with its reason. The social-activity card links to the
 * Event it produced (work_items.company_event_id), and company_events gains the smallest
 * CR-11 form its done rule needs: a status, an approval and post-event evidence.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recurring_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('title', 160);
            $table->string('frequency', 20);
            $table->unsignedSmallInteger('interval')->default(1);
            $table->date('start_on');
            $table->foreignId('owner_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('owner_position_title')->nullable();
            $table->json('tagged_employee_ids')->nullable();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->string('priority', 10)->default('medium');
            $table->unsignedSmallInteger('lead_days')->default(0);
            $table->json('subtasks')->nullable();
            $table->unsignedSmallInteger('min_attended')->default(1);
            $table->timestamp('paused_at')->nullable();
            $table->foreignId('created_by_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('recurring_task_occurrences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recurring_task_id')->constrained()->cascadeOnDelete();
            $table->date('period');
            $table->foreignId('work_item_id')->nullable()->constrained()->nullOnDelete();
            $table->text('skipped_reason')->nullable();
            $table->timestamps();
            $table->unique(['recurring_task_id', 'period']);
        });

        Schema::table('work_items', function (Blueprint $table) {
            $table->foreignId('company_event_id')->nullable()->after('project_id')->constrained()->nullOnDelete();
        });

        Schema::table('company_events', function (Blueprint $table) {
            $table->string('status', 20)->default('draft')->after('type');
            $table->timestamp('approved_at')->nullable()->after('status');
            $table->foreignId('approved_by_employee_id')->nullable()->after('approved_at')->constrained('employees')->nullOnDelete();
            $table->text('evidence_note')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('company_events', function (Blueprint $table) {
            $table->dropConstrainedForeignId('approved_by_employee_id');
            $table->dropColumn(['status', 'approved_at', 'evidence_note']);
        });
        Schema::table('work_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('company_event_id');
        });
        Schema::dropIfExists('recurring_task_occurrences');
        Schema::dropIfExists('recurring_tasks');
    }
};
