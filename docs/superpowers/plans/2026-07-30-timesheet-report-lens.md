# Timesheet report → Lens

Replaces the HR/Management all-staff timesheet report (`/app/timesheet-reports`) with the
approved **Lens** direction from `public/_timesheet-report-revamp.html` (`?v=3`).

## Why

The screen reports rollups and cannot answer the question it is opened to ask.

- There is no route from a number to the sheet behind it. `By staff` shows one bar per
  category or project for a person, never the lines they wrote or their notes. HR asking
  "what did Aisyah actually do in week 30" has to leave the screen.
- `byProject` and `byStaff` group by employee **name**
  (`TimesheetController.php:433` and `:452`). Two staff with the same name merge into one
  row with merged person-days and merged RM. This is not hypothetical: the seeded
  database already carries two `Ahmad Faizal` and two `Mei Ling` rows.
- Every figure is a floor presented as a total. Only `submitted` weeks count, so a week
  a staffer left in draft silently lowers the RM. The screen never says how many weeks
  are missing, and the compliance card above it only knows about the *current* week.
- Person-days per head is constant by construction — a full-time week is always 5 md —
  so a short month means a week nobody submitted, not less work. The current layout
  gives the reader no way to tell those apart.
- No max-width. The three tab panels stretch to the full window.

## The direction

One question at a time. A segmented control picks the lens (category / project /
person), the list shows one bar row per slice, and the right rail is the drill path:
slice → the people inside it → that person's actual weeks, lines and notes.

Money placement does not change. RM stays management/HR only, already enforced by the
screen gate and `MONEY_ROLES`.

## Shared decisions

Decided. Do not re-open them.

- **Measure:** `max-width: 980px; margin: 0 auto;` on the screen wrapper, matching
  `#stage` in the mock. Applies to the empty-state branch too.
- **The mock is the visual authority.** `public/_timesheet-report-revamp.html`, variant
  3. Carry over its shelf lead, chip row, filter row, bar rows, rail, and the two
  animations. Do **not** carry over its picker, its viewport toggle, or `#stageframe`.
- **CSS namespace `uj-tr-`,** in `resources/css/app.css`, following the `uj-ar-` block
  already there. The screen gets no inline `<style>` element.
- **Employee id is the grouping key everywhere.** A display name is a label, never a
  key. This is the same-name bug above; fixing it is Task 1, not a follow-up.
- **The new rollups land beside the old ones, not on top of them.** Task 1 adds
  `lensCategory`, `lensProject` and `lensStaff` and leaves `byCategory`, `byProject` and
  `byStaff` untouched; Task 3 switches the Blade over and deletes the old three in the
  same commit. Replacing the shapes in Task 1 was tried and reverted: the Blade reads the
  old keys, so the screen 500s and `TimesheetCostTest` goes red for the two commits
  between Task 1 and Task 3. A shared branch that another session is committing to does
  not get a red window for the sake of a tidier diff. The duplication lives for two
  commits and dies in Task 3.
- **Weeks:** Monday-start weeks whose Monday falls inside `[from, to]`. A week is *in*
  when a timesheet exists for that employee and `week_start` with status `submitted`.
  Draft or absent counts as missing. A week whose Monday precedes the employee's
  `joined_at` never existed and is not missing.
- **`submitted` is the only status that counts.** Approval was dropped by decision;
  `reportData` already filters on it. Do not reintroduce `approved`.
- **Notes are HTMLPurifier-sanitised on write** (`HtmlSanitizer::clean`), so the rail
  renders `description` with `{!! !!}`, exactly as the personal screen does at
  `resources/views/screens/timesheets.blade.php:558`.
- **Bilingual.** Every new label carries EN and BM through
  `x-text="$store.ui.lang==='en' ? '…' : '…'"`, matching the screen's existing pattern.
  The BM strings are given per task; use them verbatim.
- **Lens switching and rail navigation are client-side Alpine over one server payload.**
  No new route, no reload. The payload is bounded by tenant headcount and the screen
  already loads every entry in the period. A full-page reload here would also break the
  in-screen-swap convention this project holds to.
