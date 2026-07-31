<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class StatutoryRate extends Model
{
    use BelongsToTenant;

    /**
     * Rate-config inputs only (no computed columns on this table). tenant_id is set by
     * BelongsToTenant.
     *
     * @var list<string>
     */
    protected $fillable = [
        'type',
        'config',
        'label',
        'effective_from',
    ];

    protected function casts(): array
    {
        return [
            'config' => 'array',
            'effective_from' => 'date',
        ];
    }

    /**
     * Active rate config for the current tenant: stored values layered over the
     * published defaults, keyed by type (epf|socso|eis). Single source of truth for
     * both the calculator and the rate-config UI.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function merged(): array
    {
        $stored = static::all()->keyBy('type');
        $rates = [];
        foreach (static::defaults() as $type => $default) {
            $rates[$type] = array_merge($default, $stored->get($type)?->config ?? []);
        }

        return $rates;
    }

    /**
     * Current MY statutory defaults (confirmed 2026-06-24). Editable per tenant;
     * verify against official KWSP/PERKESO tables before a real payroll run.
     *
     * @return array<string, array<string, int|float>>
     */
    public static function defaults(): array
    {
        return [
            'epf' => [
                'employee_pct' => 11,
                'employer_pct_below' => 13,   // wage <= threshold
                'employer_pct_above' => 12,   // wage > threshold
                'threshold' => 5000,
            ],
            'socso' => [
                'employer_pct' => 1.75,   // flat-% fallback (used only if use_brackets is cleared)
                'employee_pct' => 0.5,
                'wage_ceiling' => 6000,
                'use_brackets' => true,   // use the PERKESO stepped Jadual Caruman (StatutoryBrackets)
            ],
            'eis' => [
                'employer_pct' => 0.2,
                'employee_pct' => 0.2,
                'wage_ceiling' => 6000,
                'use_brackets' => true,
            ],
            'pcb' => [
                'auto' => false,                // OFF by default — PCB stays manual entry (I-016)
                'individual_relief' => 9000,    // annual; editable per tenant
                'epf_relief_cap' => 4000,       // annual EPF/life relief cap
            ],
        ];
    }
}
