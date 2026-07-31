<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Confidential 1:1 chat requests from staff to HR. Intentionally identified
        // (HR must see who asked) but visible only to requester + HR.
        Schema::create('wellness_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('topic')->nullable();           // optional EAP category
            $table->text('message');
            $table->string('urgency')->default('normal');  // low|normal|high
            $table->string('status')->default('open');     // open|acknowledged|closed
            $table->foreignId('handled_by_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamp('handled_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wellness_requests');
    }
};
