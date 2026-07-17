# Scope — Payroll Statutory Bracket Tables (I-013)

**Goal:** Replace the SOCSO/EIS *flat-percentage-on-capped-wage* approximation with the
official PERKESO stepped **contribution bracket tables** (Jadual Caruman), so contributions
match the legally-published ringgit amounts exactly.

**Status:** SCOPED — not yet built.
**Resolves:** I-013 (SOCSO/EIS approximation). **Touches:** I-014 indirectly (rate config).
**Out of scope:** EPF (already exact — percentage with no bracket rounding), PCB/MTD (I-016),
bank-specific export formats (I-017).

---

## Locked decisions (2026-06-24)

| Decision | Choice | Rationale |
|----------|--------|-----------|
| Bracket storage | **PHP constant class** `StatutoryBrackets` | Statutory data is identical for every tenant + only changes when PERKESO republishes. Versioned in git, no migration, mirrors existing `StatutoryRate::defaults()`. |
| Categories | **Both Cat 1 (<60) and Cat 2 (≥60)** | Correct for all staff. Requires capturing employee age. |

---

## Background — how PERKESO contributions actually work

- Contributions are **fixed published amounts per wage band**, NOT a clean percentage of
  the exact wage. Bands step in **RM100** increments (with finer bands at the very bottom).
- Wage **ceiling = RM6,000** (raised from RM5,000 effective **1 Oct 2024**). Wages above
  RM6,000 contribute at the top band.
- The contribution is based on the **band**, not the precise salary — e.g. a RM2,150 and a
  RM2,199 wage land in the same band and pay the same. This is why a flat `wage × 0.5%`
  is only an approximation.
- **Category 1 (employee < 60):** SOCSO = Employment Injury + Invalidity (employer + employee);
  EIS applies (employer + employee).
- **Category 2 (employee ≥ 60):** SOCSO = **Employment Injury only** (employer-side only,
  employee pays 0); **EIS does not apply** (no employee, no employer). Also EIS excludes
  anyone first employed at/after age 57 who never previously contributed — treat as a
  follow-up refinement, not blocking.

> **CRITICAL — data sourcing:** The exact per-band ringgit amounts MUST be transcribed from
> the official PERKESO **Jadual Caruman** (contribution schedule), not computed or guessed.
> Source: PERKESO (perkeso.gov.my) — "Jadual Caruman SOCSO" and "Jadual Caruman SIP/EIS",
> current schedule effective 1 Oct 2024. Implementation step 1 is obtaining and transcribing
> this table; do not fabricate amounts. Store the schedule date as a constant for audit.

---

## Design

### 1. Bracket data — `app/Services/Payroll/StatutoryBrackets.php`

Pure constant/static-method class. One band list per (type × category).

```
final class StatutoryBrackets
{
    public const SCHEDULE_EFFECTIVE = '2024-10-01';   // PERKESO Jadual Caruman in force

    // Each band: wage "exceeding from, not exceeding to" → employee + employer ringgit.
    // Transcribed verbatim from the official schedule. ~60 bands per list.
    public const SOCSO_CAT1 = [ ['from'=>0,   'to'=>30,  'ee'=>0.10, 'er'=>0.40], ... ];
    public const SOCSO_CAT2 = [ ['from'=>0,   'to'=>30,  'ee'=>0.00, 'er'=>0.30], ... ]; // EI only, ee=0
    public const EIS        = [ ['from'=>0,   'to'=>30,  'ee'=>0.05, 'er'=>0.05], ... ]; // Cat1 only

    public static function socso(int $category): array { return $category >= 2 ? self::SOCSO_CAT2 : self::SOCSO_CAT1; }
    public static function eis(int $category): array   { return $category >= 2 ? [] : self::EIS; } // empty = N/A
    public static function lookup(array $bands, float $wage): array; // band whose from < wage <= to; top band if above ceiling
}
```

### 2. Employee age — new `date_of_birth`

- Migration: add nullable `date_of_birth` (date) to **`employees`** (drives more than payroll).
- Category derived at run time: `age = period_end.diffInYears(date_of_birth)` → `>= 60 ? 2 : 1`.
- **Null DOB fallback:** treat as Category 1 (<60) AND surface a per-payslip warning + a
  run-level banner ("N employees missing DOB — verify category"). Never silently mis-categorise.
- Surface `date_of_birth` in the employee add/edit form ([EmployeeController](app/Http/Controllers/EmployeeController.php) + profile).

### 3. Calculator — `PayrollCalculator::compute()`

Keep the class **pure** (no DB). Brackets arrive via the existing `$rates` array so the
signature is unchanged.

