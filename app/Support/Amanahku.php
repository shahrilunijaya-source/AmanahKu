<?php

namespace App\Support;

use App\Models\Employee;
use Illuminate\Support\Str;

/**
 * Mock data source for the Amanahku HR platform shell + screens.
 * Ported from the design reference (fromClaudeDesign/Unijaya-HR.dc.html).
 * Replace with real Eloquent models once the data layer is built.
 */
class Amanahku
{
    /** Status/workload colour swatches. */
    public const SWATCH = [
        'green' => 'var(--success)',
        'amber' => 'var(--amber)',
        'red' => 'var(--error)',
        'grey' => 'var(--muted-soft)',
        'info' => 'var(--info)',
        'muted' => 'var(--muted-soft)',
    ];

    /** Tenants the signed-in user can access. */
    public static function tenants(): array
    {
        return [
            ['id' => 't1', 'name' => 'Unijaya Resources', 'initials' => 'UR', 'color' => '#d6232b', 'plan' => 'Enterprise', 'meta' => '4 branches · 186 employees'],
            ['id' => 't2', 'name' => 'Shell Seremban 2', 'initials' => 'SS', 'color' => '#1f8a65', 'plan' => 'Business', 'meta' => '2 branches · 142 employees'],
            ['id' => 't3', 'name' => 'Petron Tg Lumpur', 'initials' => 'PT', 'color' => '#3a6ea5', 'plan' => 'Business', 'meta' => '1 branch · 84 employees'],
        ];
    }

    public static function tenant(string $id = 't1'): array
    {
        foreach (self::tenants() as $t) {
            if ($t['id'] === $id) {
                return $t;
            }
        }

        return self::tenants()[0];
    }

