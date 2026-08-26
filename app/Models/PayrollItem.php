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
    /**
     * ea_box values follow Form C.P.8A (Pin. 2024) — see docs/statutory. Overtime is
     * B1(a) WITH salary (LHDN's "gross salary, wages or leave pay INCLUDING overtime
     * pay" wording), never its own box. Commission and bonus are B1(b). Allowances,
     * perquisites and awards are B1(c). A claim reimbursement is not employment income
     * at all (see the guide notes' subsistence-reimbursement exclusion) so it gets no
     * box and EaFormData excludes it from EA income entirely, rather than defaulting it
     * to "unclassified" like a genuinely un-mapped item.
     */
    public const array SYSTEM_ITEMS = [
        // Wages under s.2 EPF Act 1991 and the PERKESO payments-subject-to-contribution
        // list both plainly include ordinary basic pay.
        'basic-salary' => ['Basic Salary', 'Gaji Pokok', 'earning', true, true, true, null, 'B1(a)', 'salary', true],

        // Fixed monthly allowances are wages under both Acts, and taxable income —
        // an allowance, so Form EA box B1(c).
        'fixed-allowance' => ['Fixed Allowance', 'Elaun Tetap', 'earning', true, true, true, null, 'B1(c)', 'salary', true],

        // The generic bucket for HR's free-form "addition" lines on a payslip — same
        // statutory treatment as a fixed allowance (the safe default the calculator
        // used before this catalogue existed), same EA box.
        'other-addition' => ['Other Addition', 'Tambahan Lain', 'earning', true, true, true, null, 'B1(c)', 'manual', true],

        // EPF wages include bonus/commission; PERKESO's list explicitly EXCLUDES the
        // annual bonus from SOCSO/EIS wages — the two Acts diverge here. Both are B1(b).
        'bonus' => ['Bonus', 'Bonus', 'earning', true, false, true, null, 'B1(b)', 'manual', true],
        'commission' => ['Commission', 'Komisen', 'earning', true, true, true, null, 'B1(b)', 'manual', true],

        // s.2 EPF Act 1991 excludes overtime from "wages" outright; PERKESO's list
        // includes it. The opposite carve-out from bonus above. Form EA still wants it
        // folded into B1(a) alongside salary, not reported separately.
        'overtime' => ['Overtime', 'Kerja Lebih Masa', 'earning', false, true, true, null, 'B1(a)', 'overtime', true],

        // Travel/petrol/toll: not wages for EPF or PERKESO purposes, taxable but with
        // LHDN's RM6,000/year official-duties travel exemption (cap recorded, not
        // applied — a later pass reads pcb_exempt_cap_yearly). An allowance: B1(c).
        'travel-allowance' => ['Travelling / Petrol / Toll Allowance', 'Elaun Perjalanan / Minyak / Tol', 'earning', false, false, true, 6000.00, 'B1(c)', 'manual', true],

        // Meal and outstation allowances: ordinary wages, EPF + PERKESO liable, taxable,
        // and allowances — B1(c).
        'meal-allowance' => ['Meal Allowance', 'Elaun Makan', 'earning', true, true, true, null, 'B1(c)', 'manual', true],
        'outstation-allowance' => ['Outstation Allowance', 'Elaun Luar Kawasan', 'earning', true, true, true, null, 'B1(c)', 'manual', true],

        // A reimbursement is not wages at all — no EPF, no PERKESO, not taxable income,
        // and per the guide notes NOT employment income for Form EA purposes either, so
        // it carries no ea_box (EaFormData excludes 'claim' source lines outright).
        'claim-reimbursement' => ['Claim Reimbursement', 'Bayaran Balik Tuntutan', 'earning', false, false, false, null, null, 'claim', true],

        // A deduction that reduces the wage bases themselves (handled by the calculator
        // subtracting it from both bases), not a statutory line, so no D/E box.
        'unpaid-leave-deduction' => ['Unpaid Leave Deduction', 'Potongan Cuti Tanpa Gaji', 'deduction', false, false, false, null, null, 'leave', true],

        // Deduction side — no D/E box, these are private arrangements between employer
        // and employee, not statutory deductions LHDN wants reported on Form EA.
        'staff-loan' => ['Staff Loan', 'Pinjaman Staf', 'deduction', false, false, false, null, null, 'manual', true],
        'salary-advance' => ['Salary Advance', 'Pendahuluan Gaji', 'deduction', false, false, false, null, null, 'manual', true],
        // D3 "Zakat paid via salary deduction".
        'zakat' => ['Zakat', 'Zakat', 'deduction', false, false, false, null, 'D3', 'manual', true],
        // D2 "CP38 deductions remitted to LHDNM".
        'cp38' => ['CP38', 'CP38', 'deduction', false, false, false, null, 'D2', 'manual', true],
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
     * Create any SYSTEM_ITEMS codes this tenant doesn't already have, AND backfill
     * ea_box onto existing system items that predate the Form EA box mapping. Idempotent
     * and safe to call repeatedly (tenant creation, migrations, the seeder).
     *
     * Never-clobber rule, same as the rest of this catalogue: an existing row's other
     * flags (epf_liable, pcb_taxable, ...) are never touched once created — HR may have
     * deliberately edited them. ea_box gets the same treatment: only backfilled while
     * still NULL. Every system item's ea_box was NULL before this mapping existed (no
     * code ever wrote a non-null value), so a non-null ea_box here can only be an
     * HR edit or an already-backfilled value, either way left alone.
     */
    public static function seedFor(Tenant $tenant): void
    {
        $existing = self::where('tenant_id', $tenant->id)->get(['id', 'code', 'ea_box']);
        $existingCodes = $existing->pluck('code')->all();

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

        foreach ($existing as $row) {
            $default = self::SYSTEM_ITEMS[$row->code][7] ?? null;
            if ($row->ea_box === null && $default !== null) {
                self::where('id', $row->id)->update(['ea_box' => $default]);
            }
        }
    }
}
