<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A picture attached to a Knowledge Bank lesson. Files live on the private 'local'
        // disk and are only ever reached through KnowledgeController::attachment (tenant-gated
        // stream), never a public URL. Mirrors message_attachments; unlike messages a lesson
        // is company-wide, so the gate is tenant membership, not participant membership.
        Schema::create('knowledge_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('entry_id')->constrained('knowledge_entries')->cascadeOnDelete();
            $table->string('path');                          // location on the private 'local' disk
            $table->string('name');                           // original filename shown to humans
            $table->string('mime')->nullable();
            $table->unsignedInteger('size')->default(0);      // bytes, post-compression
            $table->string('caption', 200)->nullable();       // doubles as <img alt>
            $table->unsignedTinyInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('entry_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_attachments');
    }
};
