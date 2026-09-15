<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->date('returned_at')->nullable()->after('assigned_at');
            $table->string('reference_no', 80)->nullable()->after('returned_at');
            $table->text('remark')->nullable()->after('reference_no');
        });
    }

    public function down(): void
    {
        Schema::table('assets', fn (Blueprint $table) => $table->dropColumn(['returned_at', 'reference_no', 'remark']));
    }
};
