<?php

declare(strict_types=1);

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Hides child cards (work_items.parent_id set) from every ordinary WorkItem query.
 *
 * A child lives under its parent and nowhere else: not in a board column, not in a
 * count, not on a timesheet, not in the archive list. Twenty-odd query sites already
 * read work_items and every one of them wants parents only, so the rule lives on the
 * model rather than being repeated at each of them. The two places that want children
 * opt out: WorkItem::children() and WorkItem::resolveRouteBinding().
 */
class ParentOnly implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $builder->whereNull($model->getTable().'.parent_id');
    }
}
