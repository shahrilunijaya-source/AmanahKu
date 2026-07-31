<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('performance_reviews', function (Blueprint $table): void {
            // Reviewer (manager/HR) rating-entry — kept distinct from the shared
            // `competencies`/`overall_rating` display fields so a reviewer can draft
            // and revise scores while the cycle is still open.
            $table->json('reviewer_scores')->nullable()->after('competencies');     // [{label, score(0-5)}]
            $table->decimal('reviewer_overall', 3, 1)->nullable()->after('reviewer_scores'); // 0.0–5.0
            $table->text('reviewer_comments')->nullable()->after('reviewer_overall');
            $table->timestamp('reviewer_rated_at')->nullable()->after('reviewer_comments');
        });
    }

    public function down(): void
    {
        Schema::table('performance_reviews', function (Blueprint $table): void {
            $table->dropColumn([
                'reviewer_scores',
                'reviewer_overall',
                'reviewer_comments',
                'reviewer_rated_at',
            ]);
        });
    }
};
