<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            // Canonical personal identity, captured by the staff first-login wizard.
            // `nric` is stored as ciphertext (Employee model casts it 'encrypted'),
            // so widen to TEXT like salary_structures.nric (migration 2026_06_24_000022).
            $table->text('nric')->nullable()->after('date_of_birth');
            $table->string('gender', 20)->nullable()->after('nric');
            $table->string('marital_status', 20)->nullable()->after('gender');
            $table->string('phone', 40)->nullable()->after('marital_status');
            $table->text('address')->nullable()->after('phone');
            $table->string('emergency_contact_name')->nullable()->after('address');
            $table->string('emergency_contact_phone', 40)->nullable()->after('emergency_contact_name');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn([
                'nric', 'gender', 'marital_status', 'phone', 'address',
                'emergency_contact_name', 'emergency_contact_phone',
            ]);
        });
    }
};
