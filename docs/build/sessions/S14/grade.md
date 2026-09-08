# QA grade: S14 / CR-21 (Office Requests / Permintaan Pejabat)

**Verdict: PASS**, after six fixes (F1–F6) made in this grade commit. The S14 build passed
`CR21Test`, and unlike S13 a human could drive it end to end; the fixes below are the gaps
between "the test passes" and "the real tenant's data and a real user's eyes". Every item was
re-driven after fixing.

Graded on `http://worktree-change-request-tracker.amanahku.localhost` with the quick-login
accounts, dev clock forwards only (see the S13 grade). Cast: Shazwan plays Emysha (requester),
Kussairi plays Adri (second requester / upvoter), Hidayah is the admin-team member (HR role and
in the Administration department), Hakime (employee 3, Position "Finance Manager") is MN,
Shahril and Suandy are the directors.

## Fixes made during the grade

| # | Found by | Defect | Fix |
|---|----------|--------|-----|
| F1 | Item 1 | The helper roster looked for a department named exactly `Admin`; the real tenant calls it **Administration**, so the card was created with zero helpers. | `OfficeRequestController::adminDepartmentEmployees()` matches `Admin%` (first by id). Feature test `helpers_come_from_a_department_whose_name_starts_with_admin`. |
| F2 | Items 1, 2, 5 | Categories rendered as `ucfirst` of the slug: "It", "Pantry", "Facilities" on the select, the card stamp and Insights. The spec names them "Facilities repair / Vehicle / Pantry & supplies / IT & equipment / Cleaning / Other". | `OfficeRequest::CATEGORY_LABELS` (EN + BM) and `categoryLabel()`, used by the select, the stamp and the Insights table; BM strings follow `$store.ui.lang`. |
| F3 | Item 3, 4 | Every `app_notifications` row had `url = NULL`, so the "done" and "urgent" notices were dead ends. | Both `AppNotification::send` calls pass `url('/app/office-requests')` (the board is the `/app/{screen}` catch-all, it has no named route). |
| F4 | Item 3 | After the three-day window the Reopen button still showed and clicking it silently reloaded the page (the 422 was swallowed). | The button renders only when `withinReopenWindow()`; every board action goes through `settle(r)`, which reloads on success and shows the server message on a refusal. |
| F5 | Item 5 | Average days to close rendered as `0.0011`. | JSON rounds to two decimals, the page formats one decimal. |
| F6 | Item 4 | The "Urgent" stamp stayed English under BM. | `Segera` via `$store.ui.lang`. |

Feature test `the_screens_use_the_spec_category_names_link_notifications_and_hide_reopen_outside_the_window`
pins F2–F5. `public/build` was byte-identical after `view:clear && view:cache && bun run build`
(the screens use inline styles and existing classes), so nothing to commit there.

## Acceptance items

