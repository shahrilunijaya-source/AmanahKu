<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Support\GreetingBank;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('greeting_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('bucket', 20);
            $table->string('trigger', 30);
            $table->string('text_en', 200);
            $table->string('text_ms', 200);
            $table->foreignId('suggested_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->index('tenant_id');
        });

        // Every existing tenant gets the default bank so the dashboard greeting
        // has lines to pick from immediately, not just tenants created after this.
        Tenant::pluck('id')->each(fn (int $tenantId) => GreetingBank::seed($tenantId));
    }

    public function down(): void
    {
        Schema::dropIfExists('greeting_lines');
    }
};