- **The `This week — team status` roster card keeps its current markup and position** at
  the top of the screen. It is a different job (chasing, current week) and is out of
  scope.
- **Out of scope, tracked separately:** `tsRoster` is built from the whole tenant and
  ignores `DataScope::visibleEmployeeIds`, unlike every other figure on the screen
  (`TimesheetController.php:490` against `:388`). Do not fix it in this plan and do not
  make it worse.

---

## Task 1 — Controller: key by employee id, carry rate and members

**Files:** `app/Http/Controllers/TimesheetController.php`,
`tests/Feature/TimesheetReportLensTest.php` (new).

Add three rollups to `reportData()`: `lensStaff`, `lensCategory`, `lensProject`. Every
existing key, `byCategory`, `byProject` and `byStaff` included, keeps its current shape
and its current code. Task 3 deletes the old three.

`lensStaff` is a list of:

```
['id' => int, 'name' => string, 'initials' => string, 'color' => string,
 'title' => string,            // position title, or '' when unbanded
 'rate' => float|null,         // Position::mandayRate(), null with no band
 'costed' => bool,             // rate !== null
 'days' => float, 'cost' => float,
 'pct' => int]                 // share of grand total days
```

`lensCategory` and `lensProject` are lists of:

```
['id' => int|string, 'label' => string, 'days' => float, 'cost' => float,
 'pct' => int,                 // share of grand total days
 'members' => [['id' => int, 'name' => string, 'initials' => string,
                'color' => string, 'days' => float, 'cost' => float,
                'pct' => int]]]  // pct is share of THIS slice, not of the total
```

For `lensCategory`, `id` is the category id, or the string `'uncategorised'` for entries
whose category was deleted. For `lensProject`, `id` is the project id; entries with no
project are excluded from `lensProject` exactly as they are today.

Sort order is unchanged: cost descending, then days descending. `members` sort by days
descending. Grouping keys are `timesheet.employee_id`, `category_id`, `project_id` —
never a name.

An unbanded employee has `rate => null`, `costed => false`, `cost => 0.0`. Their days
still count in `days` and in every `pct`.

**Tests** (`TimesheetReportLensTest`): two active employees with the identical name
`Mei Ling`, both with submitted entries, produce **two** `lensStaff` rows with distinct
ids and unmerged days; each slice's `members` pct sums to 100 (±1 for rounding); an
employee with no `position_id` has `costed => false` and `cost => 0.0` while their days
appear in the slice total.

---

## Task 2 — Controller: weeks in, weeks missing, and the per-person sheet

**Files:** `app/Http/Controllers/TimesheetController.php`,
`tests/Feature/TimesheetReportLensTest.php`.

Add one key and extend one.

`staffWeeks`, keyed by employee id, is the rail's content:

```
[employeeId => [['label' => 'Week 30', 'dates' => '20 – 24 Jul',
                 'days' => float, 'cost' => float,
                 'lines' => [['label' => 'Development · KPT: RMS · Reporting',
                              'note' => string|null,   // raw sanitised HTML
                              'days' => float]]]]]
```

`label` is `'Week '.$weekStart->isoWeek`. `dates` is the Monday and Friday of that week
as `j M`, joined with an en dash and spaces. `lines` label is category name, project
name and sub-pillar name joined with ` · `, nulls filtered out — the same shape as the
`$entryLabel` closure at `resources/views/screens/timesheets.blade.php:18`. `days` is
`percentage / 100`. Weeks sort oldest first; lines sort by `entry_date`, then by days
descending.

Each `lensStaff` row gains:

```
'weeksIn' => int, 'weeksTotal' => int, 'missingWeeks' => ['Week 29', 'Week 31']
```

`reportTotals` gains `'weeksTotal' => int` (weeks in the period, per the Shared
decisions rule) and `'weeksNotIn' => int` (the sum of every visible employee's missing
weeks).

