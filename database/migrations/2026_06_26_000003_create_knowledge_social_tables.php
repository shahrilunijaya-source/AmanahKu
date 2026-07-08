<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Stars = a per-employee toggle reaction on an entry (replaces the old
        // free-increment "helpful" counter, which any user could spam).
        Schema::create('knowledge_stars', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('entry_id')->constrained('knowledge_entries')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['entry_id', 'employee_id']);   // one star per person per entry
        });

        // Threaded discussion under each entry.
        Schema::create('knowledge_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('entry_id')->constrained('knowledge_entries')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->text('body');
            $table->timestamps();
        });

        // The star toggle now owns the count — drop the spammable column.
        if (Schema::hasColumn('knowledge_entries', 'helpful_count')) {
            Schema::table('knowledge_entries', function (Blueprint $table) {
                $table->dropColumn('helpful_count');
            });
        }
    }

    public function down(): void
    {
        Schema::table('knowledge_entries', function (Blueprint $table) {
            $table->unsignedInteger('helpful_count')->default(0);
        });
        Schema::dropIfExists('knowledge_comments');
        Schema::dropIfExists('knowledge_stars');
    }
};
