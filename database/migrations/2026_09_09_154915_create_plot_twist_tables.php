<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * CR-25 This Week's Plot Twist. `plot_twist_votes` and `plot_twist_receipts`
     * deliberately carry no identity column — anonymity is the point of the CR
     * (docs/build/OPEN.md "QA / CR-25"). The receipt is the only link between a
     * person and "did they vote", and it cannot be joined back to a choice.
     */
    public function up(): void
    {
        Schema::create('plot_twist_polls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('question');
            $table->string('kind'); // fun | who | social
            $table->foreignId('named_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('status')->default('draft'); // draft | open | withdrawn
            $table->date('opens_on');
            $table->dateTime('reveals_at');
            $table->dateTime('idea_fed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('plot_twist_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('poll_id')->constrained('plot_twist_polls')->cascadeOnDelete();
            $table->string('label');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('plot_twist_votes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('poll_id')->constrained('plot_twist_polls')->cascadeOnDelete();
            $table->foreignId('option_id')->constrained('plot_twist_options')->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('plot_twist_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('poll_id')->constrained('plot_twist_polls')->cascadeOnDelete();
            $table->char('receipt', 64);
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['poll_id', 'receipt']);
        });

        Schema::create('plot_twist_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('text');
            $table->string('kind'); // fun | who | social
            $table->boolean('template')->default(false);
            $table->foreignId('suggested_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->boolean('approved')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plot_twist_votes');
        Schema::dropIfExists('plot_twist_receipts');
        Schema::dropIfExists('plot_twist_options');
        Schema::dropIfExists('plot_twist_questions');
        Schema::dropIfExists('plot_twist_polls');
    }
};