Build both from data already in hand. The entries collection is already loaded and
already eager-loads `timesheet.employee.positionBand`; add `entries.subPillar` to that
`with()` list and group in PHP. Missing weeks need the submitted `week_start` values and
each employee's `joined_at`: one `Timesheet` query for the period restricted to
`$visibleIds`, and `joined_at` off the employees already attached to the entries, plus
one `Employee` query for visible employees with **no** entries in the period — they owe
every week and must not vanish from `weeksNotIn`.

**Tests** (`TimesheetReportLensTest`): an employee whose week 29 timesheet is `draft`
has those days absent from `days` and `'Week 29'` present in `missingWeeks`; an employee
whose `joined_at` is inside the period is not charged the weeks before it; a line's
`note` carries the stored description text; `reportTotals['weeksNotIn']` equals the sum
of the `missingWeeks` counts; an employee with zero entries in the period still
contributes their weeks to `weeksNotIn`.

---

## Task 3 — CSS, Blade shelf, filters, lens control, bar list

**Files:** `resources/css/app.css`,
`resources/views/screens/timesheet-reports.blade.php`,
`app/Http/Controllers/TimesheetController.php`,
`tests/Feature/TimesheetReportScreenTest.php` (new).

The CSS ships in this task, not a later one. Markup that lands a commit ahead of the
stylesheet that dresses it is an unstyled screen on `dev` for as long as the gap lasts —
the same mistake as replacing the rollups before the view could read them. The whole
`uj-tr-` block, rail classes included, arrives here; Task 4 adds markup that already has
styles waiting.

This task also deletes the legacy `byCategory`, `byProject` and `byStaff` rollups from
`reportData()`, in the same commit that stops reading them. That closes the duplication
window opened in Task 1.

### The CSS

Port the mock's styles into one `uj-tr-` block in `app.css`, placed after the `uj-ar-`
block. Rename every `tr-` class to `uj-tr-`. Take the tokens from the file's existing
`:root` — the mock re-declares them only because it is standalone; do not re-declare them.
Do not port the mock's `proto-picker`, `proto-vp`, `#stageframe` or `#stage` rules.

Carry over: `uj-tr-wrap` (the 980px measure), `uj-tr-shelf`, `uj-tr-lede`, `uj-tr-fig`,
`uj-tr-figrow`, `uj-tr-figsub`, `uj-tr-chips`, `uj-tr-chip` with its `data-t` variants,
`uj-tr-pills`, `uj-tr-pill`, `uj-tr-sel`, `uj-tr-filter`, `uj-tr-range`, `uj-tr-av`,
`uj-tr-who`, `uj-tr-name`, `uj-tr-sub`, `uj-tr-sect`, `uj-tr-note`, `uj-tr-card`,
`uj-tr-bar`, `uj-tr-barrow`, `uj-tr-tag`, `uj-tr-btn`, `uj-tr-empty`, `uj-tr-lens`,
`uj-tr-lensrow`, `uj-tr-panel`, `uj-tr-wk`, `uj-tr-ent`.

Both animations come too: `uj-tr-grow` (bars, `scaleX` from `transform-origin: left`,
420ms, `var(--ease)`, staggered per row through an inline `animation-delay`) and the rail's
entrance (`translateX(14px) scale(.985) blur(3px)` → none, 300ms). Both must be disabled
under `prefers-reduced-motion: reduce`, the rail falling back to a 200ms opacity fade.
Bars animate `transform`, never `width`.

Use a media query, **not** `@container`: the mock needed a container query only because its
harness resizes a frame inside a wide window. `max-width: 640px` collapses `uj-tr-lens` to
one column, un-stickies `uj-tr-panel`, and drops the shelf figure from 54px to 40px.

### The markup

Wrap the whole screen in `<div class="uj-tr-wrap">` (the 980px measure). Keep the
`@include('partials.guide', …)` call and the `← My timesheets` link exactly as they are.
Keep the team-status card. Replace everything from the summary strip down.

**Shelf** (`uj-tr-shelf`), in this order: the period line, the figure row, the chips.

