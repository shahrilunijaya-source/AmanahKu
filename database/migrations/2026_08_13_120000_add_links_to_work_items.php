<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_items', function (Blueprint $table) {
            $table->json('links')->nullable(); // [{label, url}]
        });
    }

    public function down(): void
    {
        Schema::table('work_items', function (Blueprint $table) {
            $table->dropColumn('links');
        });
    }
};
