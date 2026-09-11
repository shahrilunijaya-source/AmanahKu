# S21 grade: CR-31 easter eggs

**Verdict: PASS.** One code defect in the session's work (F1, fixed by QA with a feature
test) and one pre-existing settings bug found on the way (F2, fixed). Every acceptance item
was driven in the browser on the dev copy with the dev clock; every every-session check is
green.

Graded on commits `f8ad8f1b`, `b430bac6` on top of `8767c4c5` (CR31Test), with the QA
fixes `110b4f3a` (F1) and the settings commit after it (F2).

## F1 (fixed): late-night shortcut landed on a 404

`resources/views/screens/dash.blade.php` hard-coded the shortcut to `/app/overtime`.
Tenant 1 has `module.overtime` off, so clicking it gave Not Found. Now
`BuildsDashboardData::dashboardEgg()` asks `FeatureManager::screenAllowed()` and points the
link at `/app/timesheets` ("Log your hours on the timesheet?") when the module is off.
Two tests in `tests/Feature/EasterEggTest.php` cover both targets. Re-clicked in the
browser: lands on Timesheets.

## F2 (fixed, pre-existing): settings page threw two Alpine SyntaxErrors

`resources/views/screens/settings.blade.php` line 63 (Form EA commit `ba07bcac`) wrote
`\"Employer's TIN (LHDN)\"` inside a double-quoted `x-text`. HTML does not unescape that,
so Alpine failed to compile the expression on every settings load. Replaced with
`&#39;`. Not S21's, fixed because it was in the way of a clean console on the egg card.

## Acceptance items

| # | Item | Result | Evidence |
|---|------|--------|----------|
| 1 | Friday after 5, once a day | PASS | Thu 17:05 and Fri 16:55: no egg. Fri 2026-09-11 17:05: `data-egg="friday_late"`, bank line, BM toggle switches the text, × hides it, reload shows nothing, `easter_egg_views` row written. Fri 18 Sep: again (`grade-cr31-1-friday.png`). |
| 2 | Inbox zero on the last overdue card | PASS | Shazwan had seven overdue cards. Six closed by the move endpoint: `egg: null` every time. Seventh (238) closed from the board drawer's Done button: toast "Nothing overdue. Enjoy this rare moment.", no confetti (`grade-cr31-2-inbox-zero.png`). Reopened and closed again: `egg: null` (once a day). All seven restored. |
| 3 | Keep it plain | PASS | Profile switch on, dash at 22:30: `<body data-plain>`, no `.uj-egg`, no view row burned, computed `animation-name: none` on `.uj-fade`, `.uj-dw-tile`, `.kb-pulse-ring`, plain "Good evening, Shazwan." (`grade-cr31-3-plain.png`). Switch off: egg back. |
| 4 | Late night | PASS | 2026-09-10 22:30: `data-egg="late_night"`, no audio element, shortcut present; once a day. Shortcut 404 was F1, now lands on Timesheets (`grade-cr31-4-late-night.png`). |
| 5 | HR bank | PASS | As Nur Hidayah on Company Settings: added a friday_late line, edited it, deleted it (confirm dialog), each change visible after redirect (`grade-cr31-5-settings.png`). As Shazwan: `POST /app/admin/eggs` 403, `/app/settings` 403. |
| 6 | Tab collector | HUMAN | `markTestIncomplete` in CR31Test. Not driven: the heartbeat lives in localStorage across real tabs. Shazwan: open three Amanahku tabs and wait ~10s. |
| bonus | Holiday eve | PASS | Dev clock 2026-09-15 (Malaysia Day eve): `data-egg="holiday_eve"` "Almost there. Tomorrow is off." / "Dah dekat. Esok cuti.", second load none (`grade-cr31-holiday-eve.png`). |

Note for the grader after me: the `/dev/clock` redirect is itself a dashboard load and
burns the once-a-day egg. Delete the `easter_egg_views` row before the load you screenshot.

## Every-session checks

| Check | Result |
|-------|--------|
| `php artisan test --compact tests/Acceptance/CR31Test.php` | 10 passed, 1 incomplete (tab collector, by design) |
| Full suite | 2953 tests, 2947 passed, 1 failed: the known pre-existing `LeaveScreenTabsTest::test_cancelling_an_approved_replacement_refunds_the_quota`, untouched by S21 |
| Due date PATCH (`/app/board/229`, Shazwan) | 422 "Due dates are locked after the first save" |
| Audit row edit (tinker `update()`) | rejected: "audit_logs rows are append-only" |
| Dashboard baseline, Shazwan, 2026-09-10 09:00 | Current month summary, Daily clock log, Pending tasks, My leave summary, My working style, My calendar, Notice board, Flowers, My claim summary, My work summary; no bands, no egg (`grade-cr31-dashboard.png`) |
| Keep it plain | see item 3 |
| Outbound grep on the diff | no SDK, no HTTP client, no mail |
| OPEN entries | both S21 entries carry Alternatives and Reversal cost |
| Protected files | `CLAUDE.md`, `docs/build/RULES.md`, `docs/build/contracts/*`, `tests/Acceptance/*` untouched by S21 |

Dev copy left clean: `easter_egg_views` emptied, clock real, Keep it plain off, the seven
fixture cards back to their statuses with `done_at` null. Reopening a Done card keeps its
`done_at` (S06 behaviour, `WorkItemController` line 448), not this CR's concern.