- Period line: `Submitted timesheets · <b>1 – 31 July 2026</b>`, from `$from`/`$to`
  formatted `j M` / `j M Y`.
- Figure: `reportTotals['days']`, mono, followed by `person-days recorded, worth
  RM 80,325.00 at charge-out rates.` Append `` `RM x md have no band and no cost.` ``
  only when `uncostedDays > 0`.
- Chips, only those that apply: total person-days; person-days per head
  (`days / count(lensStaff)`, 2 dp); uncosted md, amber, when `> 0`; weeks not in, amber,
  when `weeksNotIn > 0`.

**Filter row** (`uj-tr-filter`): the existing `from`, `to`, `category` and `project`
controls, unchanged in behaviour — a GET to `app.screen` with `timesheet-reports`. They
stay a server round trip; the period genuinely changes the query. Put the range label
on the right in `uj-tr-range`.

**Lens control** (`uj-tr-pills`), three buttons, no page load:
`By category` / `By project` / `By person`.

**Bar list** (`uj-tr-card` of `uj-tr-lensrow` buttons), one row per slice of the active
lens: the label, a dim sub-label, the value line, then the bar.

- Category and project rows: sub-label is `N people` (`N person` at 1), value line is
  `<b>RM …</b> · x md · y%`.
- Person rows: sub-label is the position title, value line is the same, except an
  unbanded person shows `uncosted` in amber in place of the RM.
- A person row short of `weeksTotal` weeks appends `` `w/x wk` `` dim after the days.
- Bar fill colour: category `var(--info)`, project `var(--success)`, person the
  employee's `color`.

**Empty state** when `$reportEmpty`: a `uj-tr-empty` card reading
`No submitted time matches this filter` with the active filter named beneath it and the
recovery — clear a filter, or widen the period.

Copy, EN then BM, verbatim:

| EN | BM |
|---|---|
| Submitted timesheets | Timesheet dihantar |
| person-days recorded | hari-orang direkod |
| at charge-out rates | pada kadar caj |
| have no band and no cost | tiada band dan tiada kos |
| Person-days | Hari-orang |
| Per head | Setiap orang |
| Uncosted md | Md tanpa kos |
| Weeks not in | Minggu belum masuk |
| By category | Mengikut kategori |
| By project | Mengikut projek |
| By person | Mengikut individu |
| people | orang |
| person | orang |
| uncosted | tanpa kos |
| No submitted time matches this filter | Tiada masa dihantar yang sepadan dengan tapisan ini |
| Clear a filter, or widen the period. | Kosongkan satu tapisan, atau luaskan tempoh. |
| Pick a row to see who is inside it. | Pilih satu baris untuk melihat siapa di dalamnya. |

Two copy rules the mock earns the hard way:

- An unbanded person is **`uncosted`**, never `RM 0.00`. Zero ringgit reads as free
  labour; the truth is that the cost is unknown.
- A person short of a week is **short a submitted sheet, not short of work**. Say that
  in the note under the list whenever `weeksNotIn > 0`, and never let a missing week
  look like idleness.

**Tests** (`TimesheetReportScreenTest`): an HR user sees the three lens labels and the
person-days figure; an unbanded employee's row renders `uncosted` and not `RM 0.00`; the
weeks-not-in chip renders when a draft week exists and is absent when every week is in;
the empty state renders for a filter with no matches; a `manager` and a plain employee
both still get 403 (the gate landed in `eaa47ee`, this must not loosen it).

---

## Task 4 — Blade: the rail

**Files:** `resources/views/screens/timesheet-reports.blade.php`,
`tests/Feature/TimesheetReportScreenTest.php`.

The list and the rail sit in `uj-tr-lens`, a two-column grid, list first. Alpine state
lives on that wrapper:

