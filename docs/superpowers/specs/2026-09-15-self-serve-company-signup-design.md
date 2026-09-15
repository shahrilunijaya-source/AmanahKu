# Self-serve company signup and per-company work week

Date: 2026-09-15. Status: approved in chat, awaiting mockup sign-off.

## Goal

The directors own other businesses. The person in charge (PIC) of each one must be able to set up their own AmanahKu company without a developer: create the company, pick the modules they need, set their work week, and run it. Nothing about Unijaya's own company changes.

## What already exists (reused, not rebuilt)

- Superadmin creates a company by hand: tenant + first branch + department + HR admin, plus seeds (feature package by stage, payroll items, timesheet categories, greeting and easter-egg banks). `SuperAdmin\CompanyController::store`.
- HR toggles modules in Company Settings (`AdminController::updateFeatures`), bounded by superadmin platform locks.
- Launch Center (`SetupController`) walks HR through profile, branches, departments, positions, staff, attendance policy, leave types, holidays, payroll, then launch. The launch lock holds plain staff out until the critical steps are done.
- `platform.registration` feature already gates `/register`. Fortify already rate-limits register at 5 per minute per IP.
- Superadmin stays global: sees and does everything across all companies. No new roles.

## Change 1: invite-link signup

### Superadmin side

- Superadmin Companies page gets a "Generate signup link" action.
- New table `company_invites`: `id`, `token` (40 random chars, unique), `note` (nullable, who it is for), `company_category_id` (stage package the new company starts on, default Stage 3), `expires_at` (7 days), `used_at` (nullable), `used_by_tenant_id` (nullable), `created_by_user_id`, timestamps.
- Page lists invites: note, category, created, expires, status (pending / used by X / expired), copy-link button, revoke (deletes the row if unused).

### Signup side

- URL: `/register?invite=<token>`. Fortify's register view is replaced by a signup page that needs a valid token. No token, unknown, used or expired: 404. Nothing on the public site links to it.
- Form: company name, your name, email, password, password confirmation. Company name is the only company field; everything else is filled later in Company Settings. If the email already belongs to an account, see edge cases: sign in, then the link attaches that user.
- On submit, inside one transaction:
  - Tenant: slug from name (unique), initials, brand colour default, `plan` default, `company_category_id` from the invite, `status` active, `onboarding_enforced` true, `work_days` default `[1,2,3,4,5]`, `tot_saturday` false.
  - Same seeds as superadmin create: feature package by category level, payroll items, timesheet categories, greeting bank, easter-egg bank.
  - Branch "HQ", department "General".
  - User with the given password (no forced rotation, they chose it), attached with role `hr`, plus an active Employee row, position "HR Admin".
  - Invite marked used with the tenant id.
  - Audit log entry "Company self-registered".
- Then log in, set `current_tenant` in session, redirect to Launch Center.
- The provisioning steps move out of `SuperAdmin\CompanyController::store` into one `App\Services\CompanyProvisioner` used by both paths, so the two never drift.

### Rate limits

- Register POST keeps Fortify's 5 per minute per IP.
- GET with a token is throttled 20 per minute per IP so tokens cannot be brute-forced by scanning. Tokens are 40 random characters, so scanning is not realistic anyway.

### Master switch

- `platform.registration` must be on for the signup page to answer. Superadmin flips it off to close the door entirely, even for pending links.

## Change 2: per-company work week

### Storage

- `tenants.work_days`: JSON array of ISO weekday numbers (1 = Monday, 7 = Sunday). Default `[1,2,3,4,5]`.
- `tenants.tot_saturday`: boolean, default false, **no UI**. TOT (first Saturday of the month as a half day) is a Unijaya-only rule. The migration sets it true for the Unijaya tenant and nothing else ever sets it. Kept as a column rather than a slug check so the rule is data, not a special case in code.
- Validation: at least one working day.

### Helper

- `App\Support\WorkWeek` reads the current tenant once per request:
  - `isWorkingDay(Carbon $date): bool` (in `work_days`, or TOT Saturday; public holidays are still handled by the callers that already do so).
  - `capacity(Carbon $date): int` (0, 50 or 100).
  - `isTotDay(Carbon $date): bool`.
- Every current hardcode routes through it:
  - Central: `Timesheet\DayRules`, `Timesheet\DayCapacity`, `Timesheet\LockedDays`, `Models\LeaveRequest::countDays`, `Attendance\ReportPeriod`, `Support\Awards::workingDaysBetween`, `Timesheet\BoardSuggestions`.
  - Inline `isWeekend` copies: `DashboardWidgets`, `BirthdayWishController`, `BirthdayNotify`, `BuildsDashboardWidgets`, `Attendance\HolidayEve`, `Attendance\ReminderTargets`.
- Week boundaries (Monday start, Friday 19:00 timesheet deadline, management meeting day) stay as they are. They are calendar conventions, not work-day rules, and are out of scope.

### UI

- Company Settings gets a "Work week" panel: seven day toggles Mon to Sun. HR only. Late grace minutes stays where it is, on Attendance setup.
- Launch Center gets a step "Set work week" under Company basics, manual tick, deep-links to that panel.

## Change 3: live setup guide for the first HR

Not documentation. A guide that sits on screen, says what to do next, takes them to the right screen and points at the exact button. It advances by itself as the data appears.

### Where it comes from

- Launch Center (`SetupController::compute()`) already has the ordered step list with done flags. The guide is that list, surfaced one step at a time. No second source of truth: the guide's "current step" is the first step not yet done, in Launch Center order.
- Shows only while `CompanySetupProgress.completed_at` is null, only to `hr` and management-tier members. Never for plain staff, never for Unijaya (setup already completed there; the migration stamps `completed_at` for any tenant that already has staff, so existing companies never see it).

