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
