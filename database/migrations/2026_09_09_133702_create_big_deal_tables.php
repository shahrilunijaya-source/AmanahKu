<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('big_deals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('type', 30);
            $table->string('title');
            $table->text('story')->nullable();
            $table->foreignId('raised_by')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->foreignId('work_item_id')->nullable()->constrained('work_items')->nullOnDelete();
            $table->string('track_ref')->nullable();
            $table->string('client_contact')->nullable();
            $table->boolean('names_approved')->default(false);
            $table->string('source_path')->nullable();
            $table->dateTime('published_at');
            $table->timestamps();
        });

        Schema::create('big_deal_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('big_deal_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->timestamps();
        });

        Schema::create('big_deal_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('big_deal_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
        });

        Schema::create('big_deal_reactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('big_deal_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->string('reaction', 40);
            $table->timestamps();
            $table->unique(['big_deal_id', 'employee_id', 'reaction']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('big_deal_reactions');
        Schema::dropIfExists('big_deal_members');
        Schema::dropIfExists('big_deal_photos');
        Schema::dropIfExists('big_deals');
    }
};