    /** Sidebar navigation tree. `screen` keys map to named routes. */
    public static function nav(): array
    {
        // Grouped into sections for the sidebar. Each top-level item carries a
        // `section` / `section_ms` tag; the sidebar renders one collapsible group
        // per section (see partials/sidebar.blade.php). Order here defines both the
        // section order and the item order within each section.
        $s = fn (string $en, string $ms, array $item) => array_merge(['section' => $en, 'section_ms' => $ms], $item);

        return [
            // ── Overview ──────────────────────────────────────────────────────
            $s('Overview', 'Ringkasan', ['id' => 'dash', 'label' => 'Dashboard', 'label_ms' => 'Papan Pemuka', 'icon' => 'M3 3h7v7H3zM14 3h7v7h-7zM14 14h7v7h-7zM3 14h7v7H3z']),
            // ── Time & People ─────────────────────────────────────────────────
            $s('Time & People', 'Masa & Warga', ['id' => 'people', 'label' => 'People', 'label_ms' => 'Warga Kerja', 'icon' => 'M17 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M9.5 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8M23 21v-2a4 4 0 0 0-3-3.87M16.5 3.13a4 4 0 0 1 0 7.75', 'children' => [
                ['id' => 'directory', 'label' => 'Employees', 'label_ms' => 'Pekerja'],
                ['id' => 'profile', 'label' => 'Employee Profile', 'label_ms' => 'Profil Pekerja'],
                ['id' => 'profile-test', 'label' => 'My Profile Test', 'label_ms' => 'Ujian Profil Saya'],
                ['id' => 'orgchart', 'label' => 'Organisation Chart', 'label_ms' => 'Carta Organisasi'],
            ]]),            $s('Time & People', 'Masa & Warga', ['id' => 'roster', 'label' => 'Roster', 'label_ms' => 'Jadual Syif', 'icon' => 'M8 2v3M16 2v3M3.5 9.5h17M5 5h14a1.5 1.5 0 0 1 1.5 1.5V19A1.5 1.5 0 0 1 19 20.5H5A1.5 1.5 0 0 1 3.5 19V6.5A1.5 1.5 0 0 1 5 5Z']),
            $s('Time & People', 'Masa & Warga', ['id' => 'shiftswap', 'label' => 'Shift Swaps', 'label_ms' => 'Pertukaran Syif', 'icon' => 'M16 3h5v5M21 3l-7 7M8 21H3v-5M3 21l7-7']),
            $s('Time & People', 'Masa & Warga', ['id' => 'leave', 'label' => 'Leave', 'label_ms' => 'Cuti', 'icon' => 'M3 4h18v18H3zM16 2v4M8 2v4M3 10h18']),
            $s('Time & People', 'Masa & Warga', ['id' => 'calendar', 'label' => 'Time-off Calendar', 'label_ms' => 'Kalendar Cuti', 'icon' => 'M8 2v4M16 2v4M3 10h18M5 4h14a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2zM9 16l2 2 4-4']),
            $s('Time & People', 'Masa & Warga', ['id' => 'overtime', 'label' => 'Overtime', 'label_ms' => 'Kerja Lebih Masa', 'icon' => 'M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20zM12 6v6l4 2M12 2v2M2 12h2']),

            // ── Workplace ─────────────────────────────────────────────────────
            $s('Workplace', 'Tempat Kerja', ['id' => 'events', 'label' => 'Events', 'label_ms' => 'Acara', 'icon' => 'M8 2v4M16 2v4M3 9h18M5 4h14a2 2 0 0 1 2 2v13a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2zM12 14h.01M16 14h.01M8 18h.01M12 18h.01']),
            $s('Workplace', 'Tempat Kerja', ['id' => 'rooms', 'label' => 'Room Booking', 'label_ms' => 'Tempahan Bilik', 'icon' => 'M3 21h18M5 21V7l8-4v18M19 21V11l-6-4M9 9h.01M9 12h.01M9 15h.01M9 18h.01']),
            $s('Workplace', 'Tempat Kerja', ['id' => 'vehicles', 'label' => 'Vehicle Booking', 'label_ms' => 'Tempahan Kenderaan', 'icon' => 'M5 17h14M5 17a2 2 0 1 0 4 0M5 17a2 2 0 1 1 4 0m6 0a2 2 0 1 0 4 0m-4 0a2 2 0 1 1 4 0M3 17V9l2-4h10l3 4h1a2 2 0 0 1 2 2v6M3 9h15']),
            $s('Workplace', 'Tempat Kerja', ['id' => 'travel', 'label' => 'Travel', 'label_ms' => 'Perjalanan', 'icon' => 'M17.8 19.2 16 11l3.5-3.5C21 6 21.5 4 21 3c-1-.5-3 0-4.5 1.5L13 8 4.8 6.2c-.5-.1-.9.1-1.1.5l-.3.5c-.2.5-.1 1 .3 1.3L9 12l-2 3H4l-1 1 3 2 2 3 1-1v-3l3-2 3.5 5.3c.3.4.8.5 1.3.3l.5-.2c.4-.3.6-.7.5-1.2z']),
            $s('Workplace', 'Tempat Kerja', ['id' => 'assets', 'label' => 'Assets', 'label_ms' => 'Aset', 'icon' => 'M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z']),
            $s('Workplace', 'Tempat Kerja', ['id' => 'shared-resources', 'label' => 'Shared Resources', 'label_ms' => 'Sumber Bersama', 'icon' => 'M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4']),

            // ── Pay & Benefits ────────────────────────────────────────────────
            $s('Pay & Benefits', 'Gaji & Faedah', ['id' => 'payroll', 'label' => 'Payroll', 'label_ms' => 'Gaji', 'icon' => 'M2 7a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2zM12 9a3 3 0 1 0 0 6 3 3 0 0 0 0-6M6 8v8M18 8v8']),
            $s('Pay & Benefits', 'Gaji & Faedah', ['id' => 'loans', 'label' => 'Loans & Advances', 'label_ms' => 'Pinjaman & Pendahuluan', 'icon' => 'M12 1v22M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6']),
            $s('Pay & Benefits', 'Gaji & Faedah', ['id' => 'pettycash', 'label' => 'Petty Cash', 'label_ms' => 'Wang Runcit', 'icon' => 'M2 7a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2zM12 12h.01M6 9v6M18 9v6']),
            $s('Pay & Benefits', 'Gaji & Faedah', ['id' => 'benefits', 'label' => 'Benefits', 'label_ms' => 'Faedah', 'icon' => 'M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10zM9.5 11l1.7 1.7L14.5 9']),
            $s('Pay & Benefits', 'Gaji & Faedah', ['id' => 'wellness', 'label' => 'Wellness & EAP', 'label_ms' => 'Kesihatan & EAP', 'icon' => 'M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.7l-1-1.1a5.5 5.5 0 0 0-7.8 7.8l1 1.1L12 21l7.8-7.5 1-1.1a5.5 5.5 0 0 0 0-7.8z']),
            $s('Pay & Benefits', 'Gaji & Faedah', ['id' => 'claims', 'label' => 'Claims & Requests', 'label_ms' => 'Tuntutan & Permohonan', 'icon' => 'M5 2v20l2-1 2 1 2-1 2 1 2-1 2 1V2l-2 1-2-1-2 1-2-1-2 1z']),
            $s('Pay & Benefits', 'Gaji & Faedah', ['id' => 'expenses', 'label' => 'Expense Reports', 'label_ms' => 'Laporan Perbelanjaan', 'icon' => 'M9 14l6-6M9 8h.01M15 14h.01M5 3h14a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z']),

            // ── Talent & Growth ───────────────────────────────────────────────
            $s('Talent & Growth', 'Bakat & Pembangunan', ['id' => 'recruitment', 'label' => 'Recruitment', 'label_ms' => 'Pengambilan', 'icon' => 'M20 7h-4V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v2H4a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2M10 5h4v2h-4z']),
            $s('Talent & Growth', 'Bakat & Pembangunan', ['id' => 'referrals', 'label' => 'Referrals', 'label_ms' => 'Rujukan', 'icon' => 'M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8M19 8v6M22 11h-6']),
            // Onboarding groups the per-hire checklist with the company Setup Wizard
            // (moved out of Administration). Setup is role-gated to privileged staff so
            // it never leaks into a new hire's nav even though the parent is shown to all.
            $s('Talent & Growth', 'Bakat & Pembangunan', ['id' => 'onboarding', 'label' => 'Onboarding', 'label_ms' => 'Onboarding', 'icon' => 'M9 2h6a1 1 0 0 1 1 1v2H8V3a1 1 0 0 1 1-1zM8 4H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2h-2', 'children' => [
                ['id' => 'onboarding', 'label' => 'New-hire Checklist', 'label_ms' => 'Senarai Semak'],
                ['id' => 'setup', 'label' => 'Company Setup', 'label_ms' => 'Persediaan Syarikat', 'roles' => ['management', 'hr']],
            ]]),
            $s('Talent & Growth', 'Bakat & Pembangunan', ['id' => 'knowledge-bank', 'label' => 'Knowledge Bank', 'label_ms' => 'Bank Pengetahuan', 'icon' => 'M9 21h6M12 3a6 6 0 0 0-6 6c0 2.22 1.21 4.16 3 5.2V17a1 1 0 0 0 1 1h4a1 1 0 0 0 1-1v-2.8c1.79-1.04 3-2.98 3-5.2a6 6 0 0 0-6-6z']),
            $s('Talent & Growth', 'Bakat & Pembangunan', ['id' => 'probation', 'label' => 'Probation', 'label_ms' => 'Percubaan', 'icon' => 'M12 8v4l3 3M3.05 11a9 9 0 1 1 .5 4M3 4v4h4']),
            $s('Talent & Growth', 'Bakat & Pembangunan', ['id' => 'perf', 'label' => 'Performance', 'label_ms' => 'Prestasi', 'icon' => 'M23 6l-9.5 9.5-5-5L1 18M17 6h6v6', 'children' => [
                ['id' => 'kpi', 'label' => 'KPI', 'label_ms' => 'KPI'],
                ['id' => 'achievements', 'label' => 'Achievements', 'label_ms' => 'Pencapaian'],
                ['id' => 'reviews', 'label' => 'Reviews', 'label_ms' => 'Semakan'],
                ['id' => 'goals', 'label' => 'Goals & OKRs', 'label_ms' => 'Matlamat & OKR'],
                ['id' => 'skills', 'label' => 'Skills Matrix', 'label_ms' => 'Matriks Kemahiran'],
            ]]),
            $s('Talent & Growth', 'Bakat & Pembangunan', ['id' => 'training', 'label' => 'Training', 'label_ms' => 'Latihan', 'icon' => 'M4 19.5A2.5 2.5 0 0 1 6.5 17H20M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5z']),
            $s('Talent & Growth', 'Bakat & Pembangunan', ['id' => 'learning', 'label' => 'Learning Library', 'label_ms' => 'Pustaka Pembelajaran', 'icon' => 'M22 10v6M2 10l10-5 10 5-10 5zM6 12v5c3 3 9 3 12 0v-5']),
            // Resignation + exit clearance share one module (module.offboarding);
            // grouped so the two halves of a departure sit under one nav entry.
            $s('Talent & Growth', 'Bakat & Pembangunan', ['id' => 'offboarding', 'label' => 'Offboarding', 'label_ms' => 'Offboarding', 'icon' => 'M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9', 'children' => [
                ['id' => 'resignation', 'label' => 'Resignations', 'label_ms' => 'Perletakan Jawatan'],
                ['id' => 'offboarding', 'label' => 'Exit Clearance', 'label_ms' => 'Pelepasan Keluar'],
            ]]),

            // ── Compliance & Docs ─────────────────────────────────────────────
            $s('Compliance & Docs', 'Pematuhan & Dokumen', ['id' => 'compliance', 'label' => 'Compliance & Licenses', 'label_ms' => 'Pematuhan & Lesen', 'icon' => 'M12 2l8 4v6c0 5-3.5 8.5-8 10-4.5-1.5-8-5-8-10V6l8-4zM12 8v4M12 16h.01']),
            $s('Compliance & Docs', 'Pematuhan & Dokumen', ['id' => 'cases', 'label' => 'Cases', 'label_ms' => 'Kes', 'icon' => 'M12 2l8 4v6c0 5-3.5 8.5-8 10-4.5-1.5-8-5-8-10V6l8-4zM9 12l2 2 4-4']),
            $s('Compliance & Docs', 'Pematuhan & Dokumen', ['id' => 'handbook', 'label' => 'Handbook', 'label_ms' => 'Buku Panduan', 'icon' => 'M2 3h7a3 3 0 0 1 3 3v15a2.5 2.5 0 0 0-2.5-2.5H2zM22 3h-7a3 3 0 0 0-3 3v15a2.5 2.5 0 0 1 2.5-2.5H22z']),
            $s('Compliance & Docs', 'Pematuhan & Dokumen', ['id' => 'documents', 'label' => 'Documents', 'label_ms' => 'Dokumen', 'icon' => 'M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8zM14 2v6h6M16 13H8M16 17H8M10 9H8']),

            // ── Insights & Support ────────────────────────────────────────────
            $s('Insights & Support', 'Analitik & Sokongan', ['id' => 'reports', 'label' => 'Reports', 'label_ms' => 'Laporan', 'icon' => 'M12 20V10M18 20V4M6 20v-4']),
            $s('Insights & Support', 'Analitik & Sokongan', ['id' => 'surveys', 'label' => 'Surveys', 'label_ms' => 'Tinjauan', 'icon' => 'M3 3v18h18M8 17V9M13 17V5M18 17v-6']),
            $s('Insights & Support', 'Analitik & Sokongan', ['id' => 'ideas', 'label' => 'Suggestion Box', 'label_ms' => 'Peti Cadangan', 'icon' => 'M9 18h6M10 22h4M12 2a7 7 0 0 0-4 12.7c.6.5 1 1.3 1 2.1V17h6v-.2c0-.8.4-1.6 1-2.1A7 7 0 0 0 12 2z']),
            $s('Insights & Support', 'Analitik & Sokongan', ['id' => 'workload', 'label' => 'AI Workforce Intel', 'label_ms' => 'Risikan Tenaga Kerja AI', 'icon' => 'M12 3l1.9 4.6L18.5 9.5 13.9 11.4 12 16l-1.9-4.6L5.5 9.5l4.6-1.9zM19 15l.8 2 2 .8-2 .8-.8 2-.8-2-2-.8 2-.8z']),
            $s('Insights & Support', 'Analitik & Sokongan', ['id' => 'helpdesk', 'label' => 'Helpdesk', 'label_ms' => 'Helpdesk', 'icon' => 'M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20zM12 8a4 4 0 1 0 0 8 4 4 0 0 0 0-8M4.93 4.93l4.24 4.24M14.83 14.83l4.24 4.24M14.83 9.17l4.24-4.24']),
            $s('Insights & Support', 'Analitik & Sokongan', ['id' => 'messages', 'label' => 'Messages', 'label_ms' => 'Mesej', 'icon' => 'M4 4h16a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H8l-4 4V6a2 2 0 0 1 2-2z']),

            // ── Reports & Audit ───────────────────────────────────────────────
            // Oversight surface for anyone who manages staff (manager / management /
            // hr). Pulled out of Administration so managers get these without the full
            // admin toolset. Nav-gated in BuildsNav::navModel (hidden from employees);
            // screens server-gated in AppController::screen via canSeeAll.
            $s('Reports & Audit', 'Laporan & Audit', ['id' => 'oversight', 'label' => 'Reports & Audit', 'label_ms' => 'Laporan & Audit', 'icon' => 'M9 17v-6M12 17v-3M15 17v-8M5 3h14a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z', 'children' => [
                ['id' => 'attendance-report', 'label' => 'Attendance Reports', 'label_ms' => 'Laporan Kehadiran'],
                ['id' => 'leave-report', 'label' => 'Leave Reports', 'label_ms' => 'Laporan Cuti'],
                ['id' => 'timesheet-reports', 'label' => 'Timesheet Reports', 'label_ms' => 'Laporan Lembaran Masa'],
                ['id' => 'feedback', 'label' => 'Feedback Inbox', 'label_ms' => 'Peti Maklum Balas'],
                ['id' => 'audit', 'label' => 'Audit Logs', 'label_ms' => 'Log Audit'],
            ]]),

            // ── Administration ────────────────────────────────────────────────
            $s('Administration', 'Pentadbiran', ['id' => 'admin', 'label' => 'Administration', 'label_ms' => 'Pentadbiran', 'icon' => 'M4 21v-7M4 10V3M12 21v-9M12 8V3M20 21v-5M20 12V3M1 14h6M9 8h6M17 16h6', 'children' => [
                // Setup Wizard moved to the Onboarding group (Talent & Growth).
                ['id' => 'settings', 'label' => 'Company Settings', 'label_ms' => 'Tetapan Syarikat'],
                ['id' => 'attendance-admin', 'label' => 'Attendance Setup', 'label_ms' => 'Tetapan Kehadiran'],
                ['id' => 'leave-setup', 'label' => 'Leave Setup', 'label_ms' => 'Tetapan Cuti'],
                ['id' => 'position', 'label' => 'Position & Manday Rates', 'label_ms' => 'Pangkat & Kadar Manday'],
                ['id' => 'profile-test-admin', 'label' => 'Profile Test Editor', 'label_ms' => 'Editor Ujian Profil'],
                ['id' => 'timesheet-setup', 'label' => 'Timesheet Setup', 'label_ms' => 'Tetapan Lembaran Masa'],
                ['id' => 'roles', 'label' => 'Roles & Permissions', 'label_ms' => 'Peranan & Kebenaran'],
            ]]),
        ];
    }

