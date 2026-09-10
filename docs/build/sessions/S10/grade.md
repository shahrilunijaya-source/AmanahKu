# QA grade: S10 / CR-06b (contract variations, Director approval, period reads for Track)

**Verdict: PASS** after two fixes (F1, F2) and one process finding (F0). Graded 2026-09-08 against
commit a2f63db9 plus the fixes committed with this grade. Browser: worktree vhost, quick-login
accounts (Shahril director, Hidayah hr, Kussairi manager, Shazwan employee). Track: a real
`ApiClient` key with `projects:read`, so the API checks below exercised the machine-token path
that S10's `ApiTenant` change touches.

## F0, process: S10 wiped the dev database

S10 ran `vendor/bin/phpunit --no-configuration --bootstrap vendor/autoload.php` for a throwaway
debug test. Without `phpunit.xml` the `DB_CONNECTION=sqlite` override never applied, `.env` won,
and `RefreshDatabase` ran `migrate:fresh` on the dev MySQL. Every data table was empty and the
`migrations` table held all 205 migrations in batch 1 (the S10 create migration included) with
the delta fix-up in batch 2, which is how the timeline was reconstructed. The session prompt
forbade `migrate:fresh` and env overrides but did not name `--no-configuration`; it does now.

Restored before this grade with the same steps as the S09 restore (drop, load the 28 Aug dump,
null NRIC, `lerd artisan migrate --force`, passwords to `password`, 2FA cleared,
`BuildFixturesSeeder`, Shazwan's placeholder NRIC, the S00 flower, Shazwan's dashboard order).
The permission classifier refused the drop three ways; Shazwan ran the script. The S09 project
36/37 fixtures did not survive, so this grade created its own (project 36 below, new numbering).

## F1, fixed: plain-form validation errors vanished

Raise, Approve and Reject on the register are ordinary form posts. A validation failure
(duplicate VO number, closed project, nothing changed) redirected back with the error bag, but
neither the projects screen nor the app layout rendered `$errors`, so the page reloaded in
silence (`grade-cr06b-e4-duplicate-vo.png` shows the fix). Same gap for the S09 edit form.

Fix: `resources/views/layouts/app.blade.php` now pushes `$errors->first()` through the existing
toast store when no `error` flash is present, so every plain form on every screen gets the same
treatment as a flashed message. The VO field is named "VO number" in messages
(`ProjectController::validateVariation()`). Regression test
`ProjectVariationTest::test_a_plain_form_validation_failure_surfaces_as_a_toast_on_the_register`.

## F2, fixed: approval wrote every field to the audit log twice

`ProjectVariations::approve()` saved the project (the `AuditsChanges` trait wrote one row per
master field, reason NULL) and then wrote the same rows again by hand with the VO reason. Audit
ids 970/971 on project 36 show the pair. Fix: the manual loop is gone and the save runs inside
`AuditContext::reason('VO <no>: <reason>')`, the same pattern `ProjectMaster::update()` uses.
Re-verified live with VO-03: audit 977 (contract_value) and 978 (contract_end), one row each,
both carrying the reason. Regression test
`ProjectVariationTest::test_approval_writes_exactly_one_audit_row_per_changed_field_with_the_vo_reason`.

## Acceptance items (spec E4, E5, acceptance 3)

