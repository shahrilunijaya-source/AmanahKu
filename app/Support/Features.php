<?php

namespace App\Support;

/**
 * Central registry of admin-toggleable features. Each entry declares its type,
 * default, and (for enums) options. Resolution order at runtime:
 *
 *   platform-locked  → platform default wins (tenant cannot override)
 *   else             → tenant override ?? platform default ?? registry default
 *
 * Keep this the single source of truth: the toggle UIs, the resolver, and every
 * enforcement point read from here so nothing drifts.
 */
class Features
{
    /**
     * Toggleable modules: feature key → [label, screen ids it gates, category stage].
     * Disabling a module hides its nav entry and 403s its screens.
     * Core surfaces (dashboard, my-work, people, attendance, admin, security)
     * are intentionally NOT toggleable.
     *
     * The 3rd element is the minimum **company category stage** (1/2/3) at which the
     * module is included in the default package (Stage 1 = basic HR, Stage 2 adds the
     * HR-ops suite, Stage 3 adds AI/intelligence). Cumulative: a Stage-2 company gets
     * every stage ≤ 2 module. The category only seeds defaults; the resolved tenant
     * entitlement remains the source of truth (see FeatureManager::applyCategoryPackage).
     */
    public const MODULES = [
        'module.roster' => ['Roster & Shifts', ['roster', 'shiftswap'], 1],
        'module.leave' => ['Leave & Time-off', ['leave', 'calendar'], 1],
        'module.overtime' => ['Overtime', ['overtime'], 1],
        'module.events' => ['Company Events', ['events'], 1],
        'module.bookings' => ['Room & Vehicle Booking', ['rooms', 'vehicles'], 2],
        'module.payroll' => ['Payroll & Compensation', ['payroll'], 2],
        'module.loans' => ['Loans & Advances', ['loans'], 2],
        'module.pettycash' => ['Petty Cash', ['pettycash'], 2],
        'module.benefits' => ['Benefits', ['benefits'], 2],
        'module.wellness' => ['Wellness & EAP', ['wellness'], 2],
        'module.performance' => ['Performance (KPI, reviews, goals, skills)', ['kpi', 'achievements', 'reviews', 'goals', 'skills'], 2],
        'module.onboarding' => ['Onboarding', ['onboarding'], 2],
        'module.probation' => ['Probation', ['probation'], 2],
        'module.offboarding' => ['Resignation & Offboarding', ['resignation', 'offboarding'], 2],
        'module.compliance' => ['Compliance & Licenses', ['compliance'], 2],
        'module.recruitment' => ['Recruitment & Referrals', ['recruitment', 'referrals'], 2],
        'module.cases' => ['Disciplinary Cases', ['cases'], 2],
        'module.learning' => ['Training & Learning', ['training', 'learning', 'handbook'], 2],
        'module.documents' => ['Document Vault', ['documents'], 1],
        'module.claims' => ['Claims & Expenses', ['claims', 'expenses', 'travel'], 2],
        'module.helpdesk' => ['Helpdesk', ['helpdesk'], 2],
        'module.assets' => ['Asset Register', ['assets'], 2],
        'module.reports' => ['Reports', ['reports'], 1],
        'module.surveys' => ['Surveys & Suggestions', ['surveys', 'ideas'], 2],
        'module.knowledge' => ['Knowledge Bank', ['knowledge-bank'], 2],
        'module.messages' => ['Messaging', ['messages'], 2],
        'module.ai' => ['AI Workforce Intelligence', ['workload'], 3],
    ];

