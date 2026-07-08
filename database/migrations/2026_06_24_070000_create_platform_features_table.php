<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Platform-wide feature defaults set by super-admins. `value` is the default a
 * tenant inherits when it has no override; `locked` means the tenant cannot
 * override it (the platform value wins). Not tenant-scoped — global rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_features', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('value');
            $table->boolean('locked')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_features');
    }
};
