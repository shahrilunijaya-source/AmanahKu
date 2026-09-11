<?php

declare(strict_types=1);

namespace App\Models\Concerns;

/** Implemented by every model using AuditsChanges: which fields get one audit row per change. */
interface HasAuditedFields
{
    /** @return list<string> */
    public function audited(): array;
}
