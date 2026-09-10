<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CR-21: Office Requests / Permintaan Pejabat. A public wishlist/to-do for the Admin team —
 * every request routes as a T.A.A. card (`work_items.work_item_id` below), can be upvoted
 * instead of re-raised, and carries an admin note + a closing note when done. Scope 5 (vehicle
 * service requests) reuses the same table with a few nullable columns rather than a second
 * table — see docs/build/sessions/S14/contract.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('office_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->string('category', 20);
            $table->string('title', 160);
            $table->text('description');
            $table->string('photo_path')->nullable();
            $table->string('location', 160);
            $table->string('urgency', 10)->default('normal');
            $table->string('urgency_reason')->nullable();
            $table->string('status', 20)->default('open');
            $table->text('admin_note')->nullable();
            $table->text('closing_note')->nullable();
            $table->timestamp('done_at')->nullable();
            $table->foreignId('work_item_id')->nullable()->constrained('work_items')->nullOnDelete();
            $table->unsignedInteger('votes')->default(0);
            // Scope 5: vehicle service requests only, null for every other category.
            $table->string('vehicle_plate', 20)->nullable();
            $table->unsignedInteger('vehicle_mileage')->nullable();
            $table->date('vehicle_last_service_at')->nullable();
            $table->timestamps();
        });

        Schema::create('office_request_votes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('office_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['office_request_id', 'employee_id']);
        });

        // Scope 2: kept simple on purpose — a body and who wrote it.
        Schema::create('office_request_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('office_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->text('body');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('office_request_comments');
        Schema::dropIfExists('office_request_votes');
        Schema::dropIfExists('office_requests');
    }
};