- `merged()` injects the bracket lists: `$rates['socso']['brackets']`, `$rates['eis']['brackets']`.
- New optional input: `$inputs['statutory_category']` (1|2, default 1).
- SOCSO/EIS branch:
  - **If `brackets` present** → `StatutoryBrackets::lookup($bands, min($statWage, ceiling))`
    returns `ee`/`er` ringgit directly (no percentage math).
  - **Else** → existing flat-`%`-on-capped-wage path (UNCHANGED — backward compatible, keeps
    the current unit tests green and supports tenants that override to a flat rate).
  - Category 2 → EIS bands empty → `eisEmployee = eisEmployer = 0`; SOCSO uses CAT2 (ee=0).
- EPF path unchanged.

### 4. Wiring — `StatutoryRate::merged()`

Add `'brackets'` to the `socso`/`eis` entries from `StatutoryBrackets`. The flat `employee_pct`
/ `wage_ceiling` keys stay (still the fallback + the ceiling clamp + the UI display values).
`merged()` remains the single source for the calculator and the UI.

### 5. PayrollController run loop

In `runDraft()`/recompute ([PayrollController](app/Http/Controllers/PayrollController.php) ~L148–L218):
per employee, compute `statutory_category` from DOB + the run's period end, pass it into
`compute()`. Collect a `missing_dob` count for the run banner.

### 6. UI — `screens/payroll.blade.php` rate-config tab

- Show the active bracket schedule **read-only** (effective date + a collapsible band table)
  so HR can see the real figures; keep the editable ceiling + flat-rate overrides for the
  fallback path. Add a note: "Bracket mode active — contributions use the PERKESO schedule;
  flat rates apply only if brackets are cleared."
- Employee form: DOB field (date input).

### 7. Seed

- `DatabaseSeeder`: set `date_of_birth` on seeded employees (spread ages, include ≥60 so Cat 2
  is exercised by the demo + a finalized run).
- No bracket seed needed (constant class).

---

## Tests

**Unit — `tests/Unit/PayrollCalculatorTest.php` (extend):**
- Band boundary: wage exactly on a band edge picks the correct band (`from < wage <= to`).
- Two wages inside one band pay the **same** SOCSO/EIS (proves bracket, not %).
- Above ceiling (>RM6,000) → top band.
- Category 2: SOCSO employee = 0, employer = CAT2 amount; EIS ee = er = 0.
- Fallback: no `brackets` key → existing flat-% values still produced (existing tests stay green).

**Unit — new `StatutoryBracketsTest`:**
- `lookup()` returns expected band for representative wages; monotonic non-overlapping bands;
  every band has `to > from`; no gaps up to the ceiling.

**Feature — `tests/Feature/PayrollTest.php` (extend):**
- A finalized run with a ≥60 employee zeroes their EIS and uses SOCSO Cat 2.
- Missing-DOB employee → Category 1 + run banner reports the count.

---

## Migration & effort

- Migration: `2026_06_24_0000NN_add_date_of_birth_to_employees` (next free prefix — confirm at
  build time; prefix collisions are harmless in this repo but pick the next number).
- Effort: **~M (half-day)** once the official Jadual Caruman is in hand. Breakdown:
  transcribe tables (largest + must be exact) · `StatutoryBrackets` + `lookup` · calculator
  branch + category · DOB migration/form/seed · `merged()` wire · UI read-only table · tests.
- Risk register:
  - **R1 (high): table accuracy.** Wrong amounts = wrong statutory filing. Mitigate: transcribe
    from official PDF, add a unit test asserting a handful of known reference rows, keep the
    in-app "verify before real run" banner until a human signs off.
  - **R2 (med): DOB data quality.** Most employees will lack DOB initially → fallback + banner.
  - **R3 (low): schedule changes.** PERKESO republishes → bump the constant + `SCHEDULE_EFFECTIVE`.

## Acceptance criteria

- [ ] SOCSO + EIS contributions for any wage match the official Jadual Caruman amounts (not `wage × %`).
- [ ] Wages above RM6,000 use the top band; sub-RM100 wages use the fine bottom bands.
- [ ] Employees ≥60 at period end: SOCSO Cat 2 (employee 0), EIS = 0.
- [ ] Missing DOB → Category 1 + visible run banner; never a silent mis-category.
- [ ] Flat-% fallback intact; all existing payroll tests stay green.
- [ ] `php artisan test` green; `view:cache` clean.
- [ ] ISSUES I-013 moved to Resolved; in-app approximation banner updated to "verify schedule date".
