<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('external_tot_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('host')->nullable();
            $table->text('description')->nullable();
            $table->date('event_date');
            $table->string('time_label')->nullable();
            $table->string('venue')->nullable();
            $table->string('venue_map_url')->nullable();
            $table->string('registration_url')->nullable();
            $table->foreignId('posted_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'event_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('external_tot_events');
    }
};
