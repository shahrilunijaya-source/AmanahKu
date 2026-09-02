<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Concerns;

use App\Models\Employee;
use Illuminate\Support\Collection;

/**
 * Resolving a staff member from the nickname people actually say ("Nabil"),
 * because no MCP tool hands out employee ids — WorkItemsTool returns an
 * assignee's name and nothing else, so a caller asked to pass an id has
 * nowhere to get one. Shared by assign_task and update_card so both refuse
 * ambiguity the same way.
 */
trait ResolvesEmployeeNames
{
    /**
     * Active staff whose nickname or full name contains $needle. An exact hit on
     * either wins outright, so "Nabil" resolves cleanly even when a "Nabilah" also
     * contains it; anything short of that stays ambiguous on purpose and is handed
     * back to the caller rather than guessed at.
     *
     * Archived staff are excluded here rather than matched and then refused — they
     * are not assignable at all, so a name that only matches an archived person
     * reads as "nobody", which is what it means.
     *
     * @return Collection<int, Employee>
     */
    protected function matchByName(string $needle, int $tenantId): Collection
    {
        $rows = Employee::query()->active()->where('tenant_id', $tenantId)
            ->where(fn ($q) => $q->where('nickname', 'like', '%'.$needle.'%')->orWhere('name', 'like', '%'.$needle.'%'))
            ->orderBy('name')->get();

        $exact = $rows->filter(fn (Employee $e) => strcasecmp((string) $e->nickname, $needle) === 0
            || strcasecmp($e->name, $needle) === 0);

        return $exact->isNotEmpty() ? $exact->values() : $rows;
    }

    /**
     * The one person $needle names, or a message explaining why it named none or
     * several. Never guesses between people — a card handed to the wrong person
     * emails the wrong person.
     */
    protected function resolveByName(string $needle, int $tenantId): Employee|string
    {
        $matches = $this->matchByName($needle, $tenantId);

        if ($matches->isEmpty()) {
            return "No active staff member here matches '".$needle."'.";
        }

        if ($matches->count() > 1) {
            return "'".$needle."' matches ".$matches->count().' people: '.
                $matches->map(fn (Employee $e) => $e->display_name.' (employee_id '.$e->id.')')->join(', ').
                '. Re-run naming the employee_id you mean.';
        }

        return $matches->first();
    }
}
