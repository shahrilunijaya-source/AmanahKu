<?php

use App\Support\PersonName;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One-time tidy-up of names already saved before PersonName existed ("MARYAM SOLIHAH
 * BINTI ABDUL KARIM" becomes "Maryam Solihah Binti Abdul Karim"), on staff records and
 * login accounts alike. Only rows whose name actually changes are written.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['employees', 'users'] as $table) {
            DB::table($table)->whereNotNull('name')->get(['id', 'name'])
                ->each(function (object $row) use ($table) {
                    $formatted = PersonName::format($row->name);
                    if ($formatted !== $row->name) {
                        DB::table($table)->where('id', $row->id)->update(['name' => $formatted]);
                    }
                });
        }
    }

    /** The old spellings aren't kept, so there is nothing to put back. */
    public function down(): void {}
};
