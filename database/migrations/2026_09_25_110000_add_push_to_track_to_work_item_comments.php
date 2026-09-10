<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CR-08: a card comment can be pushed to Track as an official project record.
 * Once pushed it is never deleted here — a withdrawal keeps the row and records
 * the reason, and every edit appends a version, because Track shows the history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_item_comments', function (Blueprint $table) {
            $table->timestamp('pushed_to_track_at')->nullable()->after('body');
            // What Track shows: mentions flattened to plain names. Latest version.
            $table->text('track_body')->nullable()->after('pushed_to_track_at');
            $table->unsignedSmallInteger('track_version')->default(0)->after('track_body');
            // list<{v, body, by, at}> — every version ever pushed, v1 first.
            $table->json('track_versions')->nullable()->after('track_version');
            $table->timestamp('withdrawn_at')->nullable()->after('track_versions');
            $table->string('withdrawn_reason')->nullable()->after('withdrawn_at');
            $table->index(['tenant_id', 'pushed_to_track_at']);
        });

        Schema::table('projects', function (Blueprint $table) {
            // Last time Track pulled this project's comments: Track's own heartbeat that
            // it is linked (Track holds the link, Amanahku only learns of it this way).
            $table->timestamp('track_linked_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('work_item_comments', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'pushed_to_track_at']);
            $table->dropColumn(['pushed_to_track_at', 'track_body', 'track_version', 'track_versions', 'withdrawn_at', 'withdrawn_reason']);
        });
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('track_linked_at');
        });
    }
};
