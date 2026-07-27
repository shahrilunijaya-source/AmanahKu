# TOT Sessions — Design

**Date:** 2026-07-27
**Status:** Approved, not implemented
**Module:** `module.knowledge` (existing — no new module)

## 1. Problem

Unijaya runs a TOT (Transfer of Technology) session on the first Saturday of every
month. One person is the PIC and presents one topic. Today the roster and the
material live in a Google Sheet with five columns: Tahun, Bulan, PIC, Tajuk, Link.

The sheet has four weaknesses:

1. Nobody can react, comment, or ask a question. It is a list, not a place.
2. The presenter never learns whether the session helped anyone.
3. Slots rot. Eight of the twelve 2026 rows still have `?` as the title.
4. It sits outside Amanahku, so it is invisible next to the rest of company life.

Goal: move TOT into Amanahku so everyone can find past sessions, prepare before
the day, react and discuss, and rate the presenter privately.

## 2. Scope decisions

| # | Decision |
|---|---|
| 1 | New screen `tot` under the **existing** `module.knowledge`. No new module. |
| 2 | TOT gets its own tables. Knowledge Bank tables are not reshaped. |
| 3 | Rating: 1-5 plus optional note, pseudonymous, visible to the PIC and management only. |
| 4 | Emoji reactions from a fixed whitelist of six. Several per person, one of each. |
| 5 | Reminders: PIC at 14 days when the title is still empty, PIC at 7 days, everyone at 1 day. |
| 6 | Import the full 2024 to 2026 sheet history. |
| 7 | Marking a session `done` credits the PIC's Knowledge Bank contribution for that month. |
| 8 | Optional cross-link from a session to one Knowledge Bank entry. |

### Why TOT does not reuse the Knowledge Bank tables

`knowledge_comments`, `knowledge_stars`, and `knowledge_reads` all hang off
`knowledge_entries`. Reusing them would force every TOT session to create a
knowledge entry, which would then appear in the Knowledge Bank feed and the
unread badge, and would read as the presenter's monthly lesson.

The Knowledge Bank monthly-share ritual must stay intact and uncluttered, so TOT
carries its own comment, reaction, and participation tables. The duplication is
about 30 lines of schema. The alternative pollutes a working feature.

### What TOT does write to Knowledge Bank

One thing, deliberately: when a session is marked `done`, the PIC's
`knowledge_monthly_contributions` row for that session's year and month is
marked submitted. Presenting a TOT counts as that month's contribution.

Accepted consequence: after this change a green "contributed this month" badge
means *either* wrote a lesson *or* presented TOT. The badge stops being a pure
count of written lessons.

## 3. Data model

Four new tables plus one nullable column.

### `tot_sessions`

| Column | Type | Notes |
|---|---|---|
| `id` | id | |
| `tenant_id` | FK tenants, cascade | `BelongsToTenant` |
| `year` | unsignedSmallInteger | |
| `month` | unsignedTinyInteger | 1-12 |
| `presenter_employee_id` | FK employees, nullable, nullOnDelete | resolved presenter |
| `presenter_name` | string(120), nullable | fallback for `Team`, nicknames, unmatched imports |
| `title` | string(200), nullable | null when the sheet says `?` |
| `description` | text, nullable | |
| `status` | string(16) | `planned` \| `confirmed` \| `done` \| `skipped` \| `not_tot` |
| `held_on` | date, nullable | actual date the session ran |
| `links` | json, nullable | list of `{label, url}` |
| `entry_id` | FK knowledge_entries, nullable, nullOnDelete | optional cross-link |
| `created_by` | FK employees, nullable, nullOnDelete | |
| timestamps | | |

Unique: `(tenant_id, year, month)` — one slot per month, exactly like the sheet.
Index: `(tenant_id, year)` for the year-grid query.

**Status meanings**

- `planned` — slot exists. PIC may or may not be set. Not yet held.
- `confirmed` — PIC and title both set, session is expected to run.
- `done` — the session happened.
- `skipped` — no session that month.
- `not_tot` — a non-TOT entry kept for calendar fidelity, for example the
  `jamuan raya 4/4/2026` row. Never earns a contribution credit.

