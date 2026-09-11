<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            // CR-13: opt out of the birthday band, wishes and the 8 AM notice.
            // The calendar still shows the day — this only mutes the celebration.
            $table->boolean('birthday_private')->default(false)->after('date_of_birth');
        });
    }

    public function down(): void
    {
        Schema::table('employees', fn (Blueprint $table) => $table->dropColumn('birthday_private'));
    }
};