    /** Persona definitions for the dashboard role switcher. */
    public static function personas(): array
    {
        return [
            ['id' => 'employee', 'label' => 'Employee'],
            ['id' => 'manager', 'label' => 'Manager'],
            ['id' => 'management', 'label' => 'Management'],
            ['id' => 'hr', 'label' => 'HR'],
        ];
    }

    /**
     * Which personas a role may PREVIEW in the switcher. Preview is a downward/lateral
     * courtesy, never an upward one: a manager may look at the employee and manager views,
     * but must not preview the management or HR dashboards (their elevated stats and queues).
     * Management/HR are the top tier and may preview everything. Pass the effectiveRole()
     * value (director already collapsed to management). This is the whitelist that closes
     * AK-AUTHZ-02 — both the visible tabs and the switch guard read from it.
     *
     * @var array<string, array<int, string>>
     */
    public const PERSONA_ACCESS = [
        'employee' => ['employee'],
        'manager' => ['employee', 'manager'],
        'management' => ['employee', 'manager', 'management', 'hr'],
        'hr' => ['employee', 'manager', 'management', 'hr'],
    ];

    /** Persona ids a role is allowed to preview (employee-only fallback for unknown roles). */
    public static function personaIdsFor(string $role): array
    {
        return self::PERSONA_ACCESS[$role] ?? ['employee'];
    }