| # | Item | Result | Evidence |
|---|------|--------|----------|
| 1 | Emysha raises 'Coffee habis' with photo → board + card on MN's T.A.A. with admin staff tagged | PASS (after F1) | `grade-cr21-1-form.png`, `grade-cr21-1-board.png` (Open column, pantry wishlist "1 votes"); `office_requests` row 1 with `photo_path`, `GET /app/office-requests/1/photo` 200 `image/png`; `work_items` 250 owned by employee 3 (Hakime, Finance Manager), labels `["office"]`, due 2026-09-13; helpers = employees 1, 2, 22, 23 (all four Administration staff); Hidayah's board shows the card as "Tagged – Helper" with the "Office Request" label (`grade-cr21-1-helper-board.png`); audit row `office_request.created`. |
| 2 | Adri tries the same, sees the existing request, +1s, votes = 2 | PASS | Typing "coffee habis" in Kussairi's form shows "Already raised — +1 instead? Coffee habis (1) [+1]" (`grade-cr21-2-similar.png`); clicking +1 reloads with "↑ 2" and wishlist "2 votes" (`grade-cr21-2-votes.png`); `office_request_votes` has two rows, no second request. |
| 3 | Admin note 'Ordered, Thu' + Done → Emysha and Adri notified; Emysha can reopen within 3 days | PASS (after F3, F4) | Hidayah: Note prompt → "Note: Ordered, Thu" on the card (`grade-cr21-3-note.png`); Done prompt "Restocked two tins." → card moves to Done with the closing note (`grade-cr21-3-done.png`); `app_notifications` rows for users 27 (Shazwan) and 6 (Kussairi) "Office request done: Coffee habis"; card 250 status `done`. Shazwan sees Reopen (`grade-cr21-3-reopen-btn.png`), click → back to Open, note kept, same card 250 back to `todo`. Hidayah closes again; clock 12 Sep (4 days later): server 422 "This request can no longer be reopened." (`grade-cr21-3-window.png`); after F4 the button is gone and a direct call shows that message. Audit rows for status, admin_note, closing_note, done_at. |
| 4 | Urgent 'Aircond leaking Level 3' → MN and Director notified immediately | PASS (after F6) | Choosing Urgent reveals the reason box; submitting without one is stopped by the browser (`valueMissing`); with a reason (`grade-cr21-4-form.png`) the card lands in Open with the Urgent stamp (`grade-cr21-4-board.png`); `app_notifications` rows for user 4 (Hakime/MN), 25 (Shahril) and 26 (Suandy, director) written in the same request, nobody else (Haryati, manager, not notified); card 251 priority `high`, owner employee 3. Repeated for F3 with 'Projector lamp dead': three rows, each linking to the board. |
| 5 | Insights shows September: 12 requests, avg 1.8 days to close | PASS (after F2, F5) | The 12 / 1.8 figures are `CR21Test::test_acceptance_5` (fixture of twelve requests closed 1.0 or 2.6 days later; JSON `requests` 12, `avg_days_to_close` 1.8, `by_category` two each, `top_voted` "September request 4" with 3 votes). Browser as Kussairi: the Insights link shows only for PM and above (absent for Shazwan; `GET .../insights` 403 for him), the page shows Requests 2, Avg days to close (0.0 after F5), By category, Top voted "Coffee habis 2" (`grade-cr21-5-insights.png`); `?month=2026-08` returns 0 / 0 / empty. |

## Every-session checks

| Check | Result |
|-------|--------|
| `php artisan test --compact tests/Acceptance/CR21Test.php` | 6 passed, 137 assertions (plus `tests/Feature/OfficeRequestTest.php` 7 passed). Full suite after the fixes: 2833 tests, 2828 passed, 0 failed, 5 skipped, 12 incomplete. |
| Task due date via API | As Kussairi `PATCH /app/board/205 {due_at: 2026-12-01}` → 422 "Due dates are locked after the first save…". |
| Edit an audit-log row | `AuditLog::first()->update(['action' => 'tampered'])` → "audit_logs rows are append-only". |
| Dashboard as plain staff on a quiet day | Shazwan, clock 2026-09-15 10:00: left summary, clock, tasks, leave, style; right calendar, notices, flowers, claims, work; no band, no new widget. The moments slot showed the pre-existing holiday-eve card (Malaysia Day on the 16th), not CR-21's doing (`grade-cr21-dashboard.png`). |
| Keep it plain | Toggled on for Shazwan, Office Requests screen: no cheeky text, no animation; the only transitions are the shared `.uj-btn-primary` / `.uj-lv-in` 0.14–0.16s hover transitions from the base stylesheet, same as every other screen (`grade-cr21-plain.png`). Toggled back off. |
| Outbound calls in the diff | None (`Http::`, guzzle, googleapis, `Mail::` absent from `app/`, `routes/`, `resources/`). Notifications are `app_notifications` rows only. |
| OPEN entries | The S14 entry names alternatives and a reversal cost; the grade entry below does too. |
| Protected files | `CLAUDE.md`, `docs/build/RULES.md`, `docs/build/contracts/*`, `tests/Acceptance/*` untouched by S14 and by this grade (`git diff ab1b9674..HEAD --stat` on those paths is empty). |

## Notes for the next session
- Dev DB end state: `office_requests` 1 (Coffee habis, done, note + closing note), 2 (Aircond leaking Level 3, urgent, open), 3 (Projector lamp dead, urgent, open); cards 250 (done), 251, 252 (todo, high) owned by employee 3 with four Administration helpers; `app_notifications` 1566–1575. Dev clock reset to real; Keep it plain off for Shazwan.
- The board's Note and Done use `window.prompt`, which Playwright's MCP intercepts as a modal; `page.once('dialog', …)` inside the same snippet handles it, but the snippet aborts afterwards. Drive one dialog per snippet.
- Insights has no month picker in the UI; the `?month=` query is the only way to look at another month. Not required by the spec's acceptance line, left as is.
- The helper roster is by department name prefix `Admin` now; a tenant with both "Admin" and "Administration" departments would pick the lower id.
