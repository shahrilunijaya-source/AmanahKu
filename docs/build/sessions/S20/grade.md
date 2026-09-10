# S20 grade: CR-33 creative greeting line

**Verdict: PASS.** No code defects. One QA test defect (F1, in the acceptance file, fixed
by QA). Every acceptance item was driven in the browser as Shazwan on the dev copy with the
dev clock; every every-session check is green.

Graded on commits `9e80afa4` (feature), `51f8793d` (session docs), `3c7b41b4` (settings
picker test) on top of `7e26ef52`, with the QA fix `717323dc`.

## F1 (QA, fixed): item 1 ran on the fixture's birthday

`CR33Test::setUp()` gives Yati a 15 September birthday and item 1 ran on 2026-09-15, so the
birthday bucket rightly won and item 1 could never pass. The session flagged this in its
handoff and OPEN rather than bending the birthday rule, which was the right call. Item 1
now runs on Tuesday 2026-09-22 (`717323dc`). The session's OPEN entry "birthday is
unconditional and collides with CR33Test's own Tuesday-morning test" is closed by this fix.

## Acceptance items

| # | Item | Result | Evidence |
|---|------|--------|----------|
| 1 | Ten loads on a Tuesday morning: 3+ different lines, no immediate repeat | PASS | Dev clock 2026-09-22 10:00, ten loads gave 7 distinct morning lines, never the same line twice in a row, with overdue cards on the board and no clock-in and not one line about either (`grade-cr33-1-tuesday.png`). Bonus buckets seen: 07:30 "Up early, Shazwan. The office is still quiet.", Wednesday "Wednesday, Shazwan. Halfway up the hill.", Saturday "Saturday check-in, Shazwan? Rest is close by.", 1 October first load "New month, Shazwan. Fresh page." then a morning line on the next load (`grade-cr33-month-start.png`). |
| 2 | Friday 4 PM: a Friday or evening line | PASS | Dev clock 2026-09-18 16:00, four loads, all Friday lines, e.g. "Last stretch, Shazwan. Friday is nearly done." (`grade-cr33-2-friday.png`). |
| 3 | Birthday wins over everything | PASS | Shazwan's DOB set to 15 September on the dev copy for the check (restored after). Dev clock 2026-09-15 09:00: Malaysia Day eve, Tuesday, overdue cards. Five loads, all birthday lines, rotating (`grade-cr33-3-birthday.png`). 16 September: a Wednesday line. |
| 4 | Keep it plain: "Good morning, Yati." only | PASS | With Keep it plain on, 2026-09-22 09:00: "Good morning, Shazwan." on every load, BM toggle gives "Selamat pagi, Shazwan." (`grade-cr33-4-plain.png`). Off again: bank lines return. |
| 5 | BM toggle shows the BM line | PASS | EN "Morning, Shazwan. Coffee first, emails second." flips to "Pagi, Shazwan. Kopi dulu, emel kemudian." on the BM toggle, same row (`grade-cr33-5-bm.png`). |

Rules: the dev bank has no `overdue` / `not_clocked_in` rows left (migration ran on dev),
83 approved lines for tenant 1 covering all 18 triggers; HR's Company Settings greeting
card offers every trigger including the nine new ones (`grade-cr33-settings.png`); `rain`
stays inert behind `AMANAHKU_WEATHER_ENABLED` (default false), no weather call anywhere.

## Every-session checks

| Check | Result |
|-------|--------|
| `php artisan test --compact tests/Acceptance/CR33Test.php` | 9 passed (after F1) |
| Full suite | 2929 tests, 2923 passed, 1 failed: the known pre-existing `LeaveScreenTabsTest::test_cancelling_an_approved_replacement_refunds_the_quota`, untouched by S20 |
| Due date PATCH (`/app/board/229`, Shazwan) | 422 |
| Audit row edit (tinker `update()` on row 1150) | rejected: "audit_logs rows are append-only" |
| Dashboard as Shazwan, quiet day 2026-09-10 09:00 | widget order unchanged, only the h1 text differs (`grade-cr33-dashboard.png`) |
| Keep it plain | plain greeting only, no animation added by S20 |
| Outbound calls in the diff | none |
| OPEN entries | six new entries, each with Alternatives and Reversal cost |
| Protected files | untouched |

## Observations, not defects

- `all_clear` is a situation trigger, so per the spec's priority order anyone with open
  cards and nothing overdue gets an "all clear" line ahead of day and time lines, every day.
  That follows the CR text; if it wears thin, demote it to the day bucket (one line in
  `GreetingLine::TRIGGERS`).
- "First load" markers for `month_start` and `back_from_leave` live in the session, so a
  fresh login re-arms them. Recorded in OPEN by the session.
- The S19 grade left Shazwan's Keep it plain on (my restore click did not persist). Turned
  off during this grade; prefs now `plain: false`.

## Housekeeping

Shazwan's DOB restored to the prod value, dev clock real, Keep it plain off, no test rows
left on the dev copy.
