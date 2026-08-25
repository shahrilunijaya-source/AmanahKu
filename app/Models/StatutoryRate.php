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
     * published defaults, keyed by type (currently just pcb). EPF, SOCSO and EIS are
     * not here — they follow fixed published schedules (EpfCalculator, SocsoCalculator,
     * EisCalculator) and are not tenant-editable. Single source of truth for both the
     * calculator and the rate-config UI.
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
     * PCB defaults (confirmed 2026-06-24) — the only type still tenant-editable.
     *
     * @return array<string, array<string, int|float>>
     */
    public static function defaults(): array
    {
        return [
            'pcb' => [
                'auto' => false,                // OFF by default — PCB stays manual entry (I-016)
                'individual_relief' => 9000,    // annual; editable per tenant
                'epf_relief_cap' => 4000,       // annual EPF/life relief cap
            ],
        ];
    }
}