**Derived UI state (not stored):** a slot whose month is in the past and whose
status is still `planned` or `confirmed` renders an amber **Needs update** badge.
This is computed in the view, never persisted, so it cannot go stale.

### `tot_comments`

`id, tenant_id, session_id (cascade), employee_id (cascade), body text, timestamps`

Flat thread, matching `knowledge_comments`. No nesting.

### `tot_reactions`

`id, tenant_id, session_id (cascade), employee_id (cascade), emoji string(8), timestamps`

Unique: `(session_id, employee_id, emoji)`.

Whitelist, enforced in the controller and asserted in a test:

```
👍  👏  🔥  💡  🤔  ❤️
```

Six fits one row on a phone and covers agree, well done, great, learned
something, question, love it. Anything else is a 422. A repeat POST of the same
emoji by the same person toggles it off.

### `tot_participation`

| Column | Type | Notes |
|---|---|---|
| `id` | id | |
| `tenant_id` | FK tenants, cascade | |
| `session_id` | FK tot_sessions, cascade | |
| `employee_id` | FK employees, cascade | |
| `watched_at` | timestamp, nullable | set when the person marks "I watched this" |
| `score` | unsignedTinyInteger, nullable | 1-5 |
| `note` | text, nullable | |
| timestamps | | |

Unique: `(session_id, employee_id)`.

One table, not two. Attendance and rating are the same person against the same
session, and `score` stays nullable because people watch without rating.

## 4. Rating privacy

Scores and notes are **pseudonymous, not anonymous**. `employee_id` is stored so
a person rates once and can edit their own rating.

- The UI never shows who gave which score, to anybody, including management.
- Only the PIC of that session and users with the `management` or `hr` tenant
  role can see scores at all. Everyone else sees no score, no average, no count.
- Anyone with database access can still trace a rating to a person.

The screen must say this in plain words next to the rating form. Claiming full
anonymity when the row carries an employee id would be a lie to staff.

## 5. Permissions

| Actor | Can |
|---|---|
| `hr`, `management` | Create, edit, delete slots. Set PIC, title, status, links, cross-link. See every score and note. |
| PIC of a slot | Edit that slot's title, description, links, cross-link. See own average score and the notes, without names. |
| Any employee | Read every slot. Comment. React. Mark watched. Rate. |
| No employee profile | Read only. |

The privileged set matches the existing convention, for example
`LearningController::PRIVILEGED_ROLES`. **Open item (KIV):** who assigns the
yearly roster is deferred. HR and management is the working default and is one
constant to change later.

## 6. Reminders

New Artisan command `tot:remind`, scheduled daily. Follows `TimesheetReminder`
exactly: loop tenants, set `CurrentTenant` per iteration, wrap each tenant in
try/catch so one bad tenant does not silently skip the rest, clear context at the
end.

Session date is the **first Saturday of the slot's month**, computed with
`Carbon::parse("first saturday of {$year}-{$month}")`. It is not stored on the
slot until the session runs and `held_on` is set.

| Trigger | Audience | Message |
|---|---|---|
| 14 days before, title still null | PIC | "Your TOT is in two weeks and the topic is still blank." |
| 7 days before | PIC | "Your TOT is next Saturday. Upload your slides." |
| 1 day before | every active member | "TOT tomorrow: `<title>`. Material is on the TOT screen." |

Every send passes a `dedupeKey` of `tot:{session_id}:{stage}`, so cron retries
and a re-run on the same day cannot double-notify. `AppNotification::send`
already supports this.

Slots with status `skipped` or `not_tot` never fire. Slots with no
`presenter_employee_id` skip the PIC reminders and still fire the all-hands one.

## 7. Screens and wiring

One new screen id: `tot`.

1. `Features::MODULES` — `'module.knowledge' => ['Knowledge Bank', ['knowledge-bank', 'tot'], 2]`.
   The module list length does not change, so no new toggle appears in admin.
