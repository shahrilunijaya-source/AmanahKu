<?php

namespace App\Support;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\WorkItem;
use App\Models\WorkItemComment;
use App\Ports\TrackPort;
use Illuminate\Support\Collection;

/**
 * CR-08: pushing a card comment to Track as an official project record.
 *
 * Who may push: PM and above (BoardRules::ASSIGNER_ROLES through effectiveRole()),
 * or the PE / PM named on the card's project. What may push: a card whose project
 * Track has pulled recently (projects.track_linked_at) and which is not labelled
 * Internal. What is pushed: the body with every @mention flattened to a plain name,
 * so Track never notifies anyone.
 *
 * Track pulls these rows through /api/v1/project-comments; the TrackPort call here
 * only records the intent in port_outbox, the contract's audit trail for anything
 * that leaves the app.
 */
final class TrackComments
{
    /** A project Track has not pulled for this long is treated as unlinked. */
    public const LINK_STALE_DAYS = 2;

    public function __construct(private TrackPort $track) {}

    public function canPush(string $tenantRole, WorkItem $item, Employee $employee): bool
    {
        if (in_array(Permissions::effectiveRole($tenantRole), BoardRules::ASSIGNER_ROLES, true)) {
            return true;
        }

        $project = $item->projectRef;

        return $project !== null && in_array($employee->id, [$project->pe_id, $project->pm_id], true);
    }

    /** Why the tick is disabled for this card, or null when it can be used. */
    public function disabledReason(WorkItem $item): ?string
    {
        $project = $item->projectRef;
        if ($project === null || $project->track_linked_at === null || $project->track_linked_at->lt(now()->subDays(self::LINK_STALE_DAYS))) {
            return 'This card\'s project is not linked to Track.';
        }
        if (in_array('internal', $item->labels ?? [], true)) {
            return 'Internal cards cannot be pushed to Track.';
        }

        return null;
    }

    /**
     * The exact text Track will show: "@Name" becomes "Name" for every mentionable
     * name on the card.
     *
     * @param  Collection<int, string>  $mentionableNames
     */
    public function filter(string $body, Collection $mentionableNames): string
    {
        foreach ($mentionableNames->sortByDesc(fn (string $n) => mb_strlen($n)) as $name) {
            $body = str_replace('@'.$name, $name, $body);
        }

        return trim($body);
    }

    /** First push, or a new version of an already pushed comment. */
    public function push(WorkItemComment $comment, Employee $by, string $trackBody): void
    {
        $versions = $comment->track_versions ?? [];
        $version = count($versions) + 1;
        $versions[] = ['v' => $version, 'body' => $trackBody, 'by' => $by->display_name, 'at' => now()->toIso8601String()];

        $comment->forceFill([
            'pushed_to_track_at' => $comment->pushed_to_track_at ?? now(),
            'track_body' => $trackBody,
            'track_version' => $version,
            'track_versions' => $versions,
        ])->save();

        $this->track->pushComment((string) $comment->work_item_id, $trackBody, $by);
        AuditLog::record($version === 1 ? 'comment.pushed_to_track' : 'comment.track_version', "comment {$comment->id} v{$version}");
    }

    public function withdraw(WorkItemComment $comment, string $reason): void
    {
        $comment->forceFill(['withdrawn_at' => now(), 'withdrawn_reason' => $reason])->save();

        $this->track->withdrawComment((string) $comment->work_item_id, (string) $comment->id);
        AuditLog::record('comment.withdrawn_from_track', "comment {$comment->id}: {$reason}");
    }
}
