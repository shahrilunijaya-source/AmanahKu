<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            // Mean luminance (0..1) of an uploaded cover photo, sampled once at upload so
            // the layout can turn the title white over a dark picture without reading
            // the file on every request. Null for a preset (its stops are in config).
            $table->decimal('cover_luminance', 4, 3)->nullable()->after('cover_path');
        });
    }

    public function down(): void
    {
        Schema::table('employees', fn (Blueprint $table) => $table->dropColumn('cover_luminance'));
    }
};