2. `Amanahku.php` — add a nav entry under **Talent & Growth**, next to Knowledge
   Bank, with `id => 'tot'`, English and Malay labels, plus the screen meta
   (title, subtitle, breadcrumb) in both languages, matching the existing shape.
3. `AppController::screenData` — `'tot' => app(TotController::class)->screenData($request, $employee)`.
4. `routes/web.php` — write routes under the `/app/tot/...` prefix so
   `EnsureModuleEnabled` gates them by first segment, and declared **above** the
   `/app/{screen?}` catch-all.

### Routes

```
POST   /app/tot                          tot.store
POST   /app/tot/{session}                tot.update
POST   /app/tot/{session}/delete         tot.destroy
POST   /app/tot/{session}/react          tot.react
POST   /app/tot/{session}/comment        tot.comment
DELETE /app/tot/comments/{comment}       tot.comments.delete
POST   /app/tot/{session}/watched        tot.watched
POST   /app/tot/{session}/rate           tot.rate
```

### Layout

**Year grid.** Year tabs across the top (2024, 2025, 2026, then future years as
they exist). Twelve rows below, one per month, always all twelve even when empty:

`Month · PIC · Title · Status pill · Link count · Reaction row · Watched count`

An empty month shows a muted row with an **Assign PIC** button for HR. A past
month still `planned` shows the amber **Needs update** badge.

**Session detail.** Opens from a row:

- Title, presenter, held date.
- Description.
- Labelled links, all visible to everyone in the workspace.
- Emoji bar with per-emoji counts, own reactions highlighted.
- **I watched this** button.
- Rating form: 1-5 plus an optional note, with the pseudonymity notice.
- Score summary, shown only to the PIC and to management or HR.
- Comment thread.
- **Related Knowledge Bank entry** line when `entry_id` is set.

Follow the existing screen conventions: `@extends('layouts.app')`,
`@include('partials.guide', ...)` with English and Malay copy, `uj-card` and
`uj-pill` classes, `$store.ui.lang` for the language switch.

## 8. History import

Seeder `TotHistorySeeder`, sourced from the exported sheet
`2025 2026 TOT Sabtu/Sheet1.html`. Idempotent via `updateOrCreate` on
`(tenant_id, year, month)`, so a re-run does not duplicate.

**Presenter matching.** Try a case-insensitive match of the sheet nickname
against employee name. On a hit, set `presenter_employee_id`. On a miss, leave it
null and store the raw string in `presenter_name`. `Roy` and `ROY` normalise to
the same lookup. `Team` never matches and stays free text. The seeder prints a
list of unmatched names so HR can fix them in the UI.

**Status rules for the import**

- Title present, month in the past → `done`.
- Row completely blank → `skipped`.
- The `Raya / jamuan raya 4/4/2026` row → `not_tot`.
- PIC present, title `?` or empty, month in the past → `planned`. This is
  deliberate. The sheet gives no evidence the session ran, so the import must not
  invent one. These render as **Needs update** and HR resolves them.
- PIC present, title `?`, month in the future → `planned`.

**Link parsing.** A sheet cell can hold several labelled links. Split on line
breaks, take a leading `Label :` prefix as the label when present, otherwise
label by host: `drive.google.com` → Slides, `docs.google.com/presentation` →
Slides, `canva.com` → Design, `app.read.ai` → Recording.

**Expected result — 36 slots**

| Year | done | skipped | not_tot | planned |
|---|---|---|---|---|
| 2024 | 8 | 4 | 0 | 0 |
| 2025 | 10 | 2 | 0 | 0 |
| 2026 | 4 | 0 | 1 | 7 |
| **Total** | **22** | **6** | **1** | **7** |

The seeder does **not** award Knowledge Bank contribution credit for imported
`done` sessions. Backfilling months from 2024 would rewrite two years of a
counter that people already read as "lessons written". Credit starts with
sessions marked `done` inside the app.

