<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Models\Claim;
use App\Models\CompanyEvent;
use App\Models\Employee;
use App\Models\EventRsvp;
use App\Models\KnowledgeContribution;
use App\Models\LeaveRequest;
use App\Models\OvertimeRequest;
use App\Models\Tenant;
use App\Models\Ticket;
use App\Models\TotSession;
use App\Models\WorkItem;
use App\Services\FeatureManager;
use App\Support\Amanahku;
use App\Support\Permissions;

/**
 * Sidebar navigation model for AppController::screen(). Split out of
 * AppController purely for file size — navScreenIndex() is also consumed by
 * BuildsSettingsData::featureRows() on the same controller instance.
 */
trait BuildsNav
{
    /** Build the sidebar nav with active/expanded flags for the current screen. */
    private function navModel(string $screen, string $role, ?Tenant $tenant): array
    {
        $items = Amanahku::nav();
        // Collapse 'director' → 'management' up front: a director is a strict management
        // super-set (Permissions::effectiveRole), so every role gate below — Administration,
        // the oversight group, per-node allowlists — must treat it as management. Without
        // this the raw 'director' string fell through and hid admin/cases + oversight links.
        $role = Permissions::effectiveRole($role);
        // Administration is for privileged roles only.
        if (! in_array($role, ['management', 'hr'], true)) {
            $items = array_values(array_filter($items, fn ($i) => ! in_array($i['id'], ['admin', 'cases'], true)));
        }
        // The whole "My Team" section plus the Insights oversight group are for
        // managers and above — hidden from plain employees. A plain employee keeps
        // My Work and Learning, which is everything about their own week. The screens
        // themselves stay server-gated in AppController::screen (canSeeAll) for anyone
        // who reaches them by URL, so this is tidiness, not the access control.
        // 'reports' used to be its own top-level id here; it is folded into oversight's
        // children now, so hiding the 'oversight' parent already hides it too.
        if ($role === 'employee') {
            $items = array_values(array_filter(
                $items,
                fn ($i) => $i['section'] !== 'My Team' && $i['id'] !== 'oversight',
            ));
        }

        // Drop nav entries whose gating module is disabled for this tenant. Leaf items
        // are filtered by their own screen id; groups have disabled children removed and
        // are themselves dropped if nothing reachable remains. Core (un-gated) screens stay.
        $features = app(FeatureManager::class);
        $allowed = fn (string $id) => $features->screenAllowed($tenant, $id);
        // Optional per-node role allowlist: a nav item/child may declare
        // 'roles' => [...] to restrict itself even when its parent is shown to all
        // (e.g. the Company Setup child under Onboarding — privileged-only).
        $roleOk = fn (array $node) => ! isset($node['roles']) || in_array($role, $node['roles'], true);
        $items = array_values(array_filter(array_map(function (array $item) use ($allowed, $roleOk) {
            if (! empty($item['children'])) {
                $item['children'] = array_values(array_filter($item['children'], fn ($c) => $allowed($c['id']) && $roleOk($c)));

                return $item['children'] === [] ? null : $item;
            }

            return ($allowed($item['id']) && $roleOk($item)) ? $item : null;
        }, $items)));

        // Children may deep-link with a query (e.g. board ?type=core|adhoc). A child is
        // active only when both screen id AND its query type match the current request.
        // Default 'core' so a plain /app/board highlights "Tasks & Assignments".
        $currentType = request()->query('type', 'core');
        $matches = fn (array $c) => $c['id'] === $screen
            && (! isset($c['query']['type']) || $c['query']['type'] === $currentType);

        $attention = $this->navAttention($tenant);

        return array_map(function (array $item) use ($screen, $matches, $attention) {
            $children = $item['children'] ?? [];
            $childActive = collect($children)->contains($matches);
            $item['active'] = $item['id'] === $screen;
            $item['hasChildren'] = ! empty($children);
            $item['expanded'] = $childActive || $item['active'];
            $item['attention'] = $attention[$item['id']] ?? 0;
            $item['children'] = array_map(function (array $c) use ($matches, $attention) {
                $c['active'] = $matches($c);
                $c['attention'] = $attention[$c['id']] ?? 0;

                return $c;
            }, $children);

            return $item;
        }, $items);
    }

    /**
     * How many things on each screen are waiting for THIS person to act — the dot the
     * sidebar puts on a nav row. Only requests they may actually decide on: the same
     * verify/approve scopes the screens themselves use, so the dot can never point at a
     * queue that turns out to be empty when they open it.
     *
     * @return array<string, int>
     */
    private function navAttention(?Tenant $tenant): array
    {
        $request = request();
        $features = app(FeatureManager::class);

        $sources = [
            'leave' => LeaveRequest::class,
            'claims' => Claim::class,
            'overtime' => OvertimeRequest::class,
        ];

        $counts = [];
        foreach ($sources as $screen => $model) {
            if (! $features->screenAllowed($tenant, $screen)) {
                continue;
            }

            $n = $this->scopeToVerify($model::query(), $request)->count()
                + $this->scopeToApprove($model::query(), $request)->count();

            if ($n > 0) {
                $counts[$screen] = $n;
            }
        }

        foreach ($this->navPersonalAttention($request->attributes->get('employee')) as $screen => $n) {
            if ($n > 0 && $features->screenAllowed($tenant, $screen)) {
                $counts[$screen] = $n;
            }
        }

        return $counts;
    }

