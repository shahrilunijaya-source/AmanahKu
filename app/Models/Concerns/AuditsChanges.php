<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\AuditLog;
use App\Tenancy\CurrentTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Writes one AuditLog row per audited field on create/update/delete. The model
 * defines which fields count via audited(): array. Skipped entirely when the
 * model has no tenant_id and no active tenant — an unscoped write (a seeder
 * running outside any tenant loop) logs nothing rather than throwing.
 */
trait AuditsChanges
{
    public static function bootAuditsChanges(): void
    {
        static::created(function (Model $model) {
            if (self::auditingDisabled($model)) {
                return;
            }

            AuditLog::change($model, 'created', null, $model->getKey());
        });

        static::updated(function (Model&HasAuditedFields $model) {
            if (self::auditingDisabled($model)) {
                return;
            }

            foreach ($model->getChanges() as $key => $new) {
                if (! in_array($key, $model->audited(), true)) {
                    continue;
                }

                AuditLog::change($model, $key, $model->getOriginal($key), $new);
            }
        });

        static::deleted(function (Model $model) {
            if (self::auditingDisabled($model)) {
                return;
            }

            AuditLog::change($model, 'deleted', $model->getKey(), null);
        });
    }

    private static function auditingDisabled(Model $model): bool
    {
        return empty($model->tenant_id) && ! app(CurrentTenant::class)->check();
    }
}
