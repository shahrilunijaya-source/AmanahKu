<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Concerns;

use App\Models\AppNotification;
use App\Models\Employee;
use App\Models\WorkItem;
use Illuminate\Support\Collection;

/**
 * Resolving a staff member from the nickname people actually say ("Nabil"),
 * because no MCP tool hands out employee ids — WorkItemsTool returns an
 * assignee's name and nothing else, so a caller asked to pass an id has
 * nowhere to get one. Shared by assign_task, create_card and update_card so
 * they all refuse ambiguity the same way, and add card participants the same way.
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

    /**
     * Turns a `participants` list of spoken names into the `participant_ids` the
     * rest of the tool already understands, in place. Every name has to resolve to
     * exactly one active person or the whole write is refused — a half-applied
     * participant list would silently drop somebody off the card.
     *
     * @param  array<string, mixed>  $data
     * @return list<string>|null The resolved display names, or null if no names were sent.
     */
    protected function namesToParticipantIds(array &$data, int $tenantId): ?array
    {
        if (! array_key_exists('participants', $data)) {
            return null;
        }

        $names = [];
        $ids = [];
        $errors = [];

        foreach ($data['participants'] as $needle) {
            $found = $this->resolveByName($needle, $tenantId);

            if (is_string($found)) {
                $errors[] = $found;

                continue;
            }

            $ids[] = $found->id;
            $names[] = $found->display_name;
        }

        abort_if($errors !== [], 422, implode(' ', $errors));

        unset($data['participants']);
        $data['participant_ids'] = $ids;

        return $names;
    }

    /** Mirrors WorkItemController::syncParticipants() — never the owner, active tenant employees only. */
    protected function syncParticipants(WorkItem $item, array $ids, Employee $actor): void
    {
        $target = Employee::active()
            ->whereIn('id', array_filter($ids))
            ->where('id', '!=', $item->employee_id)
            ->pluck('id');

        $before = $item->participants()->pluck('employees.id');
        $item->participants()->sync($target);

        foreach ($target->diff($before) as $addedId) {
            AppNotification::send(
                Employee::find($addedId)?->user_id,
                $actor->display_name.' added you to a task',
                $item->title,
                route('app.screen', 'board'),
                mail: true,
            );
        }
    }

    /**
     * Who syncParticipants() would actually put on a card owned by $ownerId, by
     * display name: active staff in this tenant, never the owner. The preview shows
     * this so the approver sees exactly who confirm will add and email.
     *
     * @param  list<int>  $ids
     * @return list<string>
     */
    protected function participantNames(array $ids, int $ownerId, int $tenantId): array
    {
        return Employee::active()->where('tenant_id', $tenantId)
            ->whereIn('id', array_filter($ids))
            ->where('id', '!=', $ownerId)
            ->orderBy('name')->get()
            ->map(fn (Employee $e) => $e->display_name)->values()->all();
    }
}
