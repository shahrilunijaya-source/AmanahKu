<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Trello-style cards gain a description body and an explicit column ordering.
        Schema::table('work_items', function (Blueprint $table) {
            $table->text('description')->nullable()->after('title');
            $table->unsignedInteger('sort_order')->default(0)->after('progress');
        });

        // Card comment thread. One row per comment, newest rendered last.
        Schema::create('work_item_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('work_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();
            $table->text('body');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_item_comments');
        Schema::table('work_items', function (Blueprint $table) {
            $table->dropColumn(['description', 'sort_order']);
        });
    }
};
