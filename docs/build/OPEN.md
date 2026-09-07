# OPEN – decisions taken without Shazwan

Every entry is a decision the run made because it could not stop to ask. This file is the first thing Shazwan reads when the run ends, before any code.

Append only. Never remove an entry. If a later session changes an earlier decision, add a new entry referencing the old one.

## Format

```
### <session id> / <CR-ID> / <short title>
- Question: what was undecided
- Decided: what was implemented
- Alternatives: what else was viable and why it was not chosen
- Reversal cost: cheap / medium / expensive, and what would have to change
- Source: CR text says "to confirm" / contract conflict / spec silent
```

## Known open questions carried in from the tracker

These are already known before the run starts. A session that hits one of them still writes its own entry above, recording what it actually implemented.

| CR | Question | Safe default for the run |
|---|---|---|
| CR-02 | Read-only, or timesheet approval rights too | Read-only |
| CR-06 | Which fields mandatory vs optional; project-code format agreed with Finance | All new fields optional except Client and Status; project code is a configurable regex with a documented placeholder default |
| CR-09 | Does TOT attendance also feed the Attendance module for Saturday work | No, TOT attendance stays in the Learning module |
| CR-13 | Weekend or holiday birthday: show again on the next working day | Show on the day only, do not repeat |
| CR-16 | BM label for The Playground | Keep 'The Playground' in both languages |
| CR-18 | Minimum attendance percentage satisfying the social-activity Done rule | At least one Attended, configurable, default 1 |
| CR-19 | Track WBS-linked card at 100 percent, auto-Done or not | Not auto-Done. Deferred with the rest of the Track binding |
| CR-14 | Does any award carry a tangible reward | No tangible reward, recognition only |
| CR-05 | Definition of Done checklist templates per project | Not implemented, single global checklist only |

## Entries

<!-- sessions append below this line -->

### S00 / CR-32 / Appendix B column for My work summary and My working style
- Question: Appendix B draws "My work summary" and "My working style" in the left column; the registry (`DashboardWidgets`) has both in the right column, and users can drag them anyway.
- Decided: contract follows the code, right column. Nothing moved.
- Alternatives: move both to left to match the drawing. Rejected because CR-32 forbids reordering existing cards and the drawing was made from memory.
- Reversal cost: cheap, change `column` on two registry entries.
- Source: spec vs code conflict.

### S00 / CR-04 / meaning of existing "shared with" participants
- Question: `work_item_participant` rows exist today with no Helper/FYI distinction. What are they after S03?
- Decided: contract says existing rows become `role = 'helper'`.
- Alternatives: `fyi` (no credit). Rejected because today's participants can edit the card and are shown as working on it; stripping credit silently would under-report. Owners can downgrade a helper to FYI afterwards.
- Reversal cost: cheap, one data migration.
- Source: spec silent.

### S00 / Global Clause / fixture data and the "no attendance writes before S01" hard stop
- Question: S00 must produce three months of attendance, timesheet and card fixtures, but the hard stop says nothing writes to attendance or timesheet tables before S01.
- Decided: the fixture seeder is written and proven on the test database only (`BuildFixturesSeederTest`). It is not run against the dev database in S00. S01 or later runs `php artisan db:seed --class=BuildFixturesSeeder` when a session needs the data; the baseline screenshot is taken on the untouched dev data.
- Alternatives: run it in S00. Rejected because the dev database is a copy of production staff and the hard stop is explicit.
- Reversal cost: cheap, run the seeder.
- Source: rules conflict.

### S00 / CR-01 / Google Calendar client already exists
- Question: tracker treats CR-01 as unbuilt; a one-way Calendar sync (`GoogleCalendarClient`, `SyncWorkItemCalendarEventJob`) already exists.
- Decided: keep it, wrap it as the real `CalendarPort` adapter in S07, leave it unbound during the run. Feature code after S07 calls the port only.
- Alternatives: delete the client and rebuild behind the port later. Rejected, throws away working code.
- Reversal cost: cheap.
- Source: spec vs code.
