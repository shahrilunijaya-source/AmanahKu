<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feedback_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();          // reporter (always a user)
            $table->foreignId('employee_id')->nullable()->constrained()->nullOnDelete(); // their employee record, if any
            $table->string('type');                    // bug|idea
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('page_url')->nullable();     // where they were when reporting
            $table->string('status')->default('open');  // open|reviewing|resolved|declined
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feedback_items');
    }
};
