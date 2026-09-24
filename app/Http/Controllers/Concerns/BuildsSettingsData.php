<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Http\Controllers\AdminController;
use App\Models\Branch;
use App\Models\Department;
use App\Models\EasterEgg;
use App\Models\Employee;
use App\Models\EmploymentType;
use App\Models\GreetingLine;
use App\Models\StaffLevel;
use App\Models\Tenant;
use App\Services\FeatureManager;
use App\Support\EasterEggBank;
use App\Support\Features;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

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
            // Statutory & tax card: who can sign the LHDN staff forms, and whether the
            // HRD Corp block applies (levy switched on).
            'signatoryOptions' => Employee::active()->orderBy('name')->get(['id', 'name', 'position']),
            'hrdfOn' => (string) (app(FeatureManager::class)->value($tenant, 'payroll.hrdf') ?? 'off') !== 'off',
            'canManageFeatures' => $canManage,
            'featureRows' => $canManage ? $this->featureRows($tenant) : [],
            'greetingLines' => $canManage ? $this->greetingLinesOrdered() : collect(),
            'greetingPending' => $canManage ? GreetingLine::whereNull('approved_at')->orderBy('created_at')->get() : collect(),
            'greetingTriggers' => GreetingLine::TRIGGERS,
            // CR-31: dashboard/board easter-egg bank, same card shape as the greetings one above.
            'easterEggs' => $canManage ? EasterEgg::orderBy('kind')->orderBy('id')->get() : collect(),
            'easterEggKinds' => EasterEggBank::KINDS,
        ];
    }

    /** Approved greeting lines, bucket priority order then trigger. */
    private function greetingLinesOrdered(): Collection
    {
        $bucketOrder = array_flip(GreetingLine::BUCKETS);

        return GreetingLine::approved()->get()
            ->sortBy(fn (GreetingLine $l) => sprintf('%02d-%s', $bucketOrder[$l->bucket] ?? 99, $l->trigger))
            ->values();
    }

    /**
     * Tenant-scope feature rows for the Company Settings panel: every module +
     * non-platform setting, with its resolved value, lock state, and English +
     * Malay copy (label_ms/help_ms/options_ms, section_ms) for the bilingual
     * card. Platform-scope keys (e.g. platform.registration) are intentionally
     * excluded, as are Features::HIDDEN_SETTINGS unless already switched on.
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
            // Descoped modules have no screen blade any more, so switching one on here
            // would render screens.empty rather than the module. Hide the row instead of
            // offering a toggle that cannot deliver. Keyed on the *resolved* value, not
            // Features::OFF, so a company that already has an override stays able to
            // switch it back off. Reviving a module is a super-admin action (the
            // SuperAdmin\FeatureController matrix still lists every key).
            if (in_array($key, Features::OFF, true) && ! $features->value($tenant, $key)) {
                continue;
            }

            $place = $nav[$screens[0]] ?? null;
            $sectionEn = $place['section'] ?? 'Other';
            // navScreenIndex() always fills section_ms when $place exists (falling back to
            // the English section name itself), so this ?? only fires for a module whose
            // first screen isn't in the nav at all, e.g. module.messages.
            $sectionMs = $place['section_ms'] ?? 'Lain-lain';

            $sections[$sectionEn] ??= [
                'section' => $sectionEn,
                'section_ms' => $sectionMs,
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
                'label_ms' => Features::labelMs($key),
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
            if ($meta['scope'] !== 'tenant') {
                continue;
            }
            // Same idea as the OFF-module skip above: hidden settings (no AI package to
            // sell yet) drop off the company card unless a super admin already switched
            // one on for this tenant from the platform matrix, so that override stays
            // visible and switchable back off.
            if (in_array($key, Features::HIDDEN_SETTINGS, true) && ! $features->enabled($tenant, $key)) {
                continue;
            }
            $settings[] = [
                'key' => $key,
                'label' => $meta['label'],
                'label_ms' => $meta['label_ms'],
                'type' => $meta['type'],
                'options' => $meta['options'] ?? null,
                'options_ms' => $meta['options_ms'] ?? $meta['options'] ?? null,
                'min' => $meta['min'] ?? null,
                'max' => $meta['max'] ?? null,
                'help' => $meta['help'],
                'help_ms' => $meta['help_ms'],
                'value' => $features->value($tenant, $key),
                'locked' => $features->platformLocked($key),
            ];
        }

        return ['modules' => $sections, 'settings' => $settings];
    }
}
