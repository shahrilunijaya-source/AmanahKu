<?php

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Ciphertext is much longer than a 12-char NRIC — widen to TEXT first (I-018).
        Schema::table('salary_structures', function (Blueprint $table) {
            $table->text('nric')->nullable()->change();
        });

        // Encrypt any existing plaintext values in place. Idempotent: a value that already
        // decrypts is left untouched, so re-running never double-encrypts.
        foreach (DB::table('salary_structures')->whereNotNull('nric')->get(['id', 'nric']) as $row) {
            try {
                Crypt::decryptString($row->nric);

                continue;   // already encrypted
            } catch (DecryptException) {
                // plaintext — encrypt it
            }

            DB::table('salary_structures')->where('id', $row->id)
                ->update(['nric' => Crypt::encryptString($row->nric)]);
        }
    }

    public function down(): void
    {
        // Decrypt back to plaintext before narrowing the column so nothing is truncated.
        foreach (DB::table('salary_structures')->whereNotNull('nric')->get(['id', 'nric']) as $row) {
            try {
                $plain = Crypt::decryptString($row->nric);
            } catch (DecryptException) {
                continue;   // already plaintext
            }

            DB::table('salary_structures')->where('id', $row->id)->update(['nric' => $plain]);
        }

        Schema::table('salary_structures', function (Blueprint $table) {
            $table->string('nric')->nullable()->change();
        });
    }
};
