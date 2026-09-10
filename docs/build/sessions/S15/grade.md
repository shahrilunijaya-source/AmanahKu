# QA grade: S15 / CR-17 (Management view on the dashboard: lateness and overdue tasks)

**Verdict: PASS**, after two fixes (F1, F2) made in this grade. The S15 build passed
`CR17Test`, the exceptions page, nudge, reassign and the digest all worked as the test
describes, but one acceptance item could not be driven by a human at all (no incident-window
form existed) and the Reassign button showed for people the server then refused. Every item
was re-driven after fixing.

Graded on `http://worktree-change-request-tracker.amanahku.localhost` with the quick-login
accounts. Cast: Shahril is the Director, Hidayah is HR, Haryati plays Yati (her dev
`tenant_user.data_scope` was set to `branch` for the grade and put back to `department`
afterwards), Kussairi is Emysha's line manager (team scope), Shazwan plays Emysha, Adri
(employee 28) is Adri. Today's clock-ins, one confirmed flexi shift, one approved leave and
two overdue cards were seeded straight into the dev DB (the geofenced clock-in flow needs a
selfie and a location); the attendance, shift, leave and incident rows were removed after the
grade, the two cards stay (see the notes).

## Fixes made during the grade

| # | Found by | Defect | Fix |
|---|----------|--------|-----|
| F1 | Item 9 | `POST /app/attendance/incidents` existed and was tested, but no screen posted to it: HR had no way to "mark a system incident window" in the app. | An "Incident from / Until / Note" form on Attendance Setup, inside the Lateness card, HR only (`$role === 'hr'`, the screen itself is management + HR). Plain form posts redirect back with the toast "Incident window marked: 8 Sep 09:30 to 8 Sep 10:00."; JSON callers keep the `{ok, id}` body. Feature test `hr_marks_an_incident_window_from_the_attendance_setup_form`. |
| F2 | Items 2, 8 | Every overdue card rendered a Reassign button for every viewer of the panels, including HR (90 buttons, each answered 403) and Yati on cards outside her direct reports. | The reassign rule moved into `ManagementExceptions::canReassign()` (one rule for the POST gate and the button); `withReassignFlags()` stamps `can_reassign` per card for the viewer and the partial only renders the button when it is true. Feature test `the_reassign_button_only_renders_for_a_viewer_who_may_reassign`. |

No new CSS classes were added (inline styles and the existing `uj-btn-primary`, `uj-mgmt-*`
classes), so `public/build` is the S15 build unchanged.

## Acceptance items

