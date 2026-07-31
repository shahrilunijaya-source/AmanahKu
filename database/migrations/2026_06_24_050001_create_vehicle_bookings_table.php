<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicle_bookings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->string('purpose');
            $table->string('destination')->nullable();
            $table->integer('odometer_out')->nullable();
            $table->integer('odometer_in')->nullable();
            $table->enum('status', ['confirmed', 'cancelled'])->default('confirmed');
            $table->timestamps();

            $table->index(['tenant_id', 'starts_at']);
            // Conflict lookups scan confirmed bookings for a vehicle in a time window.
            $table->index(['vehicle_id', 'status', 'starts_at']);
            $table->index(['employee_id', 'starts_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_bookings');
    }
};
