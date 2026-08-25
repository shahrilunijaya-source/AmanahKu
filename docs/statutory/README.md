# Statutory source documents

Official Malaysian statutory schedules the payroll calculators are built from. These are the
authority — if code and PDF disagree, the PDF wins.

| File | What it is | Effective | Source |
|------|-----------|-----------|--------|
| `epf-third-schedule-2025-10.pdf` | EPF Act 1991, Third Schedule — monthly contribution amounts by wage band | 1 October 2025 | [KWSP](https://www.kwsp.gov.my/en/epf-act-1991-third-schedule) |

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
