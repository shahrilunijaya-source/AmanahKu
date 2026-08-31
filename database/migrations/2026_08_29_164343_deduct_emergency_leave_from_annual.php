<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Emergency leave has never had its own quota in practice — it spends the
        // Annual balance. Wire that per tenant by matching the literal names, same
        // as 2026_08_25_210000_add_is_unpaid_to_leave_types_table.php did for Unpaid.
        $annualIdsByTenant = DB::table('leave_types')
            ->where('name', 'Annual')
            ->pluck('id', 'tenant_id');

        foreach ($annualIdsByTenant as $tenantId => $annualId) {
            DB::table('leave_types')
                ->where('tenant_id', $tenantId)
                ->where('name', 'Emergency')
                ->update(['deducts_from_leave_type_id' => $annualId]);
        }
    }

    public function down(): void
    {
        DB::table('leave_types')
            ->where('name', 'Emergency')
            ->update(['deducts_from_leave_type_id' => null]);
    }
};