    /**
     * Behavioural (non-module) settings. type bool|enum.
     * `scope` = 'tenant' (per company) or 'platform' (global, super-admin only).
     */
    public const SETTINGS = [
        'security.2fa' => [
            'label' => 'Two-factor authentication',
            'type' => 'enum', 'scope' => 'tenant', 'default' => 'optional',
            'options' => ['off' => 'Off', 'optional' => 'Optional', 'required' => 'Required'],
            'help' => 'Required forces every member to enrol 2FA before using the app.',
        ],
        'security.passkey' => [
            'label' => 'Passkey sign-in',
            'type' => 'enum', 'scope' => 'tenant', 'default' => 'optional',
            'options' => ['off' => 'Off', 'optional' => 'Optional'],
            'help' => 'Allow members to register WebAuthn passkeys.',
        ],
        'ai.assistant' => [
            'label' => 'AI assistant panel',
            'type' => 'bool', 'scope' => 'tenant', 'default' => true,
            'help' => 'The in-app AI assistant slide-over.',
        ],
        'payroll.auto_pcb' => [
            'label' => 'Auto PCB/MTD estimate',
            'type' => 'bool', 'scope' => 'tenant', 'default' => false,
            'help' => 'Estimate monthly tax automatically (HR still reviews).',
        ],
        'payroll.statutory_mode' => [
            'label' => 'Statutory calculation mode',
            'type' => 'enum', 'scope' => 'tenant', 'default' => 'brackets',
            'options' => ['flat' => 'Flat percentage', 'brackets' => 'Stepped brackets'],
            'help' => 'How SOCSO/EIS are computed.',
        ],
        'payroll.four_eyes' => [
            'label' => 'Require payroll approval before finalize',
            'type' => 'bool', 'scope' => 'tenant', 'default' => false,
            'help' => 'Block finalizing a run until it has been approved (four-eyes control).',
        ],
        'claims.medical_cap' => [
            'label' => 'Medical claim annual cap (RM)',
            'type' => 'number', 'scope' => 'tenant', 'default' => 500,
            'min' => 0, 'max' => 1000000,
            'help' => 'Most one employee can be reimbursed for medical claims per calendar year.',
        ],
        'platform.registration' => [
            'label' => 'Public self-registration',
            'type' => 'bool', 'scope' => 'platform', 'default' => true,
            'help' => 'Allow anyone to create an account at /register.',
        ],
    ];

    /** All keys with their registry default. */
    public static function defaults(): array
    {
        $out = [];
        foreach (self::MODULES as $key => [$label, $screens]) {
            $out[$key] = true;
        }
        foreach (self::SETTINGS as $key => $meta) {
            $out[$key] = $meta['default'];
        }

        return $out;
    }

    /** The module key that gates a given screen id, or null if the screen is core. */
    public static function moduleForScreen(string $screen): ?string
    {
        foreach (self::MODULES as $key => [$label, $screens]) {
            if (in_array($screen, $screens, true)) {
                return $key;
            }
        }

        return null;
    }

    /** Registry default for a key (used when no platform/tenant value is stored). */
    public static function default(string $key): mixed
    {
        return self::defaults()[$key] ?? null;
    }

    public static function meta(string $key): ?array
    {
        return self::SETTINGS[$key] ?? null;
    }

    /** Human label for any key (module or setting). */
    public static function label(string $key): string
    {
        if (isset(self::MODULES[$key])) {
            return self::MODULES[$key][0];
        }

        return self::SETTINGS[$key]['label'] ?? $key;
    }

    /** Normalise a raw stored/registry value to bool for boolean features. */
    public static function asBool(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? (bool) $value;
    }

    /** Minimum company-category stage (1/2/3) at which a module is included. */
    public static function stageFor(string $moduleKey): int
    {
        return self::MODULES[$moduleKey][2] ?? 1;
    }

    /**
     * Module keys included in the default package for a category stage level,
     * cumulatively (every module whose stage ≤ $level).
     *
     * @return array<int, string>
     */
    public static function modulesUpToStage(int $level): array
    {
        $out = [];
        foreach (self::MODULES as $key => $def) {
            if (($def[2] ?? 1) <= $level) {
                $out[] = $key;
            }
        }

        return $out;
    }
}