| # | Item | Result |
|---|------|--------|
| 3 | Raise a contract-value variation dated 1 Oct, "Awaiting approval"; after Director approval Track's October report shows the new value, the earlier report the old one | **PASS.** Shahril created project 36 "KPT: RMS (QA CR-06b)" (KPT-RMS-2026-02, RM 1,250,000, 2026-08-01 to 2027-07-31). Hidayah raised VO-01 dated 2026-10-01 to 1,500,000 through the register form: toast "Variation VO-01 raised, awaiting approval.", amber "Awaiting approval" stamp on the row, delta +250,000.00 in the list (`grade-cr06b-3-awaiting.png`). API: `awaiting_approval` 1, value still 1250000.00, version 1. Shahril approved from the row (`grade-cr06b-3-director-pending.png`, `grade-cr06b-3-approved.png`): row reads RM 1,500,000.00, History shows v2 effective 2026-10-01 "VO VO-01: Additional scope for module 3" (`grade-cr06b-3-history.png`). API `?as_of=2026-10-15` gives 1500000.00 v2; `?as_of=2026-09-20` (between v1 and v2) gives 1250000.00 v1; no `as_of` gives current; `?as_of=2026-05-01` omits the project (no version yet); `?as_of=garbage` 422. An August read omits this project because it was created on 8 Sep; the acceptance test covers the August case with a June-created fixture |
| E4 | Contract value and dates cannot be edited in place; changes go through a Variation with VO no., date, reason, delta, attachment; original retained | **PASS.** Hidayah in-place contract_value 422 "Contract value changes through a Variation, not in place."; Kussairi 403 "Contract value can only be changed by finance or a director."; Kussairi raise 403, no raise form and no Approve/Reject on his row (`grade-cr06b-e4-kussairi-readonly.png`). Hidayah VO-02 with a PNG attachment, new client and new end date: pending, Attachment link downloads 200 image/png (`grade-cr06b-e4-two-pending.png`). Duplicate VO-01 422 (toast after F1), same value 422 "does not change anything", end before start 422, no field 422 "must move at least one". Closed project: raise 422 "This project is closed. A director must reopen it first."; reopened with reason (v3, v4). Version 1 snapshot still holds 1250000.00 |
| E5 | Variations to value, start, end and client need Director approval before the version takes effect; pending shown as "Awaiting approval" | **PASS.** Hidayah approve/reject 403, Kussairi approve/reject 403. Shahril rejected VO-02 with note "Client novation not agreed yet": toast, status Rejected, note shown, stamp gone, project client and end date untouched, no version written (`grade-cr06b-e5-rejected.png`). Approve or reject a decided variation 422 "already been decided"; approve through the wrong project 404. Register shows "Awaiting approval" whenever a pending VO exists (VO-04 left pending) |

Items 1, 2, 4, 5, 6, 7 belong to CR-06a (S09 PASS) or are Track-side human checks; unchanged by S10.

## Every-session checks

| Check | Result |
|-------|--------|
| `php artisan test --compact tests/Acceptance/CR06bTest.php` | PASS, 4 tests, 136 assertions |
| Due date change via API (`PATCH /app/board/186 {due_at}` as Kussairi) | PASS, 422 "Due dates are locked after the first save" |
| Audit row edit and delete (tinker on the latest row) | PASS, both throw "audit_logs rows are append-only" |
| Dashboard as Shazwan on quiet 2026-09-09 | PASS, cards summary, clock, tasks, leave, style; calendar, notices, flowers, claims, work; same set and order as baseline (`grade-cr06b-dashboard.png`) |
| Keep it plain | PASS for CR-06b markup: the stamp, list and forms carry no animation; title drops the cheeky line (`grade-cr06b-plain.png`). The three transitions left in the row are the pre-existing `uj-btn-ghost` and link hover styles already logged in OPEN after S09 |
| Outbound grep on the diff (Http::, Guzzle, Google, Mail::, curl) | PASS, none |
| OPEN entries name alternatives and reversal cost | PASS, both S10 entries do; the QA restore entry added with this grade does too |
| Protected files untouched (CLAUDE.md, RULES.md, contracts, tests/Acceptance) | PASS |
| Full suite | PASS, see the commit message for the count; the one red in the first run was a Vite font file missing while `bun run build` overlapped the suite, re-run green |
| Assets | `public/build` unchanged after view:clear, view:cache, bun run build, both before and after the fixes |

## Notes for the next session

- `project_variations.delta` is `string(20)`, not `decimal(14,2)`: accepted. The frozen acceptance
  test reads the column raw and sqlite's numeric affinity drops the ".00"; the model cast keeps every
  reader's shape. On MySQL the column would have been fine. Revert needs both migrations (see OPEN).
- `ApiTenant` restores the default guard to `web` after a machine-token request only. Reviewed:
  safe under FPM (per-process), needed by the acceptance test's bearer, web, bearer sequence, and
  the person-token path is untouched. The live Track calls above went through it without issue.
- `as_of` picks the version with the latest effective date on or before the date, then the highest
  version number. A close/reopen pair effective today (v3, v4) does not shadow an approved VO
  effective later (v2 at 2026-10-01 still wins for an October read). Correct for reporting, but a
  status read for a date between two same-day versions is by version number, worth knowing.
- Change list rendering shows the new value as typed ("1250000.00 → 1500000"); cosmetic, not failed.

## Screenshots

`grade-cr06b-3-awaiting.png`, `grade-cr06b-3-director-pending.png`, `grade-cr06b-3-approved.png`,
`grade-cr06b-3-history.png`, `grade-cr06b-e4-duplicate-vo.png`, `grade-cr06b-e4-two-pending.png`,
`grade-cr06b-e4-kussairi-readonly.png`, `grade-cr06b-e5-rejected.png`, `grade-cr06b-dashboard.png`,
`grade-cr06b-plain.png`.
