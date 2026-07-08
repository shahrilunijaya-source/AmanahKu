<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('performance_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reviewer_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('cycle');                  // "2026 H1"
            $table->string('period_label')->nullable(); // "Jan–Jun 2026"
            $table->string('status')->default('in_progress'); // scheduled|in_progress|completed|acknowledged
            $table->decimal('overall_rating', 3, 1)->nullable(); // 0.0–5.0
            $table->string('rating_label')->nullable();
            $table->text('strengths')->nullable();
            $table->text('improvements')->nullable();
            $table->text('goals')->nullable();
            $table->text('self_assessment')->nullable();
            $table->json('competencies')->nullable();  // [{label, score(0-5)}]
            $table->date('review_date')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamps();
        });

        // Enrich the existing achievements table so the recognition screen has real signal.
        Schema::table('achievements', function (Blueprint $table) {
            $table->string('category')->nullable()->after('title');  // Recognition|Milestone|Award|Spot Award
            $table->string('icon')->nullable()->after('category');   // trophy|medal|star|zap
            $table->unsignedInteger('points')->default(0)->after('icon');
            $table->date('date')->nullable()->after('points');
        });
    }

    public function down(): void
    {
        Schema::table('achievements', function (Blueprint $table) {
            $table->dropColumn(['category', 'icon', 'points', 'date']);
        });

        Schema::dropIfExists('performance_reviews');
    }
};
