<?php

namespace App\Models\Concerns;

use App\Models\Tenant;
use App\Tenancy\CurrentTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Scopes a model's queries to the active tenant and auto-fills tenant_id on create.
 * Apply to every tenant-owned model. The Tenant model itself must NOT use this.
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        $context = app(CurrentTenant::class);

        static::addGlobalScope('tenant', function (Builder $builder) use ($context) {
            if ($context->check()) {
                $builder->where($builder->getModel()->getTable().'.tenant_id', $context->id());
            }
        });

        static::creating(function (Model $model) use ($context) {
            if ($context->check() && empty($model->tenant_id)) {
                $model->tenant_id = $context->id();

                return;
            }

            // Fail-closed on writes (AK-DB-06). A tenant-owned row created with neither an
            // active tenant NOR an explicit tenant_id is almost always a bug — a queued job
            // or new command that forgot to set CurrentTenant. Reads stay fail-open (admin /
            // seed paths depend on it), but an unscoped write is refused loudly rather than
            // inserting a row that escapes tenant isolation.
            if (empty($model->tenant_id)) {
                throw new \RuntimeException(sprintf(
                    'Refusing to create %s with no tenant_id and no active tenant context. '
                    .'Set CurrentTenant before writing tenant-owned models (e.g. inside a per-tenant loop).',
                    $model::class,
                ));
            }
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
