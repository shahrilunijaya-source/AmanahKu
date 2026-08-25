<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * A named catalogue entry for a payslip amount — carries its own statutory treatment
 * (epf_liable, perkeso_liable, pcb_taxable) so PayrollCalculator's wage bases fall out
 * of these flags instead of being hardcoded per column. Every tenant is guaranteed this
 * catalogue via seedFor() (called on tenant creation and by a migration for existing
 * tenants); `is_system` items may not be deleted (HR may still edit their flags — a
 * company can legitimately treat an allowance differently).
 */
class PayrollItem extends Model
{
    use BelongsToTenant;

    /**
     * code/type/source/is_system are set at seed time and not user-editable; everything
     * else (including the statutory flags) is.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'name_ms',
        'epf_liable',
        'perkeso_liable',
        'pcb_taxable',
        'pcb_exempt_cap_yearly',
        'ea_box',
        'active',
        'sort_order',
    ];

    /**
     * The standard Malaysian pay-item catalogue, keyed by code. This is the single
     * definition of the statutory flags — PayrollController's wage-base fallback reads
     * these same values rather than retyping them, so the two can never drift apart.
     *
     * Each entry: [name, name_ms, type, epf_liable, perkeso_liable, pcb_taxable,
     * exempt_cap_yearly, ea_box, source, is_system].
     *
     * @var array<string, array{0: string, 1: string, 2: string, 3: bool, 4: bool, 5: bool, 6: ?float, 7: ?string, 8: string, 9: bool}>
     */
    public const array SYSTEM_ITEMS = [
        // Wages under s.2 EPF Act 1991 and the PERKESO payments-subject-to-contribution
        // list both plainly include ordinary basic pay.
        'basic-salary' => ['Basic Salary', 'Gaji Pokok', 'earning', true, true, true, null, 'B1(a)', 'salary', true],

        // Fixed monthly allowances are wages under both Acts, and taxable income.
        'fixed-allowance' => ['Fixed Allowance', 'Elaun Tetap', 'earning', true, true, true, null, null, 'salary', true],

        // The generic bucket for HR's free-form "addition" lines on a payslip — same
        // statutory treatment as a fixed allowance (the safe default the calculator
        // used before this catalogue existed).
        'other-addition' => ['Other Addition', 'Tambahan Lain', 'earning', true, true, true, null, null, 'manual', true],

        // EPF wages include bonus/commission; PERKESO's list explicitly EXCLUDES the
        // annual bonus from SOCSO/EIS wages — the two Acts diverge here.
        'bonus' => ['Bonus', 'Bonus', 'earning', true, false, true, null, 'B1(a)', 'manual', true],
        'commission' => ['Commission', 'Komisen', 'earning', true, true, true, null, null, 'manual', true],

        // s.2 EPF Act 1991 excludes overtime from "wages" outright; PERKESO's list
        // includes it. The opposite carve-out from bonus above.
        'overtime' => ['Overtime', 'Kerja Lebih Masa', 'earning', false, true, true, null, null, 'overtime', true],

        // Travel/petrol/toll: not wages for EPF or PERKESO purposes, taxable but with
        // LHDN's RM6,000/year official-duties travel exemption (cap recorded, not
        // applied — a later pass reads pcb_exempt_cap_yearly).
        'travel-allowance' => ['Travelling / Petrol / Toll Allowance', 'Elaun Perjalanan / Minyak / Tol', 'earning', false, false, true, 6000.00, null, 'manual', true],

        // Meal and outstation allowances: ordinary wages, EPF + PERKESO liable, taxable.
        'meal-allowance' => ['Meal Allowance', 'Elaun Makan', 'earning', true, true, true, null, null, 'manual', true],
        'outstation-allowance' => ['Outstation Allowance', 'Elaun Luar Kawasan', 'earning', true, true, true, null, null, 'manual', true],

        // A reimbursement is not wages at all — no EPF, no PERKESO, not taxable income.
        'claim-reimbursement' => ['Claim Reimbursement', 'Bayaran Balik Tuntutan', 'earning', false, false, false, null, null, 'claim', true],

        // A deduction that reduces the wage bases themselves (handled by the calculator
        // subtracting it from both bases), not a statutory line.
        'unpaid-leave-deduction' => ['Unpaid Leave Deduction', 'Potongan Cuti Tanpa Gaji', 'deduction', false, false, false, null, null, 'leave', true],

        // Deduction side.
        'staff-loan' => ['Staff Loan', 'Pinjaman Staf', 'deduction', false, false, false, null, null, 'manual', true],
        'salary-advance' => ['Salary Advance', 'Pendahuluan Gaji', 'deduction', false, false, false, null, null, 'manual', true],
        'zakat' => ['Zakat', 'Zakat', 'deduction', false, false, false, null, null, 'manual', true],
        'cp38' => ['CP38', 'CP38', 'deduction', false, false, false, null, null, 'manual', true],
        'other-deduction' => ['Other Deduction', 'Potongan Lain', 'deduction', false, false, false, null, null, 'manual', true],
    ];

    protected function casts(): array
    {
        return [
            'epf_liable' => 'boolean',
            'perkeso_liable' => 'boolean',
            'pcb_taxable' => 'boolean',
            'pcb_exempt_cap_yearly' => 'float',
            'is_system' => 'boolean',
            'active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * Create any SYSTEM_ITEMS codes this tenant doesn't already have. Idempotent and
     * safe to call repeatedly (tenant creation, migrations, the seeder): existing rows —
     * including ones HR has since edited — are never touched, only missing codes are
     * inserted.
     */
    public static function seedFor(Tenant $tenant): void
    {
        $existingCodes = self::where('tenant_id', $tenant->id)->pluck('code')->all();

        $i = count($existingCodes);
        foreach (self::SYSTEM_ITEMS as $code => [$name, $nameMs, $type, $epf, $perkeso, $taxable, $cap, $eaBox, $source, $isSystem]) {
            if (in_array($code, $existingCodes, true)) {
                continue;
            }

            // code/type/source/is_system aren't mass-assignable (see $fillable above —
            // they're seed-time-only, not HR-editable) — forceCreate them.
            self::forceCreate([
                'tenant_id' => $tenant->id,
                'code' => $code,
                'name' => $name,
                'name_ms' => $nameMs,
                'type' => $type,
                'epf_liable' => $epf,
                'perkeso_liable' => $perkeso,
                'pcb_taxable' => $taxable,
                'pcb_exempt_cap_yearly' => $cap,
                'ea_box' => $eaBox,
                'source' => $source,
                'is_system' => $isSystem,
                'active' => true,
                'sort_order' => $i++,
            ]);
        }
    }
}
