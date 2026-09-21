# Statutory source documents

Official Malaysian statutory schedules the payroll calculators are built from. These are the
authority — if code and PDF disagree, the PDF wins.

| File | What it is | Effective | Source |
|------|-----------|-----------|--------|
| `epf-third-schedule-2025-10.pdf` | EPF Act 1991, Third Schedule — monthly contribution amounts by wage band | 1 October 2025 | [KWSP](https://www.kwsp.gov.my/en/epf-act-1991-third-schedule) |
| `socso-act4.pdf` | Employees' Social Security Act 1969 (Act 4), Third Schedule — contribution amounts including the non-employment injury scheme (SKBBK) | RM6,000 ceiling since 1 Oct 2024; SKBBK columns since 1 June 2026 | [PERKESO](https://www.perkeso.gov.my/en/our-services/employer-employee/kadar-caruman.html) |
| `eis-act800.pdf` | Employment Insurance System Act 2017 (Act 800), Second Schedule — EIS/SIP contribution amounts | RM6,000 ceiling since 1 Oct 2024 | [PERKESO](https://www.perkeso.gov.my/en/our-services/employer-employee/kadar-caruman.html) |
| `spesifikasi-kaedah-pengiraan-berkomputer-pcb-2026.pdf` | LHDN specification for MTD (PCB) using computerised calculation — the formulas, reliefs, Table 1 and rounding rules | Updated 1 January 2026 | [LHDN](https://www.hasil.gov.my/en/majikan/jadual-pcb-dan-spesifikasi-data/) |
| `mtd-testing-question-2026.pdf` | LHDN's MTD testing questions — the scenarios a payroll system must answer to get IRBM verification | 2026 | [LHDN](https://www.hasil.gov.my/en/majikan/jadual-pcb-dan-spesifikasi-data/) |

## How the PERKESO schedules are used

Unlike EPF, the published PERKESO amounts do **not** follow a single arithmetic rule — the bands
below RM500 do not match a percentage of the band midpoint — so both tables are transcribed as
data rather than computed. `tests/Fixtures/socso-third-schedule-act4.csv` and
`tests/Fixtures/eis-second-schedule-act800.csv` hold all 65 published rows each, and the SOCSO
fixture's own total columns were checked to sum correctly on every row.

`eis-act800.pdf` is a scan with no text layer; its fixture was transcribed by reading the page
image. If a figure is ever disputed, the PDF is the authority.

## How the PCB specification is used

`App\Services\Payroll\PcbCalculator` implements the formulas in section D of the LHDN spec, with
the constants and rounding rules from section E. EXHIBIT 5 of that PDF works one employee through
January to April with printed intermediate values and answers; those months are the calculator's
acceptance test, asserted down to the K2 and P figures rather than just the final deduction.

`mtd-testing-question-2026.pdf` is a different thing: five scenarios LHDN sets for software
seeking IRBM verification. It ships no answers — applicants submit theirs to IRBM. Useful as a
list of cases a complete implementation must survive (previous-employer figures via Form TP3,
C-suite and knowledge-worker categories, mid-year joiners, monthly TP1 claims), not as a fixture.

Getting formally verified by IRBM is a separate, human process: answer those questions, submit
payslips, EA forms, calculation details and CP39 text files before 1 October. Nothing in this
repo does that for you.

The `payroll_opening_figures` table (`App\Models\PayrollOpeningFigure`, HR-facing "Previous
employment (TP3)" tab) is the previous-employer-figures scenario above in database form — most
of its columns mirror LHDN's Form TP3. `App\Services\Payroll\PcbYearToDate` reads it into the
∑Y/∑K/Z/X/∑LP year-to-date figures `PcbCalculator` needs: `gross`, `epf`, `pcb_paid`,
`zakat_paid`, `additional_gross`, `additional_epf` and `optional_deductions` all feed the
formula. `socso`, `eis`, `previous_employer`, `previous_employer_tin` and `exempt_allowances`
are record-keeping only (year-end EA form, HR reconciliation) and are deliberately never read
by `PcbYearToDate` — see the columns split documented on the model itself.

SKBBK ("Lindung 24 Jam") started 1 June 2026 and became voluntary on 8 July 2026. It is paid
entirely by the employee, 0.75% of wages capped by the RM6,000 ceiling, and is opted into per
employee on their salary structure. The rate is scheduled to rise to 1.0% and later 1.25%; when
that happens PERKESO publishes a new table and the fixture is replaced.

## How the EPF schedule is used

`App\Services\Payroll\EpfCalculator` does not hardcode the 1,203 published rows. Every row is
each side's percentage applied to the top of its wage band, rounded up to the next ringgit, so
the calculator computes them. That equivalence is not an assumption: every row of Parts A, C and
E was transcribed into `tests/Fixtures/epf-third-schedule-2025-10.csv`, and
`tests/Unit/EpfCalculatorTest` checks the calculator against all of them.

Parts, as published:

- **A** — citizens, permanent residents, and non-citizens who elected before 1 Aug 1998, under 60.
- **B** — deleted by Act A1760/2025.
- **C** — permanent residents and pre-1998 electors, 60 and over.
- **D** — deleted by Act A1760/2025.
- **E** — Malaysian citizens, 60 and over.
- **F** — other non-citizens, mandatory since 1 Oct 2025: a flat 2% each side of actual wages.

## When KWSP publishes a new schedule

1. Download the new PDF from the KWSP link above and add it here alongside the old one.
2. Regenerate the fixture from it (`pdftotext -layout`, then parse the `From … to …` rows).
3. Run `php artisan test --filter=EpfCalculatorTest`. If it fails, the amounts are no longer a
   plain band-upper-limit percentage and the schedule must be transcribed as data instead.

## Agency upload files

The finalized run's Payment screen hands HR one file per agency. Each one lives in
`App\Services\Payroll\Statutory` and is pinned by a golden file in `tests/Fixtures/statutory`
that was typed out by hand from the layout, not generated by the exporter.

| File | Class | Golden file | Layout source | `verified()` |
|------|-------|-------------|---------------|--------------|
| LHDN CP39 (PCB) | `LhdnCp39` | `cp39-2026-06.txt` | Exhibit 4 of `spesifikasi-kaedah-pengiraan-berkomputer-pcb-2026.pdf`, in this folder | true |
| KWSP Form A | `KwspFormA` | `kwsp-form-a-2026-06.csv` | ours, provisional | false |
| PERKESO Borang 8A | `PerkesoBorang8A` | `perkeso-8a-2026-06.txt` | ours, provisional | false |
| HRD Corp levy | `HrdCorpLevyFile` | `hrdcorp-2026-06.csv` | ours, provisional | false |

`verified()` is false when the layout is our own reading rather than a transcription of the
agency's published specification. The UI puts a "check layout" pill on those buttons and the
audit trail records the download as an unverified layout. SKBBK has no column in Borang 8A; it
is paid through the ASSIST portal.

### Verifying a provisional layout

1. Download the agency's current upload specification into this folder.
2. Add a row for it in the table above with the file name and its date.
3. Re-transcribe the golden file by hand from the document's own example record, then run
   `php artisan test --compact tests/Feature/StatutoryAgencyFilesTest.php` and fix the exporter
   until it matches. Never regenerate a golden file from the exporter's output.
4. Flip that class's `verified()` to true.
