<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Tenancy\CurrentTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Company onboarding-wizard progress (one row per tenant). Tenant-scoped via the
 * global scope, so reads/writes always target the active company.
 */
class CompanySetupProgress extends Model
{
    use BelongsToTenant;

    protected $table = 'company_setup_progress';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'steps' => 'array',
            'completed_at' => 'datetime',
        ];
    }

    /** Get-or-create the progress row for the active tenant. */
    public static function forCurrentTenant(): self
    {
        $tenantId = app(CurrentTenant::class)->id();

        return static::firstOrCreate(['tenant_id' => $tenantId], ['steps' => []]);
    }
}
