<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A type with this flag is granted by HR (opening balance on the Leave Setup screen)
        // and never applied for; it is hidden from the Apply form and rejected server-side.
        Schema::table('leave_types', function (Blueprint $table) {
            $table->boolean('is_hr_granted_only')->default(false)->after('is_unpaid');
        });

        DB::table('leave_types')->where('name', 'Replacement')->update(['is_hr_granted_only' => true]);
    }

    public function down(): void
    {
        Schema::table('leave_types', function (Blueprint $table) {
            $table->dropColumn('is_hr_granted_only');
        });
    }
};
