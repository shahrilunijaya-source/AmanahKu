<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Replacement leave changes hands: HR used to book the day off itself, now it grants
     * a quota and the employee applies against it like any other type. Each grant is a
     * row here — days plus the remark saying what earned them — and it tops up the
     * matching leave_balances row, which is what an approval then draws down.
     */
    public function up(): void
    {
        Schema::create('leave_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('leave_type_id')->constrained()->cascadeOnDelete();
            // Signed: a negative grant is how a mis-typed one is corrected.
            $table->decimal('days', 5, 1);
            $table->string('remark', 255);
            $table->foreignId('granted_by_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamps();
            $table->index(['employee_id', 'leave_type_id']);
        });

        // The old flag meant three things at once; only "not a yearly entitlement" survives.
        // A granted type's denominator is however much HR has granted, so the seeded yearly
        // figure (5) is now a number nothing should read.
        $grantedTypeIds = DB::table('leave_types')->where('is_hr_granted_only', true)->pluck('id');
        DB::table('leave_types')->whereIn('id', $grantedTypeIds)->update(['entitlement' => 0]);

        // Balances that already exist keep their days — HR asked for that — but they would
        // otherwise appear from nowhere in a history that is meant to explain every day.
        foreach (DB::table('leave_balances')->whereIn('leave_type_id', $grantedTypeIds)->where('balance', '>', 0)->get() as $balance) {
            $tenantId = DB::table('employees')->where('id', $balance->employee_id)->value('tenant_id');

            if ($tenantId === null) {
                continue;
            }

            DB::table('leave_grants')->insert([
                'tenant_id' => $tenantId,
                'employee_id' => $balance->employee_id,
                'leave_type_id' => $balance->leave_type_id,
                'days' => $balance->balance,
                'remark' => 'Carried over from before',
                'granted_by_id' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_grants');
    }
};