    /** Persona switcher tabs filtered to what $role may preview. */
    public static function personasFor(string $role): array
    {
        $allowed = self::personaIdsFor($role);

        return array_values(array_filter(
            self::personas(),
            fn (array $p): bool => in_array($p['id'], $allowed, true),
        ));
    }

    public static function roleLabel(string $persona): string
    {
        return [
            'employee' => 'Employee',
            'manager' => 'Department Manager',
            'management' => 'Senior Management',
            'hr' => 'HR Admin',
        ][$persona] ?? 'Employee';
    }

    /** Per-screen page title, subtitle and breadcrumb trail. */
    public static function page(string $screen): array
    {
        $pages = [
            // Title/sub here are placeholders only — dashHeading() overrides them per
            // persona (see AppController::screen). This entry survives for the crumb.
            'dash' => ['title' => 'Dashboard', 'title_ms' => 'Papan Pemuka', 'sub' => '', 'sub_ms' => '', 'crumb' => ['Dashboard']],
            'directory' => ['title' => 'Employee Directory', 'title_ms' => 'Direktori Pekerja', 'sub' => 'Everyone in the company, across all branches and departments.', 'sub_ms' => 'Semua orang dalam syarikat, merangkumi semua cawangan dan jabatan.', 'crumb' => ['People', 'Employees']],
            'profile' => ['title' => 'Nurul Iman binti Hassan', 'title_ms' => 'Nurul Iman binti Hassan', 'sub' => 'Senior HR Executive · People & Culture · Petaling Jaya HQ', 'sub_ms' => 'Eksekutif Kanan HR · People & Culture · Ibu Pejabat Petaling Jaya', 'crumb' => ['People', 'Employees', 'Nurul Iman']],
            'board' => ['title' => 'Tasks, Assignments & Adhoc', 'title_ms' => 'Tugasan, Penugasan & Adhoc', 'sub' => 'Plan, assign and review work across your team.', 'sub_ms' => 'Rancang, agih dan semak kerja seluruh pasukan anda.', 'crumb' => ['My Work', 'Board']],
            'team-board' => ['title' => 'Team Board — All Tasks', 'title_ms' => 'Papan Pasukan — Semua Tugasan', 'sub' => 'Every staff member\'s tasks, assignments and adhoc work — company-wide, read-only.', 'sub_ms' => 'Tugasan, penugasan dan kerja adhoc setiap staf — seluruh syarikat, baca sahaja.', 'crumb' => ['My Work', 'Team Board']],
            'timesheets' => ['title' => 'Timesheets', 'title_ms' => 'Lembaran Masa', 'sub' => 'Allocate your week by % across projects, then submit it.', 'sub_ms' => 'Peruntukkan minggu anda mengikut % merentas projek, kemudian hantar.', 'crumb' => ['My Work', 'Timesheets']],
            'learning' => ['title' => 'Learning Library', 'title_ms' => 'Pustaka Pembelajaran', 'sub' => 'Browse courses, enrol and track your progress.', 'sub_ms' => 'Layari kursus, daftar dan jejak kemajuan anda.', 'crumb' => ['Learning Library']],
            'skills' => ['title' => 'Skills Matrix', 'title_ms' => 'Matriks Kemahiran', 'sub' => 'Self-rate competencies; managers verify and spot team gaps.', 'sub_ms' => 'Nilai sendiri kompetensi; pengurus mengesahkan dan mengesan jurang pasukan.', 'crumb' => ['Performance', 'Skills Matrix']],
            'referrals' => ['title' => 'Employee Referrals', 'title_ms' => 'Rujukan Pekerja', 'sub' => 'Refer candidates to open roles and track referral bonuses.', 'sub_ms' => 'Rujuk calon kepada jawatan kosong dan jejak bonus rujukan.', 'crumb' => ['Referrals']],
            'workload' => ['title' => 'AI Workforce Intelligence', 'title_ms' => 'AI Workforce Intelligence', 'sub' => 'Capacity, risk and recommended actions across the workforce.', 'sub_ms' => 'Kapasiti, risiko dan tindakan disyorkan merentas tenaga kerja.', 'crumb' => ['AI Workforce Intelligence']],
            'attendance' => ['title' => 'Attendance', 'title_ms' => 'Kehadiran', 'sub' => 'Clock in to start your day. Location captured at check-in only.', 'sub_ms' => 'Daftar masuk untuk mulakan hari anda. Lokasi direkod ketika daftar masuk sahaja.', 'crumb' => ['Attendance']],
            'attendance-admin' => ['title' => 'Attendance Setup', 'title_ms' => 'Tetapan Kehadiran', 'sub' => 'Geofences, client sites and work arrangements that drive attendance rules.', 'sub_ms' => 'Geofence, lokasi klien dan susunan kerja yang memacu peraturan kehadiran.', 'crumb' => ['Administration', 'Attendance Setup']],
            'attendance-report' => ['title' => 'Attendance Reports', 'title_ms' => 'Laporan Kehadiran', 'sub' => 'Punctuality and presence over time — drill down to any staff member.', 'sub_ms' => 'Ketepatan masa dan kehadiran dari masa ke masa — perincikan kepada mana-mana staf.', 'crumb' => ['Reports & Audit', 'Attendance Reports']],
            'leave-report' => ['title' => 'Leave Reports', 'title_ms' => 'Laporan Cuti', 'sub' => 'Leave taken by type and by person, with unplanned (emergency) leave flagged.', 'sub_ms' => 'Cuti diambil mengikut jenis dan individu, dengan cuti kecemasan (tidak dirancang) ditandakan.', 'crumb' => ['Reports & Audit', 'Leave Reports']],
            'leave-setup' => ['title' => 'Leave Setup', 'title_ms' => 'Tetapan Cuti', 'sub' => 'Set each person\'s opening leave balance — carry forward balances from your previous system.', 'sub_ms' => 'Tetapkan baki cuti permulaan setiap orang — bawa ke hadapan baki daripada sistem terdahulu anda.', 'crumb' => ['Administration', 'Leave Setup']],
            'position' => ['title' => 'Position & Manday Rates', 'title_ms' => 'Pangkat & Kadar Manday', 'sub' => 'Salary bands per position — drive manday/manhour costing on timesheets.', 'sub_ms' => 'Jadual gaji mengikut pangkat — memacu kos manday/manhour pada timesheet.', 'crumb' => ['Administration', 'Position & Manday Rates']],
            'roster' => ['title' => 'Shift Roster', 'title_ms' => 'Jadual Syif', 'sub' => 'Weekly staff scheduling across branches.', 'sub_ms' => 'Penjadualan staf mingguan merentas cawangan.', 'crumb' => ['Roster']],
            'shiftswap' => ['title' => 'Shift Swaps', 'title_ms' => 'Pertukaran Syif', 'sub' => 'Request to swap or give away a roster shift — counterpart and manager approve.', 'sub_ms' => 'Mohon tukar atau serah syif — rakan dan pengurus meluluskan.', 'crumb' => ['Shift Swaps']],
            'documents' => ['title' => 'Document Vault', 'title_ms' => 'Peti Dokumen', 'sub' => 'Contracts, certificates and IDs — stored privately per employee.', 'sub_ms' => 'Kontrak, sijil dan kad pengenalan — disimpan secara peribadi bagi setiap pekerja.', 'crumb' => ['Documents']],
            'surveys' => ['title' => 'Pulse Surveys', 'title_ms' => 'Tinjauan Pulse', 'sub' => 'Short engagement and eNPS pulses — one response per person.', 'sub_ms' => 'Tinjauan penglibatan dan eNPS ringkas — satu maklum balas setiap orang.', 'crumb' => ['Surveys']],
            'ideas' => ['title' => 'Suggestion Box', 'title_ms' => 'Peti Cadangan', 'sub' => 'Share ideas and upvote the best — HR triages each through to done.', 'sub_ms' => 'Kongsi idea dan undi yang terbaik — HR menyaring setiap satu hingga selesai.', 'crumb' => ['Suggestion Box']],
            'leave' => ['title' => 'Apply for Leave', 'title_ms' => 'Mohon Cuti', 'sub' => '12.5 days annual leave remaining · check team calendar before applying.', 'sub_ms' => '12.5 hari cuti tahunan berbaki · semak kalendar pasukan sebelum memohon.', 'crumb' => ['Leave', 'New Application']],
            'calendar' => ['title' => 'Time-off Calendar', 'title_ms' => 'Kalendar Cuti', 'sub' => 'Company-wide leave, holidays and events — who is out and when.', 'sub_ms' => 'Cuti, cuti umum dan acara seluruh syarikat — siapa tiada dan bila.', 'crumb' => ['Time-off Calendar']],
            'overtime' => ['title' => 'Overtime Requests', 'title_ms' => 'Permohonan Overtime', 'sub' => 'Log overtime hours and track approvals.', 'sub_ms' => 'Rekod jam overtime dan jejak kelulusan.', 'crumb' => ['Overtime']],
            'resignation' => ['title' => 'Resignation & Exit', 'title_ms' => 'Perletakan Jawatan & Exit', 'sub' => 'Submit a resignation, track notice and exit interviews.', 'sub_ms' => 'Hantar perletakan jawatan, jejak notis dan temu duga exit.', 'crumb' => ['Resignation']],
            'compliance' => ['title' => 'Compliance & Licenses', 'title_ms' => 'Pematuhan & Lesen', 'sub' => 'Licenses, certifications and permits with expiry alerts.', 'sub_ms' => 'Lesen, pensijilan dan permit dengan amaran tamat tempoh.', 'crumb' => ['Compliance']],
            'payroll' => ['title' => 'Payroll & Compensation', 'title_ms' => 'Payroll & Pampasan', 'sub' => 'Salary structures, statutory deductions and monthly payslips.', 'sub_ms' => 'Struktur gaji, potongan berkanun dan slip gaji bulanan.', 'crumb' => ['Payroll']],
            'kpi' => ['title' => 'KPI & Performance', 'title_ms' => 'KPI & Prestasi', 'sub' => '2026 H1 cycle · mid-year review window open until 15 July.', 'sub_ms' => 'Kitaran H1 2026 · tetingkap semakan pertengahan tahun dibuka sehingga 15 Julai.', 'crumb' => ['Performance', 'KPI']],
            'achievements' => ['title' => 'Achievements & Recognition', 'title_ms' => 'Pencapaian & Pengiktirafan', 'sub' => 'Kudos, awards and milestones across the team.', 'sub_ms' => 'Pujian, anugerah dan pencapaian merentas pasukan.', 'crumb' => ['Performance', 'Achievements']],
            'reviews' => ['title' => 'Performance Reviews', 'title_ms' => 'Semakan Prestasi', 'sub' => 'Review cycles, scorecards and self-assessments.', 'sub_ms' => 'Kitaran semakan, kad skor dan penilaian kendiri.', 'crumb' => ['Performance', 'Reviews']],
            'onboarding' => ['title' => 'Onboarding Checklist', 'title_ms' => 'Senarai Semak Onboarding', 'sub' => 'Farah Aziz · Marketing Executive · Day 12 of 90', 'sub_ms' => 'Farah Aziz · Eksekutif Pemasaran · Hari 12 daripada 90', 'crumb' => ['Onboarding', 'Farah Aziz']],
            'knowledge-bank' => ['title' => 'Knowledge Bank', 'title_ms' => 'Bank Pengetahuan', 'sub' => "Share one lesson learned every month — and search the company's collective know-how.", 'sub_ms' => 'Kongsi satu pengajaran setiap bulan — dan cari himpunan pengetahuan syarikat.', 'crumb' => ['Knowledge Bank']],
            'messages' => ['title' => 'Messages', 'title_ms' => 'Mesej', 'sub' => 'Direct one-to-one messages with your colleagues.', 'sub_ms' => 'Mesej terus satu-dengan-satu bersama rakan sekerja.', 'crumb' => ['Messages']],
            'claims' => ['title' => 'Claims & Requests', 'title_ms' => 'Tuntutan & Permohonan', 'sub' => 'Submit expense, mileage and medical claims for approval.', 'sub_ms' => 'Hantar tuntutan perbelanjaan, perbatuan dan perubatan untuk kelulusan.', 'crumb' => ['Claims & Requests']],
            'expenses' => ['title' => 'Expense Reports', 'title_ms' => 'Laporan Perbelanjaan', 'sub' => 'Itemised expense reports with receipts — submit a batch for approval.', 'sub_ms' => 'Laporan perbelanjaan terperinci dengan resit — hantar sekumpulan untuk kelulusan.', 'crumb' => ['Expense Reports']],
            'probation' => ['title' => 'Probation Tracking', 'title_ms' => 'Penjejakan Percubaan', 'sub' => 'New-hire probation periods, check-ins and confirmation decisions.', 'sub_ms' => 'Tempoh percubaan pekerja baharu, semakan dan keputusan pengesahan jawatan.', 'crumb' => ['Probation']],
            'helpdesk' => ['title' => 'Helpdesk', 'title_ms' => 'Helpdesk', 'sub' => 'Raise and track IT, facilities and HR support tickets.', 'sub_ms' => 'Bangkitkan dan jejak tiket sokongan IT, fasiliti dan HR.', 'crumb' => ['Helpdesk']],
            'events' => ['title' => 'Company Events', 'title_ms' => 'Acara Syarikat', 'sub' => 'Town halls, training, holidays and socials — RSVP once per event.', 'sub_ms' => 'Town hall, latihan, cuti umum dan acara sosial — RSVP sekali setiap acara.', 'crumb' => ['Events']],
            'offboarding' => ['title' => 'Offboarding', 'title_ms' => 'Offboarding', 'sub' => 'Exit clearance and final sign-offs for departing staff.', 'sub_ms' => 'Penyelesaian exit dan pengesahan akhir bagi staf yang berhenti.', 'crumb' => ['Offboarding']],
            'goals' => ['title' => 'Goals & OKRs', 'title_ms' => 'Matlamat & OKR', 'sub' => 'Set objectives and track key-result progress.', 'sub_ms' => 'Tetapkan objektif dan jejak kemajuan key result.', 'crumb' => ['Performance', 'Goals & OKRs']],
            'recruitment' => ['title' => 'Recruitment', 'title_ms' => 'Pengambilan', 'sub' => 'Open job requisitions and track candidates through the hiring pipeline.', 'sub_ms' => 'Permohonan jawatan terbuka dan jejak calon melalui saluran pengambilan.', 'crumb' => ['Recruitment']],
            'cases' => ['title' => 'Cases', 'title_ms' => 'Kes', 'sub' => 'Confidential disciplinary and grievance case management.', 'sub_ms' => 'Pengurusan kes tatatertib dan rungutan secara sulit.', 'crumb' => ['Cases']],
            'loans' => ['title' => 'Loans & Advances', 'title_ms' => 'Pinjaman & Pendahuluan', 'sub' => 'Request a loan or salary advance and track approvals.', 'sub_ms' => 'Mohon pinjaman atau pendahuluan gaji dan jejak kelulusan.', 'crumb' => ['Loans & Advances']],
            'pettycash' => ['title' => 'Petty Cash', 'title_ms' => 'Wang Runcit', 'sub' => 'Branch cash floats, disbursements and replenishments.', 'sub_ms' => 'Peruntukan tunai cawangan, pengeluaran dan penambahan semula.', 'crumb' => ['Petty Cash']],
            'benefits' => ['title' => 'Benefits & Insurance', 'title_ms' => 'Faedah & Insurans', 'sub' => 'Review available plans and manage your enrollment.', 'sub_ms' => 'Semak pelan yang tersedia dan urus pendaftaran anda.', 'crumb' => ['Benefits']],
            'wellness' => ['title' => 'Wellness & EAP', 'title_ms' => 'Kesihatan & EAP', 'sub' => 'Confidential wellbeing check-ins and support resources.', 'sub_ms' => 'Daftar masuk kesejahteraan sulit dan sumber sokongan.', 'crumb' => ['Wellness']],
            'travel' => ['title' => 'Travel & Business Trips', 'title_ms' => 'Perjalanan & Lawatan Kerja', 'sub' => 'Request business trips and track approvals.', 'sub_ms' => 'Mohon lawatan kerja dan jejak kelulusan.', 'crumb' => ['Travel']],
            'rooms' => ['title' => 'Meeting Rooms', 'title_ms' => 'Bilik Mesyuarat', 'sub' => 'Book a room — overlapping confirmed bookings are blocked automatically.', 'sub_ms' => 'Tempah bilik — tempahan disahkan yang bertindih disekat secara automatik.', 'crumb' => ['Room Booking']],
            'vehicles' => ['title' => 'Vehicle Booking', 'title_ms' => 'Tempahan Kenderaan', 'sub' => 'Book a company vehicle — overlapping confirmed bookings are blocked automatically.', 'sub_ms' => 'Tempah kenderaan syarikat — tempahan disahkan yang bertindih disekat secara automatik.', 'crumb' => ['Vehicle Booking']],
            'assets' => ['title' => 'Asset Register', 'title_ms' => 'Daftar Aset', 'sub' => 'Company assets and who they are assigned to.', 'sub_ms' => 'Aset syarikat dan kepada siapa ia diberikan.', 'crumb' => ['Assets']],
            'shared-resources' => ['title' => 'Shared Resources', 'title_ms' => 'Sumber Bersama', 'sub' => 'Company accounts and tools everyone shares — links and logins in one place.', 'sub_ms' => 'Akaun dan alat syarikat yang dikongsi semua — pautan dan log masuk dalam satu tempat.', 'crumb' => ['Shared Resources']],
            'training' => ['title' => 'Training & Certifications', 'title_ms' => 'Latihan & Pensijilan', 'sub' => 'Assigned courses, mandatory training and completion status.', 'sub_ms' => 'Kursus ditugaskan, latihan wajib dan status penyiapan.', 'crumb' => ['Training']],
            'orgchart' => ['title' => 'Organisation Chart', 'title_ms' => 'Carta Organisasi', 'sub' => 'Reporting lines across the company.', 'sub_ms' => 'Garis pelaporan merentas syarikat.', 'crumb' => ['People', 'Organisation Chart']],
            'profile-test' => ['title' => 'My Profile Test', 'title_ms' => 'Ujian Profil Saya', 'sub' => 'A short working-style check — no right or wrong answers. Your result shows on your profile.', 'sub_ms' => 'Semakan gaya kerja ringkas — tiada jawapan betul atau salah. Keputusan anda dipaparkan pada profil anda.', 'crumb' => ['People', 'My Profile Test']],
            'profile-test-admin' => ['title' => 'Profile Test Editor', 'title_ms' => 'Editor Ujian Profil', 'sub' => 'Manage the working-style and colour questions everyone answers.', 'sub_ms' => 'Urus soalan gaya kerja dan colour yang dijawab oleh semua orang.', 'crumb' => ['Administration', 'Profile Test Editor']],
            'timesheet-setup' => ['title' => 'Timesheet Setup', 'title_ms' => 'Tetapan Lembaran Masa', 'sub' => 'Categories, projects and sub-pillars staff pick when allocating their week.', 'sub_ms' => 'Kategori, projek dan sub-tiang yang dipilih staf semasa memperuntukkan minggu mereka.', 'crumb' => ['Administration', 'Timesheet Setup']],
            'timesheet-reports' => ['title' => 'Timesheet Reports', 'title_ms' => 'Laporan Lembaran Masa', 'sub' => 'Staff time allocation by project and by person over a period.', 'sub_ms' => 'Peruntukan masa staf mengikut projek dan mengikut individu untuk satu tempoh.', 'crumb' => ['Reports & Audit', 'Timesheet Reports']],
            'reports' => ['title' => 'Reports', 'title_ms' => 'Laporan', 'sub' => 'Workforce, capacity and leave summaries.', 'sub_ms' => 'Ringkasan tenaga kerja, kapasiti dan cuti.', 'crumb' => ['Reports']],
            'handbook' => ['title' => 'Employee Handbook', 'title_ms' => 'Buku Panduan Pekerja', 'sub' => 'Company policies, SOPs and required acknowledgements.', 'sub_ms' => 'Polisi syarikat, SOP dan pengakuan yang diperlukan.', 'crumb' => ['Handbook']],
            'setup' => ['title' => 'Setup Wizard', 'title_ms' => 'Bestari Persediaan', 'sub' => 'Get your company workspace ready, step by step.', 'sub_ms' => 'Sediakan ruang kerja syarikat anda, langkah demi langkah.', 'crumb' => ['Administration', 'Setup Wizard']],
            'settings' => ['title' => 'Company Settings', 'title_ms' => 'Tetapan Syarikat', 'sub' => 'Workspace profile, branches and departments.', 'sub_ms' => 'Profil ruang kerja, cawangan dan jabatan.', 'crumb' => ['Administration', 'Company Settings']],
            'roles' => ['title' => 'Roles & Permissions', 'title_ms' => 'Peranan & Kebenaran', 'sub' => 'Assign access roles to workspace members.', 'sub_ms' => 'Tetapkan peranan akses kepada ahli ruang kerja.', 'crumb' => ['Administration', 'Roles & Permissions']],
            'feedback' => ['title' => 'Feedback Inbox', 'title_ms' => 'Peti Maklum Balas', 'sub' => 'Bug reports and ideas staff sent through the sidebar — triage each through to done.', 'sub_ms' => 'Laporan pepijat dan idea yang dihantar staf melalui bar sisi — saring setiap satu hingga selesai.', 'crumb' => ['Reports & Audit', 'Feedback Inbox']],
            'audit' => ['title' => 'Audit Logs', 'title_ms' => 'Log Audit', 'sub' => 'Recent administrative and approval activity.', 'sub_ms' => 'Aktiviti pentadbiran dan kelulusan terkini.', 'crumb' => ['Reports & Audit', 'Audit Logs']],
            'security' => ['title' => 'Account Security', 'title_ms' => 'Keselamatan Akaun', 'sub' => 'Two-factor authentication and sign-in protection.', 'sub_ms' => 'Pengesahan dua faktor dan perlindungan log masuk.', 'crumb' => ['Account', 'Security']],
            'updates' => ['title' => "What's New", 'title_ms' => 'Apa Baharu', 'sub' => 'Release notes and product updates.', 'sub_ms' => 'Nota keluaran dan kemas kini produk.', 'crumb' => ["What's New"]],
            'soon' => ['title' => 'Module', 'title_ms' => 'Modul', 'sub' => 'This module is part of the AmanahKu platform.', 'sub_ms' => 'Modul ini sebahagian daripada platform AmanahKu.', 'crumb' => ['Module']],
        ];

        return $pages[$screen] ?? $pages['soon'];
    }