    /**
     * The other half of the dot: things addressed to this person by name rather than
     * queues they are the decider for. Each one has to be able to CLEAR by itself, or
     * the dot becomes wallpaper — so every count below narrows to the state where the
     * person still has to do something, not merely to everything that mentions them.
     *
     * @return array<string, int>
     */
    private function navPersonalAttention(?Employee $employee): array
    {
        if (! $employee) {
            return [];
        }

        // T.A.A. — a card somebody else put on your board that you have not picked up.
        // Clears the moment you drag it out of To Do. Self-assigned cards never dot.
        $board = WorkItem::where('employee_id', $employee->id)
            ->whereNotNull('assigned_by_id')
            ->where('assigned_by_id', '!=', $employee->id)
            ->where('status', 'todo')
            ->whereNull('archived_at')
            ->count();

        // Helpdesk — a ticket parked on you that nobody has started. 'in_progress' is
        // deliberately excluded: you already know about a ticket you are working on.
        $helpdesk = Ticket::where('assignee_employee_id', $employee->id)
            ->where('status', 'open')
            ->count();

        // Knowledge Bank — the monthly contribution you still owe, but only once the month
        // is nearly out. The debt itself starts on the 1st; dotting from then would put a
        // red mark on every person's sidebar for three weeks running, which teaches people
        // to ignore dots. The last seven days is when it is actually a nudge.
        $daysLeft = now()->daysInMonth - now()->day;
        $knowledge = $daysLeft < 7 && ! KnowledgeContribution::where('employee_id', $employee->id)
            ->where('year', (int) now()->year)
            ->where('month', (int) now()->month)
            ->where('submitted', true)
            ->exists() ? 1 : 0;

        return [
            'board' => $board,
            'helpdesk' => $helpdesk,
            'knowledge-bank' => $knowledge,
            'events' => $this->unansweredEventInvites($employee),
            'tot' => $this->upcomingTotSlot($employee),
        ];
    }

    /**
     * Upcoming events that name this person in the description and that they have not
     * answered yet. Tagging is a JSON array of employee ids, and CompanyEvent forbids
     * whereJsonContains (sqlite and MySQL disagree about it), so the tag test happens in
     * PHP over the upcoming rows — a short list by definition. External events are
     * skipped: they take a registration link instead of an RSVP, so the dot could never
     * clear for them.
     */
    private function unansweredEventInvites(Employee $employee): int
    {
        $upcoming = CompanyEvent::whereDate('event_date', '>=', now()->toDateString())
            ->get(['id', 'tagged_employee_ids', 'host']);

        $tagged = $upcoming->filter(fn (CompanyEvent $e) => ! $e->isExternal()
            && in_array($employee->id, $e->taggedIds(), true));

        if ($tagged->isEmpty()) {
            return 0;
        }

        $answered = EventRsvp::where('employee_id', $employee->id)
            ->whereIn('company_event_id', $tagged->pluck('id'))
            ->pluck('company_event_id');

        return $tagged->whereNotIn('id', $answered)->count();
    }

    /**
     * 1 when this person's own TOT slot is coming up inside a fortnight — the same
     * two-week horizon the TotReminder command nudges on, so the dot and the bell agree.
     * The session date is computed (first Saturday of the slot's month), never stored, so
     * the date test has to happen in PHP. Slots already done, skipped or marked not-TOT
     * are nothing to act on.
     */
    private function upcomingTotSlot(Employee $employee): int
    {
        $sessions = TotSession::where('year', '>=', (int) now()->year)
            ->whereNotIn('status', ['done', 'skipped', 'not_tot'])
            ->with(['presenters:id', 'presenter:id'])
            ->get();

        return $sessions->filter(function (TotSession $s) use ($employee) {
            if (! $s->isPresentedBy($employee)) {
                return false;
            }
            $date = TotSession::firstSaturday($s->year, $s->month);

            // The session date is midnight, so a plain isFuture() would go quiet on the
            // morning of the session itself — compare against the end of that day.
            return $date->copy()->endOfDay()->isFuture() && $date->lte(now()->addDays(14));
        })->count();
    }

    /**
     * Map every nav screen id to its sidebar placement: section (en/ms), the
     * nav-item label (en/ms), and ordering ints. Children inherit their parent's
     * section + order so a module that gates a child screen still lands in the
     * right group. Lets the Features panel group module toggles like the nav.
     *
     * @return array<string, array{section:string,section_ms:string,label:string,label_ms:string,order:int,section_order:int}>
     */
    private function navScreenIndex(): array
    {
        $index = [];
        $sectionOrder = [];
        $i = 0;

        foreach (Amanahku::nav() as $item) {
            $section = $item['section'];
            $sectionOrder[$section] ??= count($sectionOrder);
            $order = $i++;

            $place = fn (string $label, string $labelMs): array => [
                'section' => $section,
                'section_ms' => $item['section_ms'] ?? $section,
                'label' => $label,
                'label_ms' => $labelMs,
                'order' => $order,
                'section_order' => $sectionOrder[$section],
            ];

            $index[$item['id']] = $place($item['label'], $item['label_ms'] ?? $item['label']);

            foreach ($item['children'] ?? [] as $child) {
                // First-seen wins — a duplicate child id (e.g. 'board' twice) keeps
                // its first placement and never clobbers a real top-level screen.
                $index[$child['id']] ??= $place($child['label'], $child['label_ms'] ?? $child['label']);
            }
        }

        return $index;
    }
}
