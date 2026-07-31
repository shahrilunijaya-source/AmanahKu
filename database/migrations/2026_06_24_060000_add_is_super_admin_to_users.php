<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Super-admins operate above every tenant: they provision new companies and seed
 * each company's first HR admin. The flag is deliberately NOT mass-assignable
 * (absent from User::$fillable) so it can only ever be set by a migration/seeder
 * or an explicit forceFill — never from request input.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_super_admin')->default(false)->after('password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_super_admin');
        });
    }
};