    /**
     * Bahasa Melayu translations for breadcrumb segments. Keys are the exact
     * English crumb strings used in page() and the profile/dash overrides.
     * Segments not present here (tenant names, employee names) fall through
     * to the English original, which is correct — proper nouns are not translated.
     */
    public static function crumbMap(): array
    {
        return [
            'Dashboard' => 'Papan Pemuka',
            'People' => 'Warga Kerja',
            'Employees' => 'Pekerja',
            'Employee Profile' => 'Profil Pekerja',
            'My Profile Test' => 'Ujian Profil Saya',
            'Profile Test Editor' => 'Editor Ujian Profil',
            'Attendance Reports' => 'Laporan Kehadiran',
            'Timesheet Setup' => 'Tetapan Lembaran Masa',
            'Timesheet Reports' => 'Laporan Lembaran Masa',
            'Organisation Chart' => 'Carta Organisasi',
            'My Work' => 'Kerja Saya',
            'Board' => 'Papan Kerja',
            'Timesheets' => 'Lembaran Masa',
            'Learning Library' => 'Pustaka Pembelajaran',
            'Performance' => 'Prestasi',
            'Skills Matrix' => 'Matriks Kemahiran',
            'Referrals' => 'Rujukan',
            'AI Workforce Intelligence' => 'Risikan Tenaga Kerja AI',
            'Attendance' => 'Kehadiran',
            'Position & Manday Rates' => 'Pangkat & Kadar Manday',
            'Roster' => 'Jadual Syif',
            'Shift Swaps' => 'Pertukaran Syif',
            'Documents' => 'Dokumen',
            'Surveys' => 'Tinjauan',
            'Suggestion Box' => 'Peti Cadangan',
            'Leave' => 'Cuti',
            'New Application' => 'Permohonan Baharu',
            'Time-off Calendar' => 'Kalendar Cuti',
            'Overtime' => 'Kerja Lebih Masa',
            'Resignation' => 'Perletakan Jawatan',
            'Compliance' => 'Pematuhan',
            'Payroll' => 'Gaji',
            'KPI' => 'KPI',
            'Achievements' => 'Pencapaian',
            'Reviews' => 'Semakan',
            'Onboarding' => 'Onboarding',
            'Knowledge Bank' => 'Bank Pengetahuan',
            'Messages' => 'Mesej',
            'Claims & Requests' => 'Tuntutan & Permohonan',
            'Expense Reports' => 'Laporan Perbelanjaan',
            'Probation' => 'Percubaan',
            'Helpdesk' => 'Helpdesk',
            'Events' => 'Acara',
            'Offboarding' => 'Offboarding',
            'Goals & OKRs' => 'Matlamat & OKR',
            'Recruitment' => 'Pengambilan',
            'Cases' => 'Kes',
            'Loans & Advances' => 'Pinjaman & Pendahuluan',
            'Petty Cash' => 'Wang Runcit',
            'Benefits' => 'Faedah',
            'Wellness' => 'Kesihatan',
            'Travel' => 'Perjalanan',
            'Room Booking' => 'Tempahan Bilik',
            'Vehicle Booking' => 'Tempahan Kenderaan',
            'Assets' => 'Aset',
            'Shared Resources' => 'Sumber Bersama',
            'Training' => 'Latihan',
            'Reports' => 'Laporan',
            'Handbook' => 'Buku Panduan',
            'Administration' => 'Pentadbiran',
            'Company Settings' => 'Tetapan Syarikat',
            'Roles & Permissions' => 'Peranan & Kebenaran',
            'Reports & Audit' => 'Laporan & Audit',
            'Feedback Inbox' => 'Peti Maklum Balas',
            'Audit Logs' => 'Log Audit',
            'Account' => 'Akaun',
            'Security' => 'Keselamatan',
            "What's New" => 'Apa Baharu',
            'Module' => 'Modul',
        ];
    }

