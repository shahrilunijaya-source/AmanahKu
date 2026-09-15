<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Models\Employee;

trait ChecksReportingCycles
{
    /**
     * Would pointing $employeeId at $managerId form a reporting loop? Walks up the
     * proposed manager's chain; a cycle exists if we reach the employee being edited.
     * The visited guard also breaks on any pre-existing loop in stored data, so this
     * can never spin. Tenant-scoped automatically via the Employee global scope.
     */
    private function wouldCycle(int $employeeId, int $managerId): bool
    {
        $cursor = $managerId;
        $seen = [];

        while ($cursor !== null) {
            if ($cursor === $employeeId) {
                return true;
            }
            if (isset($seen[$cursor])) {
                break;
            }
            $seen[$cursor] = true;
            $cursor = Employee::whereKey($cursor)->value('reports_to_id');
        }

        return false;
    }
}
