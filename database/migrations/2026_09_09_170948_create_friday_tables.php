<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * CR-29 Friday Sign-Off. `friday_moods` and `friday_receipts` deliberately
     * carry no identity column and no column that could join the two of them
     * back together (docs/build/OPEN.md "QA / CR-29") — same anonymity shape
     * as `plot_twist_votes`/`plot_twist_receipts` (CR-25), different input to
     * the receipt hash. `friday_wins` is not anonymous: "my win" is only ever
     * posted under the author's name when they tick "share".
     */
    public function up(): void
    {
        Schema::create('friday_moods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->date('week_of');
            $table->string('mood');
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('friday_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->date('week_of');
            $table->char('receipt', 64);
            $table->unique(['tenant_id', 'week_of', 'receipt']);
            // No mood, no created_at: a shared timestamp with the mood row it
            // accompanies would let anyone with DB access join receipt to mood
            // by insertion time and recover who answered what.
        });

        Schema::create('friday_wins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->date('week_of');
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->string('text', 160);
            $table->boolean('shared')->default(false);
            // No timestamps: a created_at here would land in the same instant
            // as the friday_moods row written in the same transaction, and
            // this table DOES carry employee_id — joining the two on
            // (tenant_id, week_of, created_at) would recover the mood of
            // anyone who also left a win, shared or private (docs/build/OPEN.md
            // "S25 / CR-29 / friday_wins carries no timestamps").
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('friday_wins');
        Schema::dropIfExists('friday_receipts');
        Schema::dropIfExists('friday_moods');
    }
};
