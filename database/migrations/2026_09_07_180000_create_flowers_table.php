<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flowers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('giver_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('recipient_id')->constrained('employees')->cascadeOnDelete();
            $table->string('note', 200);
            // 'YYYY-MM' of the giving date — the caps (3/month, 1/recipient/month) and
            // the "reset next month" rule all key off this rather than created_at, so a
            // month boundary is a string comparison, not a date-range query everywhere.
            $table->char('month', 7);
            $table->timestamp('hidden_at')->nullable();
            $table->foreignId('hidden_by_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'month']);
            $table->index('recipient_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flowers');
    }
};