    /** Translate a single breadcrumb segment to BM, falling back to the original. */
    public static function crumbMs(string $segment): string
    {
        return self::crumbMap()[$segment] ?? $segment;
    }

    /**
     * Render user-entered text safe for the page with bare URLs turned into
     * clickable links. HTML is escaped FIRST (XSS-safe), then http(s) URLs are
     * wrapped in anchors and newlines become <br>. Output is meant for {!! !!}.
     */
    public static function linkify(string $text): string
    {
        $escaped = e($text);

        $linked = preg_replace_callback(
            '~\bhttps?://[^\s<]+~i',
            function (array $m): string {
                // Don't swallow trailing sentence punctuation into the href.
                $url = rtrim($m[0], '.,;:!?)]}\'"');
                $tail = substr($m[0], strlen($url));

                return '<a href="'.$url.'" target="_blank" rel="noopener noreferrer nofollow" '
                    .'style="color:var(--info);text-decoration:underline;word-break:break-all;">'.$url.'</a>'.$tail;
            },
            $escaped
        );

        return nl2br($linked, false);
    }

    /**
     * Dashboard title/subtitle override per persona.
     *
     * The management & hr subtitles carry LIVE workforce figures supplied via $stats
     * (see AppController::dashStats) — never the old hardcoded "186" seed. employee &
     * manager subtitles remain canned demo copy this phase.
     */
    public static function dashHeading(string $persona, array $stats = [], ?Employee $employee = null): array
    {
        $headcount = (int) ($stats['headcount'] ?? 0);
        $onProbation = (int) ($stats['on_probation'] ?? 0);
        $confirmationsDue = (int) ($stats['confirmations_due'] ?? 0);
        $company = $stats['company'] ?? 'Unijaya Resources';

        $employees = $headcount.' '.Str::plural('employee', $headcount);
        $confirmations = $confirmationsDue.' '.Str::plural('confirmation', $confirmationsDue);

        // Manager subtitle is driven by the viewer's real reporting line (see
        // BuildsDashboardData::managerHeadingStats), replacing the old fixed "8 direct reports" copy.
        $directReports = (int) ($stats['direct_reports'] ?? 0);
        $onLeave = (int) ($stats['on_leave'] ?? 0);
        $outstanding = (int) ($stats['timesheets_outstanding'] ?? 0);
        $reportsLabel = $directReports.' '.Str::plural('direct report', $directReports);
        $timesheetsLabel = $outstanding.' '.Str::plural('timesheet', $outstanding);

        return [
            'employee' => self::employeeHeading($employee),
            'manager' => ['title' => 'Team Overview — People & Culture', 'title_ms' => 'Gambaran Pasukan — People & Culture', 'sub' => "{$reportsLabel} · {$onLeave} on leave · {$timesheetsLabel} outstanding.", 'sub_ms' => "{$directReports} laporan terus · {$onLeave} bercuti · {$outstanding} lembaran masa belum dihantar."],
            'management' => ['title' => 'Workforce Intelligence', 'title_ms' => 'Workforce Intelligence', 'sub' => "{$company} · {$employees} · live capacity & risk view.", 'sub_ms' => "{$company} · {$headcount} pekerja · paparan kapasiti & risiko secara langsung."],
            'hr' => ['title' => 'HR Operations', 'title_ms' => 'Operasi HR', 'sub' => "{$headcount} headcount · {$onProbation} on probation · {$confirmations} due this month.", 'sub_ms' => "{$headcount} jumlah pekerja · {$onProbation} dalam percubaan · {$confirmationsDue} pengesahan jawatan perlu diputuskan bulan ini."],
        ][$persona] ?? ['title' => 'Dashboard', 'sub' => ''];
    }

