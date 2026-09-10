# Session S15 contract: CR-17 (Management view on the dashboard: lateness and overdue tasks)

Per `docs/build/OPEN.md` → "QA / CR-17 / shapes fixed by CR17Test", read in full before this
was written.

## Files touched

- `database/migrations/2026_09_20_100000_create_management_exceptions_tables.php` — new:
  `overdue_ledger`, `attendance_incidents`.
- `app/Models/OverdueLedger.php` — new model.
- `app/Models/AttendanceIncident.php` — new model, `AuditsChanges` for the creation row.
- `app/Support/ManagementExceptions.php` — new service: `lateness()`, `overdue()`. Single
  source of truth read by both the dashboard band and the exceptions page, so the two never
  disagree.
- `app/Support/DashboardBands.php` — `managementSlot()` gains `$lateness`/`$overdue` params
  and returns shaped panel rows (was kicker/title/sub text only).
- `app/Http/Controllers/Concerns/BuildsDashboardWidgets.php` — `dashboardBands()` calls the
  new service and passes the rows to `managementSlot()`.
- `resources/views/partials/dash/bands.blade.php` — management slot renders
  `@include('partials.dash.management-panels', ...)` in place of the placeholder text.
- `resources/views/partials/dash/management-panels.blade.php` — new, shared by the band and
  the page: `data-panel="lateness"` / `data-panel="overdue"`, `data-late-row`,
  `data-overdue-owner`, `data-card`, `data-nudge-url`, collapsible + client-side filter via
  Alpine.
- `app/Http/Controllers/ManagementExceptionsController.php` — new: `show()` (the page),
  `nudge()`, `reassign()`.
- `app/Http/Controllers/AttendanceAdminController.php` — `storeIncident()` added (HR-only).
- `resources/views/screens/management-exceptions.blade.php` — new page view.
- `routes/web.php` — `GET /app/management/exceptions`, `POST
  /app/management/overdue/{card}/nudge`, `POST /app/management/overdue/{card}/reassign`,
  `POST /app/attendance/incidents`.
- `app/Console/Commands/ManagementDigest.php` — new, `management:digest`.
- `bootstrap/app.php` — schedules `management:digest` daily at 08:00.
- `resources/css/app.css` — small block for `.uj-mgmt*` panel layout.
- `docs/build/OPEN.md` — appended (no edits to existing entries).
- `tests/Feature/ManagementExceptionsTest.php` — new governance tests.

No contract file edited. No file outside this list touched.

## Schema changes

- `overdue_ledger`: `id, tenant_id, work_item_id, employee_id, month (date), days_overdue
  (unsigned int), timestamps`. Migration above.
- `attendance_incidents`: `id, tenant_id, starts_at (datetime), ends_at (datetime), note
  (text), created_by_id (nullable FK employees), timestamps`. Same migration file.

## Design decisions carried from the frozen OPEN entry (not re-litigated here)

- Band stays gated `FINAL_APPROVAL_ROLES` (dashboard-slots.md); a branch/company-scope
  `manager` gets no band and reads the same panels at `/app/management/exceptions` scoped to
  `DataScope::teamIds()`; team-scope managers and staff 403.
- Lateness: skip `wfh`/`client` type records and anyone on approved leave today; expected
  start = a `confirmed` `shifts` row for the day, else the record's `expected_start`, else
  09:00; no grace applied to the displayed figure; a clock-in inside an
  `attendance_incidents` window reads `Unverified`.
- Overdue: grouped by `work_items.employee_id` only (helpers/reviewers never count),
  includes subtasks (`withoutGlobalScope(ParentOnly::class)`), excludes events/cancelled/done,
  same predicate shape as `WorkforceInsights::overdueItems()` (not calling that method itself
  — it does not include subtasks and is out of scope to change for another screen).
