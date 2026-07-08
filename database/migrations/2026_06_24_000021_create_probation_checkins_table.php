<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('probation_checkins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('probation_review_id')->constrained()->cascadeOnDelete();
            $table->string('milestone');
            $table->text('note');
            $table->unsignedTinyInteger('rating')->nullable();
            $table->date('checkin_date');
            $table->timestamps();

            $table->index(['tenant_id', 'probation_review_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('probation_checkins');
    }
};
