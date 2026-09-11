<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Board comment replies: a reply is a comment pointing at its parent. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_item_comments', function (Blueprint $table) {
            $table->foreignId('parent_id')->nullable()->after('employee_id')->constrained('work_item_comments')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('work_item_comments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_id');
        });
    }
};
