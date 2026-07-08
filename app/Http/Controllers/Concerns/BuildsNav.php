<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Models\Tenant;
use App\Services\FeatureManager;
use App\Support\Amanahku;

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
        // Administration is for privileged roles only.
        if (! in_array($role, ['management', 'hr'], true)) {
            $items = array_values(array_filter($items, fn ($i) => ! in_array($i['id'], ['admin', 'cases'], true)));
        }
        // Probation and the Reports & Audit oversight group are for managers and
        // above — hidden from plain employees. The screens themselves stay server-
        // gated in AppController::screen (canSeeAll) for anyone who reaches them by URL.
        if ($role === 'employee') {
            $items = array_values(array_filter($items, fn ($i) => ! in_array($i['id'], ['probation', 'oversight'], true)));
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

        return array_map(function (array $item) use ($screen, $matches) {
            $children = $item['children'] ?? [];
            $childActive = collect($children)->contains($matches);
            $item['active'] = $item['id'] === $screen;
            $item['hasChildren'] = ! empty($children);
            $item['expanded'] = $childActive || $item['active'];
            $item['children'] = array_map(function (array $c) use ($matches) {
                $c['active'] = $matches($c);

                return $c;
            }, $children);

            return $item;
        }, $items);
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