| # | Item | Result | Evidence |
|---|------|--------|----------|
| 1 | Director's dashboard opens with Lateness and Overdue panels at the top, company-wide | PASS | `grade-cr17-1-director.png`: one `data-band="management"` above `.uj-dw-grid`, 32 `data-late-row`s (every active employee not on leave / WFH), 14 owner groups, 90 cards. |
| 2 | Yati (Sr PM) sees the same panels scoped to her staff | PASS | Her dashboard has no band (contract: the band is FINAL_APPROVAL_ROLES only, see the CR17Test OPEN entry); `/app/management/exceptions` shows only her reporting line: 22 rows, no Hidayah / Ain Akilah / Hakime / Yati / Shahril rows, 10 owner groups all under Kussairi's chain (`grade-cr17-2-yati-exceptions.png`). Kussairi (team scope) and Shazwan get 403 on that URL. |
| 3 | Emysha sees no panels | PASS | Shazwan's dashboard: 0 bands, 0 panels; `/app/management/exceptions` 403, nudge 403, reassign 403 (`grade-cr17-3-staff.png`). |
| 4 | Prototype P2 SMK (+7 days) under its assignee with 7 days overdue; nudge notifies the assignee | PASS | Card 253 (owner Shazwan, due 2026-09-01) shows "7 days overdue" under Shazwan's group; the Director's Nudge click writes `app_notifications` 1576 for user 27 "Shahrilnizam nudged you about: Prototype P2 SMK (QA CR-17)" linking to the board card, and audit row 1048 `work_item.nudged`; a second click the same day alerts "This card was already nudged today." |
| 5 | 13:14 clock-in against a 9:00 shift shows Late 4h14m; the same on an approved flexi 13:00 shift shows On time | PASS | Shazwan (13:14, expected 09:00) row reads "Late 4h14m"; Nurin (13:00 clock-in with a `confirmed` 13:00 shift) reads "On time"; Kussairi 09:40 reads "Late 0h40m". |
| 6 | Approved leave, WFH or client-site staff not in today's late list | PASS | Nurhidayah Abdul Halim (approved leave today) and Anasuha (`wfh` record) have no row; everyone without a record reads "Not clocked in". |
| 7 | Card owned by Adri with Emysha as helper appears under Adri only | PASS | Card 254 (owner Adri, helper Shazwan) renders once, inside `data-overdue-owner="28"`, on the Director's band and Yati's page; not under Shazwan. |
| 8 | Emysha's line manager reassigns her overdue card, reason required, audit entry, September overdue still recorded against Emysha | PASS (after F2) | Kussairi (line manager, team scope, so no panel) reassigns card 253 through the route: no reason 422 "The reason field is required."; with reason 200. Result: owner 26 → 6, due date still 2026-09-01, audit row 1049 `work_item.employee_id` 26 → 6 with the reason, `overdue_ledger` row (card 253, employee 26, month 2026-09-01, 7 days), notifications to both owners (1577, 1578). The Director's prompt-driven Reassign on card 254 (Adri → Irfan) did the same through the UI (audit 1051, ledger row for employee 28, notifications 1583, 1584). After F2 HR sees 90 Nudge and 0 Reassign buttons; Yati sees Reassign only on Kussairi's 16 cards (her direct report) and none on Nurin's (`grade-cr17-8-yati-reassign-scope.png`). |
| 9 | HR marks a system incident window; clock records in that window show Unverified | PASS (after F1) | Hidayah, Attendance Setup, "Incident from 09:30 / Until 10:00 / Note" → toast (`grade-cr17-9-hr-form.png`); the band then reads Kussairi (09:40) "Unverified" while Shazwan (13:14) stays "Late 4h14m". The Director's and a plain manager's Attendance Setup show no such form. |
| D | Deferred digest | PASS | `php artisan management:digest` on the dev DB: `port_outbox` row 5 (port `mail`, method `send`, kind `management_digest`, to = HR + the three directors' emails only, body "3 people are late today. 90 cards are overdue." with the BM twin) and four `app_notifications` "Daily management digest" rows; no mail left the machine. Scheduled `dailyAt('08:00')` in `bootstrap/app.php`. |

## Every-session checks

| Check | Result |
|-------|--------|
| `php artisan test --compact tests/Acceptance/CR17Test.php` | 10 passed (1 incomplete: the human check the test itself names). With `ManagementExceptionsTest`, `CR32Test`, `DashboardBandsTest`: 35 passed, 334 assertions. Full suite before the fixes: 2851 tests, 0 failed, 5 skipped, 12 incomplete; after the fixes: see the commit message. |
| Task due date via API | As Kussairi `PATCH /app/board/202 {due_at: 2026-12-31}` → 422 "Due dates are locked after the first save…". |
| Edit an audit-log row | `AuditLog::orderBy('id','desc')->first()->update(['action' => 'tampered'])` → RuntimeException "audit_logs rows are append-only". |
| Dashboard as plain staff on a quiet day | Shazwan, clock 2026-09-09 09:00: no band, no panel, the same ten widgets as `docs/build/baseline/dashboard.png` (summary, clock, tasks, leave, style; calendar, notices, flowers, claims, work) (`grade-cr17-dashboard-staff.png`). Clock reset to real. |
| Keep it plain | Toggled on for Shahril with the band visible: greeting drops to "Good afternoon, Shahrilnizam.", the band text is figures only, no keyframe animation anywhere in it; the only transitions are the shared 0.14s button hover transitions every screen has (`grade-cr17-plain-director.png`). Toggled back off. |
| Outbound calls in the diff | None (`Http::`, guzzle, googleapis, `Mail::`, curl absent from `app/`, `routes/`, `bootstrap/`, `resources/`, `database/`). The digest goes through `MailPort` into `port_outbox`. |
| OPEN entries | The two S15 entries and the grade entry below name alternatives and a reversal cost. |
| Protected files | `CLAUDE.md`, `docs/build/RULES.md`, `docs/build/contracts/*`, `tests/Acceptance/*` untouched by S15 and by this grade. |

## Notes for the next session
- Dev DB end state: cards 253 "Prototype P2 SMK (QA CR-17)" (owner Nurin, due 2026-09-01) and 254 "Site survey report (QA CR-17 helper)" (owner Irfan, helper Shazwan, due 2026-09-05) remain, with `overdue_ledger` rows 1 and 2; `port_outbox` row 5 and `app_notifications` 1576 to 1584 remain. Today's seeded attendance, shift, leave and incident rows were deleted; Haryati is back to `department` scope; dev clock real; Keep it plain off.
- A team-scope line manager (Kussairi) has no panel and so no Reassign button; the route works for them (item 8) but the only UI is the Director's or a branch manager's panel. A board-card action for the direct manager is the natural next step; logged in the grade OPEN entry.
- The S15 tree was left uncommitted by the session; this grade committed it together with F1 and F2 (the fixes touch the same files), then the grade artefacts separately.
- Two `window.prompt`s in one click (Reassign): register `page.on('dialog')` with both answers before the click; the MCP still reports the first prompt as a modal and aborts the snippet, but the handler stays attached and the second prompt is answered, so the POST lands.
