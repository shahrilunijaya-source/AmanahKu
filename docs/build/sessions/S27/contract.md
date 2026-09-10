# Session S27 contract: CR-27 Mystery Award

Read RULES.md, contracts/*, CR-27.md, CR-14.md, global-clause.md, date-calendar-rules.md,
culture-pack-preamble.md, S26 handoff, OPEN.md (incl. "QA / CR-27 / shapes fixed by
CR27Test"), tests/Acceptance/CR27Test.php docblock, S27 mockup. Shapes below are exactly
what CR27Test and its docblock fix; nothing here is a new decision.

## Schema

Migration `create_mystery_award_tables` (timestamped by `make:migration`):

- `mystery_awards`: id, tenant_id (FK, cascade), month (date), employee_id (FK employees,
  cascade), category (string 80), explanation (string 500), picked_by (FK employees,
  cascade), published_at (nullable datetime), timestamps. Unique (tenant_id, month) — one
  row per tenant per month, a later pick replaces the earlier one (delete+insert).
- `mystery_committee`: id, tenant_id (FK, cascade), month (date), employee_id (FK
  employees, cascade), timestamps. Unique (tenant_id, month, employee_id).

No Eloquent models: every access is `DB::table()`, same convention as
`award_nominations`/`award_snapshots`; no route-model binding needed (routes take no
`{mystery}` param), no relation is consumed elsewhere. Adding a model later is additive.

## Controller (extends `AwardController`, no new controller file)

- `mysteryPick(Request)` → `POST /app/awards/mystery`. Auth: director OR a
  `mystery_committee` row for `selectionMonth()` matching the acting employee (else 403).
  Validates `employee_id` required int, `category` required string max:80, `explanation`
  required string max:500. Refuses (422, key `employee_id`) when `employee_id` equals the
  employee_id on the previous month's `mystery_awards` row (no back-to-back), independent
  of that row's publish state. Delete+insert the month's row (replace semantics). Audit
  `award.mystery_picked`, target `mystery:<row id>`.
- `mysteryCommittee(Request)` → `POST /app/awards/mystery/committee`. Auth: director only
  (403 otherwise). Validates `employee_ids` required array size:3, each int; manual
  distinct-count check (3 unique) and active-employee check, both surfaced under the
  `employee_ids` key (not `employee_ids.*`, so `assertSessionHasErrors('employee_ids')`
  matches both the count and the duplicate case). Delete+insert the month's three rows.
  Audit `award.mystery_committee`, target `mystery_committee:YYYY-MM-01`.
- `screenData()` extended with: `isDirector`, `isMysteryCommitteeMember`, `mysteryMonth`
  (selectionMonth() Y-m-d — safe, not a secret), `mysteryPicked` (bool: a row exists for
  that month, regardless of publish state — this is the sealed marker, never the
  category/explanation), `mysteryCommitteeMembers` (Employee collection for that month),
  `mysteryLastWinnerId` (previous month's winner id, **only when that row is already
  published** — otherwise still sealed, so it must not leak here either).
- Both new routes sit in the same `app` middleware group, right after the existing
  `awards.adjust` route. No path collision: `/app/awards/mystery` and
  `/app/awards/mystery/committee` never match the existing `{result}/react|comments|adjust`
  patterns (different literal suffixes).

## Publish stamp

`AwardsPublish::publishTenant()`, inside the existing "already published this month"
idempotency guard (so it fires once, on the first working day, same as every other award):
`UPDATE mystery_awards SET published_at = $publishedAt WHERE tenant_id = ? AND month = ?
AND published_at IS NULL`. Fires even when `$rows` (the regular award_results batch) is
empty — a month can have a mystery pick and nothing else. Never touches `award_results`.

## Rendering

- `App\Support\AwardBoard::slidesForMonth()` gains a private `mysterySlide()` lookup:
  `mystery_awards` row for that month with `published_at` not null → a plain object
  `{award_key: 'mystery', employee, employee_id, category, explanation, byDirector,
  monthLabel}`, appended **last** to the slides collection (or returned as the only slide
  when there are no regular results for the month — cheap to support, no reason the
  mystery award should silently vanish just because nothing else published that month).
  Not an `AwardResult`, so it never flows through the existing tie/rule-9/10 machinery.
- New partial `partials/awards/mystery.blade.php` (`.uj-ma-*` markup per the mockup),
  included from `bands.blade.php` (attr=`slide`) and `screens/awards.blade.php` (attr=
  `award`) instead of `partials/awards/result.blade.php` whenever `$group->award_key ===
  'mystery'`. No CR-30 reaction row (mockup marks it optional; there is no `AwardResult`
  row to key reactions on, and CR-27 says "not counted" — matches the existing OPEN.md
  "left open" note, not a new decision).
- Select tab (`screens/awards.blade.php`): mystery pick form (`partials/awards/mystery-
  form.blade.php`, `.uj-ma-form`) or, when `mysteryPicked`, the sealed box
  (`.uj-ma-sealed[data-mystery-picked="YYYY-MM-01"]`) with a `<details>` "Pick again"
  revealing the same form — never the current category/explanation. Committee chips +
  "Change" form, director-only. The whole mystery block (form/sealed + committee) is
  gated on `isDirector || isMysteryCommitteeMember`, and `$canSelect` (which decides
  whether the Select tab itself appears) is extended with `isMysteryCommitteeMember` so a
  non-manager committee member still reaches it.
- CSS: mockup's `.uj-ma-*` block appended to `resources/css/app.css` verbatim.

## Verification (per acceptance item)

1. `test_acceptance_1`: pick → publish stamps `published_at` → slide last on `/app/dash`
   for 3 roles, `/app/awards` default and `?month=` both show `data-award="mystery"`.
2. `test_acceptance_2`: nothing leaks pre-publish across 5 pages × 4 viewers × 2 times;
   sealed marker with no category on `/app/awards` for the director; reveals after publish.
3. `test_acceptance_3`: 403s without a committee, committee CRUD validation (403 non-
   director, size, duplicate), committee-member pick, no-rubric column, replace semantics,
   back-to-back refusal after publish, never an `award_results` row / badge / Hall of Fame
   even after 3 wins.
4. `test_acceptance_4`: plain mode keeps text, drops `uj-db-art`/`<canvas`/`<audio`.

Plus `tests/Feature/MysteryAwardTest.php` for: cross-tenant 404 on... (mystery routes take
no bound model, so there is no route-model tenant leak to test the usual way — instead:
committee member picking is exercised inside CR27Test already; this file adds an
out-of-tenant committee member cannot be set (validation), an inactive employee cannot be
picked as committee, and category/explanation length limits (81/501 chars → 422).