    /**
     * Personal dashboard heading for the employee persona — a time-of-day greeting,
     * the viewer's own first name, and today's date. The server clock runs on
     * Asia/Kuala_Lumpur (config/app.php), so one timezone drives the greeting for
     * every staff member. Both language strings are returned; the blade x-text
     * ternary picks EN or BM at render, matching the rest of the app.
     */
    private static function employeeHeading(?Employee $employee): array
    {
        $hour = (int) now()->hour;

        // English keeps the familiar three-way split; Malay is more granular
        // (pagi / tengah hari / petang / malam) so it reads naturally to local staff.
        // Pre-dawn (0–4) reads as night, not morning — night-shift staff clock in then.
        [$greetEn, $greetMs] = match (true) {
            $hour < 5 => ['Good evening', 'Selamat malam'],
            $hour < 12 => ['Good morning', 'Selamat pagi'],
            $hour < 14 => ['Good afternoon', 'Selamat tengah hari'],
            $hour < 19 => ['Good afternoon', 'Selamat petang'],
            default => ['Good evening', 'Selamat malam'],
        };

        // First name only — the first whitespace-delimited token works for both
        // "Aisyah Rahman" → "Aisyah" and "Nabil Syafiq Bin Azlan" → "Nabil".
        $name = trim((string) ($employee->name ?? ''));
        $firstName = $name === '' ? '' : (string) Str::of($name)->squish()->explode(' ')->first();

        $titleEn = $firstName === '' ? $greetEn : "{$greetEn}, {$firstName}";
        $titleMs = $firstName === '' ? $greetMs : "{$greetMs}, {$firstName}";

        return [
            'title' => $titleEn,
            'title_ms' => $titleMs,
            'sub' => self::todayLabel('en')." · here's what needs your attention today.",
            'sub_ms' => self::todayLabel('ms').' · ini perkara yang perlu perhatian anda hari ini.',
        ];
    }

