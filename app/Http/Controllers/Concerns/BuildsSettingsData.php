<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Http\Controllers\AdminController;
use App\Models\Branch;
use App\Models\Department;
use App\Models\EmploymentType;
use App\Models\StaffLevel;
use App\Models\Tenant;
use App\Services\FeatureManager;
use App\Support\Features;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\Request;

/**
 * Company Settings screen data (org lists + the module/feature toggle panel)
 * for AppController::screen(). Split out of AppController purely for file
 * size — featureRows() relies on BuildsNav::navScreenIndex() via $this.
 */
trait BuildsSettingsData
{
    private function settingsData(Request $request): array
    {
        $tenant = app(CurrentTenant::class)->get();
        $canManage = $this->hasTenantRole($request, ['management', 'hr']);

        return [
            'company' => $tenant->load('companyCategory'),
            'branches' => Branch::orderBy('name')->get(),
            'departments' => Department::withCount('employees')->orderBy('name')->get(),
            'staffLevels' => StaffLevel::orderByRaw('`rank` IS NULL, `rank`')->orderBy('name')->get(),
            'employmentTypes' => EmploymentType::orderBy('name')->get(),
            'locationTypes' => app(AdminController::class)->locationTypes(),
            'canManageFeatures' => $canManage,
            'featureRows' => $canManage ? $this->featureRows($tenant) : [],
        ];
    }

    /**
     * Tenant-scope feature rows for the Company Settings panel: every module +
     * non-platform setting, with its resolved value and lock state. Platform-scope
     * keys (e.g. platform.registration) are intentionally excluded.
     *
     * @return array{modules:array,settings:array}
     */
    private function featureRows(Tenant $tenant): array
    {
        $features = app(FeatureManager::class);
        $nav = $this->navScreenIndex();

        // Bucket the module toggles under their sidebar section so an admin can
        // map each toggle to where it lives in the nav. A module is placed by its
        // first gated screen; both section order and within-section order follow
        // the sidebar (nav order), not the registry order.
        $sections = [];
        foreach (Features::MODULES as $key => [$label, $screens]) {
            $place = $nav[$screens[0]] ?? null;
            $sectionEn = $place['section'] ?? 'Other';

            $sections[$sectionEn] ??= [
                'section' => $sectionEn,
                'section_ms' => $place['section_ms'] ?? $sectionEn,
                'order' => $place['section_order'] ?? 999,
                'rows' => [],
            ];

            // The nav items this single toggle switches on/off — shown as a caption.
            $navItems = [];
            foreach ($screens as $screen) {
                if (isset($nav[$screen])) {
                    $navItems[] = ['en' => $nav[$screen]['label'], 'ms' => $nav[$screen]['label_ms']];
                }
            }

            $sections[$sectionEn]['rows'][] = [
                'key' => $key,
                'label' => $label,
                'type' => 'bool',
                'value' => $features->value($tenant, $key),
                'locked' => $features->platformLocked($key),
                'nav_items' => $navItems,
                'order' => $place['order'] ?? 999,
            ];
        }

        usort($sections, fn ($a, $b) => $a['order'] <=> $b['order']);
        foreach ($sections as &$section) {
            usort($section['rows'], fn ($a, $b) => $a['order'] <=> $b['order']);
        }
        unset($section);

        $settings = [];
        foreach (Features::SETTINGS as $key => $meta) {
            if (($meta['scope'] ?? 'tenant') !== 'tenant') {
                continue;
            }
            $settings[] = [
                'key' => $key,
                'label' => $meta['label'],
                'type' => $meta['type'],
                'options' => $meta['options'] ?? null,
                'min' => $meta['min'] ?? null,
                'max' => $meta['max'] ?? null,
                'help' => $meta['help'] ?? null,
                'value' => $features->value($tenant, $key),
                'locked' => $features->platformLocked($key),
            ];
        }

        return ['modules' => $sections, 'settings' => $settings];
    }
}
