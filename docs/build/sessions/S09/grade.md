# QA grade: S09 CR-06a project master schema and versioning

**Result: PASS.** Zero FAIL after three fixes. Graded on the dev DB with Playwright on the
worktree vhost as Kussairi (manager, PM field set), Hidayah (HR, finance field set),
Shazwan (staff) and Shahril (director, both sets plus reopen). Every create, edit, close and
reopen was clicked through the Projects register; locked fields were also pushed through
`POST /app/projects/{id}` with JSON to prove the server refuses what the screen greys out.
The Track-facing list was read with a real `projects:read` bearer token. DB and audit rows
read with `mysql`.

## Dev database wiped and restored before this grade

The CR06aTest writer ran `migrate:fresh` with an inline sqlite override through the lerd
`php` wrapper. The wrapper ignores inline env, so the command emptied the real dev MySQL
(2026-09-07 17:32 UTC). Restored on 2026-09-08 from the 28 Aug prod dump per CLAUDE.md
(drop, load, null NRIC, `lerd artisan migrate`, passwords to `password`, 2FA cleared), then
`BuildFixturesSeeder` re-run. Three things the dump does not hold were recreated by hand so
the every-session checks could run: Shazwan's NRIC (the profile gate held him on the
welcome wizard), the S00 baseline flower (Hidayah to Ain Akilah) and Shazwan's saved card
order. Run-time data from the S01 to S08 grades (CR-18 schedules, CR-03 timesheet days,
port_outbox probes, leave request 27) is gone; their grade files keep the evidence. Logged
in OPEN under "QA / run / dev database restored after S09".

## Findings fixed during the grade

**F1 (fixed, 36507ba0).** The row appended after an AJAX add rendered its edit form
without the employee list, so the PM and PE selects held only "— none —" and the first
save on a freshly created project wiped both people (version 2 on project 36 recorded
"PM: Kussairi → —"). `storeProject` now passes the same picker list as the register;
`test_the_appended_row_offers_the_pm_and_pe_pickers` covers it. Re-driven: added project
37 with PM and PE, edited the appended row at once, both kept.

**F2 (fixed, 36507ba0).** `GET /api/v1/projects` serialised `contract_start` and
`contract_end` as UTC timestamps (`2026-09-30T16:00:00Z` for 1 Oct), which a consumer
reading the date part would take as the previous day. Now plain `Y-m-d`, asserted in
`ProjectMasterTest`.

**F3 (fixed, b0b1c524).** A closed project's edit form still showed live inputs and a Save
button; only the server refused the save with a 422. Every field is now disabled with
"This project is closed. A director must reopen it before anything here can change." and
no Save button, covered by `test_a_closed_project_renders_its_edit_form_locked`.

A Keep it plain nit fixed in the same commit as F1: version history read "Pm Id: 5 → —";
it now names the field and the person ("PM: Kussairi → —").

## Acceptance items (docs/specs/CR-06.md, as CR06aTest numbers them)

