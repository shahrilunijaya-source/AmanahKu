<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec F8: how much of this month's pay was exempt under a Payroll Item's yearly
 * exemption cap (e.g. the RM6,000/year official-duties travel allowance). Kept on the
 * payslip so Form EA Part F can sum it without re-deriving a year of caps.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payslips', fn (Blueprint $t) => $t->decimal('pcb_exempt_amount', 12, 2)->default(0)->after('pcb_override'));
    }

    public function down(): void
    {
        Schema::table('payslips', fn (Blueprint $t) => $t->dropColumn('pcb_exempt_amount'));
    }
};
