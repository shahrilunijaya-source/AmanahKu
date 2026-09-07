<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * S07: the port outbox. Every call to a port (calendar, Track, mail) writes one row here
 * before the adapter runs; the stub adapter marks it sent with a synthetic id and touches
 * nothing outside the app. Deferred features record their intent here and are graded on it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('port_outbox', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained()->nullOnDelete();
            $table->string('port', 20);
            $table->string('method', 40);
            $table->nullableMorphs('subject');
            $table->json('payload')->nullable();
            $table->string('status', 10)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('external_id')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'port', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('port_outbox');
    }
};
