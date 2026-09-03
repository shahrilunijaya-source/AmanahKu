<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Personal workspace look: {"wallpaper": "preset:dawn" | "upload" | absent,
            // "wallpaper_path": "wallpapers/…", "dim": "none|soft|strong"}. Read by
            // layouts/app.blade.php on every page; only the owner ever sees it.
            $table->json('appearance')->nullable()->after('dashboard_prefs');
        });

        Schema::table('employees', function (Blueprint $table) {
            // Profile cover photo on the public disk. Everyone who opens the profile sees it.
            // `photo` beside it is an unused avatar column; left alone on purpose.
            $table->string('cover_path', 200)->nullable()->after('photo');
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('appearance'));
        Schema::table('employees', fn (Blueprint $table) => $table->dropColumn('cover_path'));
    }
};
