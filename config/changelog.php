<?php

/*
|--------------------------------------------------------------------------
| What's New — product changelog
|--------------------------------------------------------------------------
|
| SINGLE SOURCE OF TRUTH for the "What's New" tab in the Feedback hub.
| To publish an update: prepend a new entry to the TOP of this array.
|
| Everything else is automatic:
|   - The Feedback hub shows the newest entry first.
|   - A "New" badge appears on the sidebar Feedback pill for every user who
|     has not yet seen the latest `version`, and clears once they open the
|     What's New tab (tracked client-side per device).
|
| Each entry:
|   version  unique id for the release; bumping it re-triggers the "New" badge
|   date     human display date
|   title    short headline
|   notes    grouped lines under new | improved | fixed (any group may be omitted)
|
*/

return [

    'releases' => [

        [
            'version' => '2026.07.08',
            'date' => 'July 8, 2026',
            'title' => 'Finer module controls, resignation clearance & tidier disabled modules',
            'notes' => [
                'new' => [
                    'Overtime can now be switched on or off on its own in Company Settings, separately from Leave & Time-off.',
                    'Acknowledging a resignation now automatically opens a clearance checklist for the leaver, so nothing is missed before they go.',
                    'Departed staff move to a clear "archived" state, with an outstanding-items flag and an HR clearance link.',
                ],
                'improved' => [
                    'Turning a module off now hides it everywhere, not just from the menu — for example, switching off Performance also removes the KPI cards and the KPI History tab from profiles and the dashboard.',
                ],
            ],
        ],

        [
            'version' => '2026.06.27',
            'date' => 'June 27, 2026',
            'title' => 'Company onboarding: categories, branded login & setup wizard',
            'notes' => [
                'new' => [
                    'Company categories (Stage 1/2/3) — the category sets each company\'s default feature package.',
                    'Company-branded login pages at /login/{company} with your logo, colours and welcome message.',
                    'Setup wizard that walks new company admins through getting their workspace ready, with progress on the dashboard.',
                    'Staff levels and employment types you can manage and assign to staff.',
                    'Expanded company profile: registration number, company code, industry, address, contact, website, subscription dates and more.',
                    'Data access scope per member (own / team / department / branch / company) — narrow who each manager can see, set on Roles & Permissions.',
                    'Per-member permission overrides (grant / deny) on top of their role, on Roles & Permissions.',
                    'Bulk staff import from CSV, with a downloadable template.',
                    'Account activation links — invited staff set their own password from a secure email link.',
                    'Richer branch and position details (code, type, address, contact, default role, managerial flag).',
                ],
                'improved' => [
                    'Super admins can now suspend or reactivate a company and re-apply its category package.',
                    'Company branding (logo, colours, welcome message, contact) is now editable from Company Settings.',
                ],
            ],
        ],

        [
            'version' => '2026.06.24',
            'date' => 'June 24, 2026',
            'title' => 'Feedback hub, refreshed sign-in & account menu',
            'notes' => [
                'new' => [
                    'Send feedback from anywhere — report a bug or suggest an idea from the sidebar.',
                    'This "What\'s New" panel, so you always see the latest changes.',
                    'Account menu in the top-right: profile, security, switch workspace, sign out.',
                ],
                'improved' => [
                    'Redesigned sign-in page with one-click demo logins.',
                ],
            ],
        ],

    ],

];