| # | Item | Result |
|---|------|--------|
| 1 | Full-detail create, appears in Track's list | **PASS** Kussairi filled every Details, Contract and People field (`grade-cr06a-1-form-filled.png`), row appended with code, client, RM 1,250,000.50, PM/PE (`grade-cr06a-1-row-added.png`); project 36 stored with all 19 master fields, version 1 dated 2026-09-08 by user 6, audit rows 908 to 910; `GET /api/v1/projects` with a `projects:read` token lists it with project_code, client, status, contract value, dates as `2026-10-01`/`2027-09-30` after F2, procurement, contractor, drive link, pm, pe, version 1 |
| 2 | Track pre-fill | human check after the run (CR-06c deferred), incomplete in CR06aTest |
| 3 | Variation and period reports | S10 (CR-06b), incomplete in CR06aTest |
| 4 | Track minimal save | human check after the run, incomplete in CR06aTest |
| 5 | Legacy rows as version 1 dated creation | **PASS** after `lerd artisan migrate` on the restored dump all 35 legacy projects carry exactly one version, `version_no` 1, `effective_date` = `DATE(created_at)` (0 mismatches); History on a legacy row shows "v1 — <created> — System"; API `version` 1 |
| 6 | PM cannot edit contract value; Finance cannot edit PM | **PASS** Kussairi's form locks project code, client, contract value/dates and every finance field, leaves procurement, contractor, drive link, status, PM, PE open (`grade-cr06a-6-kussairi-edit.png`); JSON contract_value 403 "Contract value can only be changed by finance or a director.", bond_value 403, client 403, project_code 422 "Project code is locked once the project is created."; Hidayah's form opens only bond, LOA and agreement fields, locks PM, PE, status, contractor (`grade-cr06a-6-hidayah-edit.png`), screen edit of bond value and LOA ref saved as version 3 with reason; JSON pm_id 403 "PM can only be changed by a project manager or a director.", contract_value 422 "Contract value changes through a Variation, not in place."; Shazwan sees the register with no Edit, Add or form at all (`grade-cr06a-6-shazwan-readonly.png`), JSON edit 403; Shahril's form opens both sets, still locks code and variation fields |
| 7 | Closed project read-only until a director reopens with reason | **PASS** Kussairi set status Closed with reason (version 4, audit 923); form fully disabled with the closed note for Kussairi and Hidayah (`grade-cr06a-7-kussairi-closed.png`, `grade-cr06a-7-hidayah-closed.png`), JSON edits 422 "This project is closed. A director must reopen it first.", reopen 403 for manager, HR and staff, no Reopen form shown to them; Shahril sees the Reopen form (`grade-cr06a-7-shahril-reopen-form.png`), empty reason 422 "The reason field is required.", "Extension signed with KPT" reopens to Active as version 5, audit 928 by user 25, form editable again (`grade-cr06a-7-shahril-reopened.png`) |

## Every-session checks

| Check | Result |
|-------|--------|
| `php artisan test --compact tests/Acceptance/CR06aTest.php` | PASS, 8 tests, 102 assertions, 3 incomplete (items 2, 3, 4 by design) |
| Due date change via API (`PATCH /app/board/186 {due_at}` as Kussairi, own card) | PASS, 422 "Due dates are locked after the first save" |
| Audit-log row edit and delete via the model | PASS, both throw "audit_logs rows are append-only" |
| Dashboard as Shazwan on quiet 2026-09-09 | PASS, cards summary, clock, tasks, leave, style; calendar, notices, flowers, claims, work; same set and order as baseline (`grade-cr06a-dashboard.png`). Header reads "A few cards are past due" because the re-seeded fixture cards have past due dates; layout unaffected |
| Keep it plain | PASS for this CR: greeting "Good morning, Shazwan.", nothing new from CR-06a animates or jokes, Projects text plain (`grade-cr06a-plain.png`). Pre-existing shell animations (page fade-in, summary tile entrance, Knowledge badge pulse) still run under plain; unchanged since S00, logged in OPEN for S21 CR-31 which owns the toggle |
| Diff grep for outbound calls (Http::, guzzle, googleapis, brevo, smtp, Mail::, Notification::, curl, Google\) on 9672f082..b0b1c524 | PASS, no hits |
| OPEN entries name alternatives and reversal cost | PASS, "S09 / CR-06a / sanctum guard collision on /api/v1/projects" |
| Protected files untouched | PASS, `git diff --stat 9672f082..HEAD` touches none of CLAUDE.md, RULES.md, contracts, tests/Acceptance |
| Full suite | PASS, 2750 tests, 2745 passed, 5 skipped, 12 incomplete |

## Notes for the next session

- Dev DB is the 28 Aug dump plus `BuildFixturesSeeder`, plus projects 36 ("KPT: RMS (QA
  CR-06a)", active again, versions 1 to 5, PM Kussairi, PE Shazwan) and 37 ("QA F1
  appended-row edit", versions 1 to 3). A `projects:read` token named `qa-cr06a` exists for
  Kussairi. Dev clock reset, plain pref off.
- Sanctum `'guard' => []` (S09 OPEN entry) is what lets a bearer token work while a web
  session exists in the same process; S10's variation endpoints inherit it.
- Never run `migrate:fresh` or any env-overridden artisan through the lerd `php` wrapper.
  Inline env does not reach the container; the command hits the dev MySQL.
