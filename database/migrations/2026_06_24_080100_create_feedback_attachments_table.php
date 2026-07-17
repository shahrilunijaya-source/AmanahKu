<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feedback_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('feedback_item_id')->constrained()->cascadeOnDelete();
            $table->string('path');                       // location on the private 'local' disk
            $table->string('name');                       // original / generated filename shown to humans
            $table->string('mime')->nullable();           // drives image-vs-document rendering in the inbox
            $table->unsignedInteger('size')->default(0);  // bytes
            $table->timestamps();

            $table->index('feedback_item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feedback_attachments');
    }
};
