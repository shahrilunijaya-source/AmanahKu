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
     * module is included in the default package. Packages are now Stage 1 (basic HR)
     * and Stage 2 (HR operations) only; Stage 3 was dropped 2026-09-17 (no AI package
     * sold). module.ai keeps stage 3 here, a stage no package reaches, so category alone
     * never turns it on. Cumulative: a Stage-2 company gets every stage ≤ 2 module. The
     * category only seeds defaults; the resolved tenant entitlement remains the source
     * of truth (see FeatureManager::applyCategoryPackage).
     */
    public const MODULES = [
        'module.roster' => ['Roster & Shifts', ['roster', 'shiftswap'], 1],
        'module.leave' => ['Leave & Time-off', ['leave', 'calendar', 'leave-report'], 1],
        'module.overtime' => ['Overtime', ['overtime'], 1],
        'module.events' => ['Company Events', ['events'], 1],
        'module.bookings' => ['Room & Vehicle Booking', ['rooms', 'vehicles'], 2],
        'module.payroll' => ['Payroll & Compensation', ['payroll', 'payroll-my', 'payroll-transaction', 'payroll-process', 'payroll-review', 'payroll-payment', 'payroll-form'], 2],
        'module.loans' => ['Loans & Advances', ['loans'], 2],
        'module.pettycash' => ['Petty Cash', ['pettycash'], 2],
        'module.benefits' => ['Benefits', ['benefits'], 2],
        'module.wellness' => ['Wellness & EAP', ['wellness'], 2],
        'module.performance' => ['Performance (KPI, reviews, goals, skills)', ['kpi', 'achievements', 'reviews', 'goals', 'skills'], 2],
        'module.onboarding' => ['Onboarding', ['onboarding', 'onboarding-content'], 2],
        'module.probation' => ['Probation', ['probation'], 2],
        'module.offboarding' => ['Resignation & Offboarding', ['resignation', 'offboarding'], 2],
        'module.compliance' => ['Compliance & Licenses', ['compliance'], 2],
        'module.recruitment' => ['Recruitment & Referrals', ['recruitment', 'referrals'], 2],
        'module.cases' => ['Disciplinary Cases', ['cases'], 2],
        'module.learning' => ['Training & Learning', ['training', 'learning', 'handbook'], 2],
        'module.documents' => ['Document Vault', ['documents'], 1],
        'module.claims' => ['Claims', ['claims', 'claim-approvals'], 2],
        'module.expenses' => ['Expense Reports & Travel', ['expenses', 'travel'], 2],
        'module.helpdesk' => ['Helpdesk', ['helpdesk'], 2],
        'module.assets' => ['Asset Register', ['assets'], 2],
        'module.reports' => ['Reports', ['reports'], 1],
        'module.surveys' => ['Surveys & Suggestions', ['surveys', 'ideas'], 2],
        'module.knowledge' => ['Knowledge Bank', ['knowledge-bank', 'tot', 'tot-roster'], 2],
        'module.messages' => ['Messaging', ['messages'], 2],
        'module.sharedresources' => ['Shared Resources', ['shared-resources'], 2],
        'module.profiletest' => ['Employee Profile Test', ['profile-test', 'profile-test-admin', 'profile-test-results'], 2],
        'module.ai' => ['AI Workforce Intelligence', ['workload'], 3],
    ];

    /** Malay label for each module key, same order as MODULES. See labelMs(). */
    public const MODULE_LABELS_MS = [
        'module.roster' => 'Jadual Kerja & Syif',
        'module.leave' => 'Cuti',
        'module.overtime' => 'Kerja Lebih Masa',
        'module.events' => 'Acara Syarikat',
        'module.bookings' => 'Tempahan Bilik & Kenderaan',
        'module.payroll' => 'Gaji & Pampasan',
        'module.loans' => 'Pinjaman & Pendahuluan',
        'module.pettycash' => 'Wang Runcit',
        'module.benefits' => 'Faedah',
        'module.wellness' => 'Kesejahteraan & EAP',
        'module.performance' => 'Prestasi (KPI, penilaian, matlamat, kemahiran)',
        'module.onboarding' => 'Onboarding',
        'module.probation' => 'Percubaan',
        'module.offboarding' => 'Peletakan Jawatan & Offboarding',
        'module.compliance' => 'Pematuhan & Lesen',
        'module.recruitment' => 'Pengambilan & Rujukan',
        'module.cases' => 'Kes Tatatertib',
        'module.learning' => 'Latihan & Pembelajaran',
        'module.documents' => 'Peti Dokumen',
        'module.claims' => 'Tuntutan',
        'module.expenses' => 'Laporan Perbelanjaan & Perjalanan',
        'module.helpdesk' => 'Helpdesk',
        'module.assets' => 'Daftar Aset',
        'module.reports' => 'Laporan',
        'module.surveys' => 'Tinjauan & Cadangan',
        'module.knowledge' => 'Bank Pengetahuan',
        'module.messages' => 'Mesej',
        'module.sharedresources' => 'Sumber Kongsi',
        'module.profiletest' => 'Ujian Profil Pekerja',
        'module.ai' => 'Risikan Tenaga Kerja AI',
    ];

    /**
     * Behavioural (non-module) settings. type bool|enum.
     * `scope` = 'tenant' (per company) or 'platform' (global, super-admin only).
     * `label_ms`/`help_ms`/`options_ms` are the Malay counterparts shown when
     * $store.ui.lang is 'ms'; a setting without them falls back to English (labelMs()).
     */
    public const SETTINGS = [
        'security.2fa' => [
            'label' => 'Two-factor authentication',
            'label_ms' => 'Pengesahan dua faktor',
            'type' => 'enum', 'scope' => 'tenant', 'default' => 'optional',
            'options' => ['optional' => 'Optional', 'required' => 'Required'],
            'options_ms' => ['optional' => 'Pilihan', 'required' => 'Wajib'],
            'help' => 'Required forces every member to enrol 2FA before using the app.',
            'help_ms' => 'Wajib: setiap ahli perlu sediakan kod log masuk sebelum boleh guna aplikasi.',
        ],
        'security.passkey' => [
            'label' => 'Passkey sign-in',
            'label_ms' => 'Log masuk dengan passkey',
            'type' => 'enum', 'scope' => 'tenant', 'default' => 'optional',
            'options' => ['off' => 'Off', 'optional' => 'Optional'],
            'options_ms' => ['off' => 'Tutup', 'optional' => 'Pilihan'],
            'help' => 'Lets members sign in with a passkey (face, fingerprint or device PIN). Off blocks passkey sign-in for this company\'s staff.',
            'help_ms' => 'Membolehkan ahli log masuk dengan passkey (wajah, cap jari atau PIN peranti). Tutup menyekat log masuk passkey untuk staf syarikat ini.',
        ],
        'ai.assistant' => [
            'label' => 'AI assistant panel',
            'label_ms' => 'Panel pembantu AI',
            'type' => 'bool', 'scope' => 'tenant', 'default' => false,
            'help' => 'The in-app AI assistant slide-over.',
            'help_ms' => 'Panel pembantu AI dalam aplikasi.',
        ],
        'payroll.four_eyes' => [
            'label' => 'Require payroll approval before finalize',
            'label_ms' => 'Perlu kelulusan sebelum gaji dimuktamadkan',
            'type' => 'bool', 'scope' => 'tenant', 'default' => false,
            'help' => 'Only used when payroll is on: a pay run cannot be finalized until someone approves it.',
            'help_ms' => 'Hanya digunakan jika modul gaji dihidupkan: larian gaji tidak boleh dimuktamadkan sehingga diluluskan.',
        ],
        'payroll.payslip_acknowledgement' => [
            'label' => 'Ask staff to acknowledge payslips',
            'label_ms' => 'Minta staf mengaku terima payslip',
            'type' => 'bool', 'scope' => 'tenant', 'default' => false,
            'help' => 'Shows an Acknowledge button on a published payslip and lists who has not pressed it yet.',
            'help_ms' => 'Menunjukkan butang Akui pada payslip yang diterbitkan dan menyenaraikan siapa yang belum menekannya.',
        ],
        'payroll.hrdf' => [
            'label' => 'HRD Corp levy',
            'label_ms' => 'Levi HRD Corp',
            'type' => 'enum', 'scope' => 'tenant', 'default' => 'off',
            'options' => ['off' => 'Not registered', '1' => '1% (10 or more Malaysian employees)', '0.5' => '0.5% (5 to 9, voluntary)'],
            'options_ms' => ['off' => 'Tidak berdaftar', '1' => '1% (10 atau lebih pekerja warganegara)', '0.5' => '0.5% (5 hingga 9, sukarela)'],
            'help' => 'Employer-side levy on basic pay plus fixed allowances for Malaysian employees. Nothing is deducted from staff. Needs the HRD Corp registration number in Settings.',
            'help_ms' => 'Levi majikan atas gaji pokok dan elaun tetap pekerja warganegara. Tiada potongan daripada staf. Perlukan nombor pendaftaran HRD Corp di Tetapan.',
        ],
        'claims.medical_cap' => [
            'label' => 'Medical claim annual cap (RM)',
            'label_ms' => 'Had tahunan tuntutan perubatan (RM)',
            'type' => 'number', 'scope' => 'tenant', 'default' => 500,
            'min' => 0, 'max' => 1000000,
            'help' => 'Most one employee can be reimbursed for medical claims per calendar year.',
            'help_ms' => 'Jumlah paling tinggi seorang pekerja boleh dibayar balik untuk tuntutan perubatan dalam satu tahun kalendar.',
        ],
        'platform.registration' => [
            'label' => 'Signup links',
            'type' => 'bool', 'scope' => 'platform', 'default' => true,
            'help' => 'Lets people with a signup link create their own company. Off closes the signup page, even for links not used yet.',
        ],
    ];

    /**
     * Modules that are fully built but deliberately shipped OFF by default. Two
     * reasons land a key here, and both mean the same thing to the resolver:
     *
     *  - descoped — outside the current delivery scope (attendance, timesheets,
     *    T.A.A., TOT, claims, leave, plus basic HR admin). The models, controllers,
     *    and routes stay so the module can be brought back, not rewritten.
     *  - not signed off — built, but not approved for release yet (module.ai).
     *
     * This is a DEFAULT, not a lock: a super-admin can still switch any of these on
     * per company from the platform matrix, which writes an override that beats this
     * list. Kept in one place so "why is this off" is answerable by reading here
     * instead of hunting for a scattered inline condition.
     *
     * Reviving a module takes TWO steps, not one. Deleting its line here restores the
     * gate, but its screen blade was deleted in the UI revamp, so the screen renders
     * screens.empty until the blade is restored:
     *   git checkout pre-blade-purge -- resources/views/screens/<screen>.blade.php
     * The tenant-facing Features panel hides these rows for the same reason
     * (BuildsSettingsData::featureRows); the super-admin matrix still lists them all.
     * See docs/DECISIONS.md, "Descoped screen blades deleted".
     */
    public const OFF = [
        // Descoped — kept in the codebase, hidden from the app.
        'module.roster',
        'module.overtime',
        'module.events',
        'module.bookings',
        'module.payroll',
        'module.loans',
        'module.pettycash',
        'module.benefits',
        'module.wellness',
        'module.performance',
        'module.onboarding',
        'module.probation',
        'module.offboarding',
        'module.compliance',
        'module.recruitment',
        'module.cases',
        'module.learning',
        'module.expenses',
        'module.assets',
        'module.surveys',
        'module.sharedresources',
        // Built but not signed off for release (see docs/ROADMAP.md I-025).
        'module.ai',
    ];

    /**
     * Tenant-scope settings the company Features card does not offer, even though
     * they still resolve normally for enforcement everywhere else. ai.assistant: no
     * AI package exists yet, so a company has nothing to switch it on for; a
     * super-admin can still turn it on per company from the platform matrix, which
     * writes an override the card must keep honouring (see BuildsSettingsData).
     */
    public const HIDDEN_SETTINGS = ['ai.assistant'];

    /** All keys with their registry default. */
    public static function defaults(): array
    {
        $out = [];
        foreach (self::MODULES as $key => [$label, $screens]) {
            $out[$key] = ! in_array($key, self::OFF, true);
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

    /** Malay label for any key (module or setting), falling back to the English label. */
    public static function labelMs(string $key): string
    {
        if (isset(self::MODULE_LABELS_MS[$key])) {
            return self::MODULE_LABELS_MS[$key];
        }

        return self::SETTINGS[$key]['label_ms'] ?? self::label($key);
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
            if ($def[2] <= $level) {
                $out[] = $key;
            }
        }

        return $out;
    }
}
