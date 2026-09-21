<?php

declare(strict_types=1);

use App\Support\StatutoryOptions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Spec F2: bank_code (SWIFT/BIC, what upload files carry) derived from the free-text
 * bank_name once here and on every save after; SOCSO/HRD Corp exemption switches.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salary_structures', function (Blueprint $table) {
            $table->string('bank_code', 11)->nullable()->after('bank_name');
            $table->boolean('socso_exempt')->default(false)->after('socso_category');
            $table->boolean('hrdf_exempt')->default(false)->after('socso_exempt');
        });

        foreach (StatutoryOptions::BANK_CODES as $name => $code) {
            // Match on the distinctive part of the name only ("Bank Islam" -> "islam"):
            // matching on the generic word "bank" would stamp one bank's code on every
            // other bank's rows. Anything unmatched ("MBB") stays null and the readiness
            // check flags it rather than guessing.
            $needle = strtolower((string) preg_replace('/(^Bank |\s+Bank$)/', '', $name));
            DB::table('salary_structures')->whereNull('bank_code')
                ->whereRaw('LOWER(bank_name) LIKE ?', ['%'.$needle.'%'])
                ->update(['bank_code' => $code]);
        }
    }

    public function down(): void
    {
        Schema::table('salary_structures', function (Blueprint $table) {
            $table->dropColumn(['bank_code', 'socso_exempt', 'hrdf_exempt']);
        });
    }
};