### Pieces

1. **Guide dock.** Small floating card, bottom right of every app screen, above the phone dock. Shows "Setting up · step 3 of 12", the step title, one line of where-to-click copy ("Company Settings, then Add branch"), and two buttons: **Take me there** (deep-link to the step's screen, same link Launch Center uses) and **Skip for now** (marks manual steps done, or just moves to the next for auto steps, remembered per step in localStorage). Collapse to a small pill; reopens from the pill. Hidden entirely once setup is finished.
2. **Sidebar highlight.** The nav item for the current step's screen gets a soft pulsing ring so the eye finds it without reading.
3. **On-screen pointer.** On the step's target screen, the existing `partials.coachmark` bubble points at the main action (Add branch, Add department, Add employee, Load standard leave types, Save modules, and so on). One new option on the partial, `$when`, a server-side boolean so the bubble shows because this is the current step, not because of localStorage. Closing it does not dismiss forever; it comes back if the step is still current on the next visit.
4. **Step done feedback.** When the guide detects the step just completed (page load after the save), the dock shows a short "Done, next: …" line before moving on. No confetti.

### Copy

Each step in `stepDefs()` gains `guide` and `guide_ms`: one sentence, imperative, names the screen and the button. Bilingual like everything else. Example for branches: "Go to Company Settings and click Add branch. Give it a name and address; the map pin can wait."

### Where it does not go

- No overlay that blocks the page. The bubble and dock float; the app stays usable.
- No new state table. Progress is Launch Center's, dismissals are localStorage.
- No guide for staff-side screens. This is the first HR's setup path only.

## Edge cases and decisions

Signup
- Email already has an account: no duplicate. Signup page detects it and asks them to sign in; once signed in, the same link attaches the existing user as `hr` of the new company (name and password fields skipped). Workspace picker then shows both companies.
- Company name collision: slug gets a numeric suffix. Names are not unique.
- Same link submitted twice or by two people: the invite row is locked `FOR UPDATE` inside the transaction; the second submit gets "This link has already been used".
- Validation failure on submit: token travels in a hidden field so the form re-renders with it.
- `platform.registration` off: existing blocked response. Pending invites are not deleted.
- Revoke only on pending rows. Used rows link to the company instead.
- A superadmin opening a link is refused with "You already see every company". Superadmins create companies from the Companies page.
- Signup done, tab closed: next login lands in the workspace picker, guide resumes at the first undone step.

Work week
- At least one working day, enforced on save.
- Changing work days is forward-only. Stored leave day counts, submitted timesheet weeks and past attendance reports are not recalculated. The panel says so.
- Saturday ticked as a full work day overrides the hidden TOT half-day (only Unijaya has the flag, the helper handles the precedence).
- Public holidays keep working as today: callers check the holiday after the work-day check, so a holiday on a non-work day changes nothing.
- A week with zero working days (all holidays, or a Sat/Sun-only company on a holiday week): timesheet capacity is 0 and the submit gate treats it as nothing to fill, not blocked. Covered by a test.
- Tenant factory defaults `work_days` to Mon to Fri and `tot_saturday` false; Unijaya-shaped tests set the flag on explicitly.

Guide
- "Skip for now" only advances the dock. It never marks a critical auto step done, the launch lock still holds staff out, and the Finish step lists skipped steps.
- Steps done another way (CSV import instead of one-by-one) tick because detection is data-based.
- Turning a module off drops its step; the dock count shrinks with it.
- Two HR users: progress is shared (data), collapse and dismissals are per browser.
- Phone: dock sits above the phone nav and collapses to a pill; the bubble is the existing phone-safe coachmark.
- Migration stamps `completed_at` on every tenant that already has active staff, so Unijaya and any live company never see the dock. Companies created by superadmin from the Companies page do get it.
- Superadmin browsing a new company sees the dock too. Intended.
- Re-running the walkthrough after Finish is out of scope; Launch Center remains the checklist.

## Out of scope

- No setup wizard (Launch Center is the wizard; the guide walks it).
- No new roles, no per-company admin flag. Superadmin unchanged.
- No email verification on signup (invite link is the verification).
- No change to week start, timesheet deadlines or meeting day.
- No billing or subscription self-service. Superadmin still sets subscription dates.

## Tests

- Signup: existing email is told to sign in, and a signed-in user with the link gets attached as `hr` without a new user row. Superadmin with a link is refused. Same token twice fails on the second.
- Signup: valid token creates tenant, branch, department, HR user, employee, seeds, marks invite used, logs in, lands on Launch Center. Missing, unknown, used and expired tokens all 404. `platform.registration` off gives the existing blocked response. Second submit with the same token fails.
- Superadmin: generate, list, revoke invites. Non-superadmin gets 403.
- Provisioner: superadmin create still produces the same rows as before (existing tests stay green).
- WorkWeek unit tests: Mon to Fri default, custom six-day week, TOT Saturday on and off, capacity values.
- Guide: current step is the first undone step; dock hidden for staff, hidden after finish, hidden for tenants stamped completed by the migration; "Take me there" links match Launch Center; coachmark `$when` renders only on the current step.
- Existing TOT and timesheet tests keep passing with Unijaya's `tot_saturday = true`. One new feature test proves a tenant with `tot_saturday = false` treats the first Saturday as a non-working day in leave counting and timesheet capacity.

## Mockups (before code)

Three screens, static HTML in `docs/superpowers/mockups/2026-09-15-self-serve-signup/`, symlinked to `~/mockups/self-serve-signup`:

1. Superadmin invites list with generate action.
2. Signup page.
3. Company Settings work week panel.
4. Live setup guide: dock, sidebar highlight and on-screen pointer on the Branches step.