## 9. Contribution credit

`KnowledgeController::markContributed` currently hardcodes `now()`. Extract it to
`KnowledgeContribution::mark(Employee $employee, int $year, int $month): void`
and have both controllers call it. About 10 lines, no behaviour change for
Knowledge Bank.

Rules for the TOT side:

- Fires only on a status transition **into** `done`. Not on title edits, not on
  link uploads. Presenting is the thing that earns it.
- Credits the **session's** year and month, never `now()`. Marking the March
  session done in April credits March.
- **Never revoked.** Flipping a session out of `done` leaves the credit. Revoking
  could silently erase a contribution the person separately earned by writing a
  real entry, and that bug would be invisible.
- No `presenter_employee_id`, no credit. `Team`, `Raya`, and unmatched nicknames
  credit nobody.

## 10. Files

| File | Change |
|---|---|
| `database/migrations/..._create_tot_tables.php` | new — 4 tables |
| `app/Models/TotSession.php` | new |
| `app/Models/TotComment.php` | new |
| `app/Models/TotReaction.php` | new |
| `app/Models/TotParticipation.php` | new |
| `app/Http/Controllers/TotController.php` | new |
| `app/Console/Commands/TotReminder.php` | new |
| `database/seeders/TotHistorySeeder.php` | new |
| `resources/views/screens/tot.blade.php` | new |
| `app/Support/Features.php` | add `'tot'` to `module.knowledge` |
| `app/Support/Amanahku.php` | nav entry + screen meta |
| `app/Http/Controllers/AppController.php` | one `match` arm |
| `routes/web.php` | 8 write routes |
| `bootstrap/app.php` | schedule `tot:remind` daily |
| `app/Models/KnowledgeContribution.php` | add static `mark()` |
| `app/Http/Controllers/KnowledgeController.php` | call the extracted `mark()` |
| `tests/Feature/TotTest.php` | new |

## 11. Testing

PHPUnit feature tests, per the project convention.

**Permissions**
- Employee POST to `tot.store` → 403.
- Employee POST to `tot.update` on someone else's slot → 403.
- PIC updates own slot title → 200, title saved.
- PIC updates a slot they do not present → 403.

**Rating privacy**
- Employee GETs the `tot` screen → screen data carries no `score` for any session.
- PIC GETs it → sees own session's average and notes.
- Management GETs it → sees every average.

**Reactions**
- Emoji outside the whitelist → 422.
- Same emoji twice by the same person → toggles off, row deleted.
- Two different emoji by the same person → both persist.

**Participation**
- Second rating by the same person updates the row, no duplicate.
- Score outside 1-5 → 422.

**Contribution credit**
- Slot flipped to `done` → the PIC has a `knowledge_monthly_contributions` row
  for the **session's** year and month.
- Slot flipped `done` then back to `planned` → the contribution row survives.
- Slot with only `presenter_name` flipped to `done` → no contribution row, no error.

**Reminders**
- `tot:remind` run twice on the same day → one notification per stage, dedupe holds.
- Slot with `skipped` status → no notification.

**Seeder**
- Import produces 36 slots with the status counts in the table above.
- Multi-link 2024-09 and 2024-10 rows parse into 2 links each with distinct labels.
- Re-running the seeder still leaves 36 slots.

## 12. Out of scope

- File upload. Links only, same as the sheet does today.
- Nested comment replies.
- Public score leaderboards or presenter rankings.
- Automatic Knowledge Bank entry creation from a session.
- Backfilling contribution credit for imported history.
- Calendar or Events integration. The first Saturday is computed, not stored as
  a `company_event`.

## 13. Open items

1. **Roster assignment (KIV).** Who sets the yearly roster is deferred. HR and
   management is the working default.
2. **Unmatched presenter nicknames.** The import will leave some slots with a
   `presenter_name` and no employee. HR resolves these in the UI after the first
   deploy.

## 14. Estimate

About 2 to 2.5 days, including the history import, the reminder command, and the
tests.