- Nudge: `FINAL_APPROVAL_ROLES` or a `manager` whose `DataScope::teamIds()` contains the
  card's owner; max 1/card/tenant-day (checked via an existing `AuditLog` row for that card
  today, action `like '%nudge%'`), 422 on a second same-day nudge or a done/cancelled card.
- Reassign: the owner's direct `reports_to_id` manager, the card's `projectRef->pm_id`, or
  `Permissions::effectiveRole() === 'management'`; HR 403; reason required; due date untouched;
  `AuditLog::change($card,'employee_id',old,new,reason)` via the existing `AuditsChanges`
  auto-hook (set `AuditContext::reason()` first, same trick as `WorkItemController::reassign`);
  one `overdue_ledger` row for the OLD owner; both owners notified.
- Digest: `management:digest`, `0 8 * * *`, one `MailMessage` per tenant (`kind =
  management_digest`) through `MailPort`, recipients = tenant users whose role is in
  `FINAL_APPROVAL_ROLES`; idempotent by checking `port_outbox` for today's `management_digest`
  row for the tenant before building another.

## New decisions this session (logged to OPEN.md, not re-derived here)

- No sidebar nav entry for `/app/management/exceptions` (nav's `roles` filter can't express
  "manager, but only branch/company data-scope"; the page is reachable by URL for anyone the
  controller actually authorizes).
- `?scope=staff|company` toggle (spec C) built only on the exceptions page for
  `FINAL_APPROVAL_ROLES` (default company); the dashboard band always stays company-wide for
  them, matching CR17Test acceptance 1.
- "Totals by project" (spec B) not built as a separate table — a per-owner overdue count is
  shown; a project-level breakdown is additive later if asked for.
- Reassign-target picker is a `<select>` of active employees; the required reason is collected
  via `prompt()` per the session brief's own wording ("reassign with a reason prompt").

## Acceptance verification (docs/specs/CR-17.md, acceptance 1–9, cross-checked by CR17Test)

1. `test_acceptance_1_director_dashboard_opens_with_lateness_and_overdue_panels_company_wide`
   — director, `?scope` unset, `ManagementExceptions::lateness(null,...)`/`overdue(null,...)`.
2. `test_acceptance_2_yati_sees_the_same_panels_scoped_to_her_reporting_line` —
   `ManagementExceptionsController::show()` scope resolution (branch manager → teamIds; team
   manager/staff → 403; director/HR → company via the same controller).
3. `test_acceptance_3_emysha_sees_no_panels` — role gate on the band (unchanged from S04) and
   403 on the page/nudge route for `employee`.
4. `test_acceptance_4_...seven_days_overdue_and_a_nudge_notifies_the_assignee` —
   `ManagementExceptions::overdue()` days figure, `nudge()` 1/day + done-card 422s.
5. `test_acceptance_5_a_13_14_clock_in_is_late_4h14m_...` —
   `ManagementExceptions::lateness()` expected-start resolution (confirmed shift > record >
   09:00) and the `Late %dh%02dm` format.
6. `test_acceptance_6_staff_on_approved_leave_wfh_or_client_site_...` — leave/type exclusion
   in `lateness()`.
7. `test_acceptance_7_a_card_owned_by_adri_with_emysha_as_helper_...` — grouping strictly by
   `employee_id`, never by participant/reviewer.
8. `test_acceptance_8_emyshas_line_manager_reassigns_...` — `reassign()` gate, audit, ledger
   row, notifications, due date immutability (untouched field).
9. `test_acceptance_9_hr_marks_an_incident_window_...` — `AttendanceAdminController::
   storeIncident()` gate + validation, `lateness()`'s incident-window check.
10. (Deferred half) `test_deferred_the_8am_digest_is_a_mail_port_intent_in_the_outbox_...` —
    `ManagementDigest` command + scheduler entry.

`CR32Test` and `DashboardBandsTest` re-run unchanged (the management slot still renders for
the same roles, on the same days; only its inner content changed).