    /** Today's date: "Tuesday, 7 July 2026" (en) / "Selasa, 7 Julai 2026" (ms). */
    private static function todayLabel(string $lang): string
    {
        $now = now();
        if ($lang === 'en') {
            return $now->format('l, j F Y');
        }
        // Carbon ships no bundled BM locale here, so map by hand (mirrors
        // KnowledgeController::monthLabels and timesheet-capture.js day names).
        $days = ['Ahad', 'Isnin', 'Selasa', 'Rabu', 'Khamis', 'Jumaat', 'Sabtu'];
        $months = [1 => 'Januari', 'Februari', 'Mac', 'April', 'Mei', 'Jun', 'Julai', 'Ogos', 'September', 'Oktober', 'November', 'Disember'];

        return $days[$now->dayOfWeek].', '.$now->day.' '.$months[(int) $now->month].' '.$now->year;
    }

    /** Management operational risk feed (canned this phase). */
    public static function operationalRisks(): array
    {
        return [
            ['t' => 'Operations at 96% capacity for 3rd consecutive week', 'sev' => 'High', 'sevc' => 'red'],
            ['t' => '12 timesheets missing across 3 departments', 'sev' => 'Medium', 'sevc' => 'amber'],
            ['t' => 'Logistics: 5 assignments overdue, 2 critical', 'sev' => 'High', 'sevc' => 'red'],
            ['t' => '3 certifications expiring within 30 days', 'sev' => 'Low', 'sevc' => 'amber'],
        ];
    }

    /** AI assistant seed conversation for the current screen/persona. */
    public static function aiMessages(string $persona, string $screen): array
    {
        if ($persona === 'manager' || $screen === 'workload') {
            return [
                ['isAi' => true, 'text' => 'Your team is at 84% capacity this week. Faizal Othman is overloaded (red): 47 assigned hours against a 40h capacity, plus 3 adhoc items.'],
                ['isAi' => true, 'text' => 'I recommend moving the "Q3 vendor audit" assignment from Faizal to Nurul Iman, who is at 61% utilisation. Shall I draft the reassignment for your approval?', 'source' => 'Workload model · live data'],
            ];
        }

        return [
            ['isAi' => true, 'text' => 'Good morning Aisyah. You have 2 assignments due this week and your timesheet is not yet submitted. Want me to draft it from your recorded tasks?'],
        ];
    }

    public static function aiPrompts(): array
    {
        return [
            'Who on my team is overloaded this week?',
            'Summarise overdue assignments',
            'Draft my timesheet from my tasks',
        ];
    }
}
