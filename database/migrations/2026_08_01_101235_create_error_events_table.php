<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Unhandled exceptions, captured into the application's own database.
 *
 * Production runs on infrastructure this repo has no shell, no database client and no
 * log file on. Without this table a production fault is only a white page and a phone
 * call: the trace lives in a file nobody here can open. Every row carries a short
 * reference that is printed on the error page, so a user reports a code and a
 * super-admin reads the exact fault behind it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('error_events', function (Blueprint $table) {
            $table->id();
            // Shown to the user on the error page. Unique, so a reported code resolves
            // to exactly one fault.
            $table->string('reference', 12)->unique();
            // class + file + line, hashed. Groups repeats of the same fault.
            $table->string('fingerprint', 64)->index();
            $table->string('exception_class');
            $table->text('message');
            $table->string('file');
            $table->unsignedInteger('line');
            // Frames only, never call arguments — see ErrorEvent::traceFrames().
            $table->text('trace');
            $table->string('url');
            $table->string('method', 10);
            $table->string('ip', 45)->nullable();
            // Plain columns, not foreign keys: the row must outlive the user or the
            // company it points at, and a fault can happen before either is resolved.
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('error_events');
    }
};
