<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('petty_cash_txns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('petty_cash_float_id')->constrained()->cascadeOnDelete();
            $table->enum('type', ['disbursement', 'replenishment']);
            $table->decimal('amount', 12, 2)->default(0);
            $table->string('payee', 120)->nullable();
            $table->string('purpose', 255)->nullable();
            $table->string('note', 255)->nullable();
            $table->foreignId('recorded_by_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->date('txn_date');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('petty_cash_txns');
    }
};
