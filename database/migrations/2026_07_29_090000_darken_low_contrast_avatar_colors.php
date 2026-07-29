<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Employee avatars draw white initials on `avatar_color`, so the column has to
 * clear WCAG AA for small text (4.5:1) against #fff. Three palette entries did
 * not: the green (4.30:1), the amber (3.16:1) and the teal (4.11:1). Each was
 * scaled toward black on its own RGB ratio, which keeps the hue and lifts the
 * ratio to ~4.74:1. This remaps rows already carrying an old value.
 */
return new class extends Migration
{
    /** Old (failing) hex => new (passing) hex, hue preserved. */
    private const REMAP = [
        '#1f8a65' => '#1d825f',
        '#c08532' => '#996a28',
        '#2b8a8a' => '#287f7f',
    ];

    public function up(): void
    {
        foreach (self::REMAP as $old => $new) {
            DB::table('employees')->where('avatar_color', $old)->update(['avatar_color' => $new]);
        }
    }

    public function down(): void
    {
        foreach (self::REMAP as $old => $new) {
            DB::table('employees')->where('avatar_color', $new)->update(['avatar_color' => $old]);
        }
    }
};