```
x-data="{
  lens: 'category',
  sel: { kind: 'slice', key: null, from: null },
  rows() { return this.lens === 'category' ? this.category
         : this.lens === 'project' ? this.project : this.staff },
  setLens(l) { this.lens = l; this.sel = { kind: 'slice', key: null, from: null } },
  slice(key) { this.sel = { kind: 'slice', key, from: null } },
  openPerson(id, from) { this.sel = { kind: 'person', key: id, from } },
  // The rail is never empty and never stale: an unset or vanished selection
  // falls back to the biggest row of the active lens.
  currentSlice() {
    const rs = this.rows()
    const hit = rs.find(r => String(r.id) === String(this.sel.key))
    return hit || rs[0] || null
  },
  currentPerson() {
    return this.staff.find(r => String(r.id) === String(this.sel.key))
        || this.staff[0] || null
  },
}"
```

with `category`, `project`, `staff` and `weeks` seeded from `@js($lensCategory)`,
`@js($lensProject)`, `@js($lensStaff)`, `@js($staffWeeks)`.

Rail behaviour:

- Category or project lens, slice selected: the rail lists that slice's `members`,
  biggest first, each a button that calls `openPerson(member.id, slice.key)`.
- Person lens: clicking a row calls `openPerson(row.id, null)` and the rail shows that
  person directly.
- Person selected with a `from`: the rail header carries a `←` button back to that
  slice. Without a `from` there is no back button.
- Nothing selected: the rail shows the biggest row of the active lens, so it is never
  empty. A selection that no longer exists after a lens change falls back the same way.

The person rail shows: avatar, name, position title, `RM …/day` when banded, the
person's `weeksIn`/`weeksTotal`, then one block per week — `Week 30 · 20 – 24 Jul` with
its days and cost, then each line as label, note, days. Under it, when the person has
`missingWeeks`, `Week 29 is not here: no sheet was ever submitted.` And when unbanded,
the existing `No salary band …` wording pointing at Administration → Position & Manday
Rates.

Copy, EN then BM, verbatim:

| EN | BM |
|---|---|
| Pick a person to read the lines behind their share. | Pilih seorang untuk membaca baris di sebalik bahagian mereka. |
| Pick a person to read the lines behind the number. | Pilih seorang untuk membaca baris di sebalik nombor itu. |
| is not here: no sheet was ever submitted. | tiada di sini: tiada lembaran pernah dihantar. |
| are not here: no sheet was ever submitted. | tiada di sini: tiada lembaran pernah dihantar. |
| No salary band, so this time carries no cost. | Tiada band gaji, jadi masa ini tidak membawa kos. |
| Back | Kembali |

On a narrow container the grid collapses to one column and the rail loses its sticky
position, per the CSS in Task 5.

**Tests** (`TimesheetReportScreenTest`): the rail markup carries a stored note's text
for a person with a submitted week; `staffWeeks` reaches the page (assert the week label
string appears); a person with a `draft` week renders the not-here wording.

---

## Assets — an orchestrator gate, not a task

`agy` cannot run `lerd` and cannot commit, so the asset rebuild is not a task. After
Task 3 and again after Task 4, the orchestrator runs, in this order:

```fish
lerd artisan view:cache
bun run build
git add public/build resources/css/app.css resources/views/screens/timesheet-reports.blade.php
```

`view:cache` first is not optional: `app.css` has
`@source '../../storage/framework/views/*.php'`, so Tailwind scans the compiled Blade
cache and a partial cache silently drops utilities. CI compares the committed CSS
against a rebuild in the `Committed assets match sources` job.

---

## Verification, all tasks

`php artisan test --compact` over the **entire** suite after every task. A scoped run
has missed regressions on this project before: `TeamBoardAccessTest` and
`ShippedScopeTest` both exercise timesheet screens without "timesheet" in the test name,
and `TimesheetReminderTest` asserts on the team-status card this plan must not disturb.

`vendor/bin/pint --dirty --format agent` before handing a task back.

Browser verification is the orchestrator's step. `agy` runs headless and cannot drive a
browser; do not put browser steps in a brief. The orchestrator checks
`http://localhost:9100/app/timesheet-reports` as `hr@amanahku.test` through
`/dev/login`, at desktop and at 375px, against variant 3 of the mock.
