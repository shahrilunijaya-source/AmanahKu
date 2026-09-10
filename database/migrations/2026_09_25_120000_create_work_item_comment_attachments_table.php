<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CR-08: files on a card comment. Stored on the private 'local' disk and streamed
 * through WorkItemController::commentAttachment (card-access gated). A file marked
 * confidential never leaves the card; any other file goes to Track only when the
 * author ticked it for that push.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_item_comment_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('work_item_comment_id')->constrained('work_item_comments')->cascadeOnDelete();
            $table->string('path');
            $table->string('name');
            $table->string('mime')->nullable();
            $table->unsignedInteger('size')->default(0);
            $table->boolean('confidential')->default(false);
            $table->boolean('pushed_to_track')->default(false);
            $table->timestamps();

            $table->index('work_item_comment_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_item_comment_attachments');
    }
};
