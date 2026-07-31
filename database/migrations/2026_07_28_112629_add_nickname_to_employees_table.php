<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Staff call each other by nickname ("Hakime", "Kak Lin") while `name` holds the
        // full legal name. Without a column for the short name, every people picker and
        // every imported roster row has to guess who a nickname means.
        Schema::table('employees', function (Blueprint $table) {
            $table->string('nickname', 60)->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('nickname');
        });
    }
};
