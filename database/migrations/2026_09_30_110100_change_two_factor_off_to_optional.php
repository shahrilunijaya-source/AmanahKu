<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * security.2fa drops its 'off' choice (Features::SETTINGS): nothing ever distinguished
 * it from 'optional' (EnforceTwoFactor only special-cases 'required'), so it was
 * removed rather than wired up. Any tenant or platform row still holding the old
 * 'off' value is remapped to 'optional', the choice it already behaved exactly like.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('tenant_features')
            ->where('key', 'security.2fa')
            ->where('value', 'off')
            ->update(['value' => 'optional']);

        DB::table('platform_features')
            ->where('key', 'security.2fa')
            ->where('value', 'off')
            ->update(['value' => 'optional']);
    }

    /**
     * Nothing to restore: 'off' and 'optional' resolved identically for security.2fa,
     * so a remapped row loses no behaviour by staying 'optional'.
     */
    public function down(): void {}
};
