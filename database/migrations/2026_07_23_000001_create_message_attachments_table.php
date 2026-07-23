<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A file attached to a direct message — image or document. Files live on the
        // private 'local' disk and are only ever reached through
        // MessageController::attachment (participant-gated stream), never a public URL.
        // Mirrors feedback_attachments. tenant_id first FK like every tenant table.
        Schema::create('message_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('message_id')->constrained('messages')->cascadeOnDelete();
            $table->string('path');                       // location on the private 'local' disk
            $table->string('name');                       // original filename shown to humans
            $table->string('mime')->nullable();           // drives image-vs-chip rendering
            $table->unsignedInteger('size')->default(0);  // bytes
            $table->timestamps();

            $table->index('message_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_attachments');
    }
};
