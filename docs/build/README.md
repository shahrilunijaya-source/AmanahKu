# Amanahku Autonomous Build, Starter Bundle

Everything Fable needs on disk before session S01. Nothing here required access to the
codebase. What does require the codebase is session S00, which you run first.

## What is in here

```
docs/build/README.md                    this file
docs/build/BUILD-PLAN.md                the plan this bundle was cut from
docs/build/RULES.md                     standing rules, read every session
docs/build/OPEN.md                      decisions taken without Shazwan, empty except known questions
docs/build/CLAUDE-rules-block.md        appended to CLAUDE.md during S00
docs/build/sessions/TEMPLATE.md         handoff template, sessions write docs/build/sessions/<id>/
docs/build/contracts/                   empty, filled during Wave A
docs/build/baseline/                    empty, S00 writes dashboard.png here
docs/specs/CR-01.md ... CR-34.md        34 CRs, split verbatim from the tracker
docs/specs/global-clause.md             audit and data integrity, cross-cutting
docs/specs/date-calendar-rules.md       locked due dates, cross-cutting
docs/specs/culture-pack-preamble.md     shared principle for CR-22 to CR-31
docs/specs/appendix-a-left-panel.md
docs/specs/appendix-b-dashboard-placement.md
.claude/commands/session.md             the /session command
.claude/commands/qa.md                  the /qa command
tests/Acceptance/                       empty, filled by /qa write (PHPUnit)
```

## Install

Already installed in this layout. Commit before running anything, so every session's
diff is reviewable against a clean starting point.

`.claude/` is gitignored, so `session.md` and `qa.md` live only in the checkout they were
copied into. A fresh clone or worktree needs them copied in by hand before `/session` or
`/qa` exist there.

## Run order

**Step 1, S00, the only session that needs your attention.**

```
/session S00 CR-00
```

S00 is a survey, not a build. It writes no feature code. It produces:

- `docs/build/findings.md`: real answers to the four reconciliation questions below
- `CLAUDE.md`: the block from `CLAUDE-rules-block.md` verbatim, plus the repo-specific
  half only Claude Code can write (stack, build command, test command, migration
  command, directory conventions, how to start the app for browser checks)
- `docs/build/contracts/roles.md`, `docs/build/contracts/audit-log.md`, `docs/build/contracts/dates.md` grounded in the
  real schema, not written from the CR text
- `baseline/dashboard.png` and reproducible seed fixtures covering three months of
  cards, timesheets, attendance and clock records

**Step 2, read `docs/build/findings.md` yourself.** This is the single human checkpoint. If CR-05's
role model is further from CR-04 than expected, S03 becomes a migration session rather
than an additive one, and the Wave A estimate moves.

**Step 3, then loop, unattended:**

```
/qa write CR-04          writes the acceptance tests first
/session S03 CR-04       implements against them
/qa grade CR-04          grades it, any FAIL goes back to the generator
/clear                   then the next session
```

The `/clear` matters. The handoff file carries state between sessions, not the context
window. That is the whole point of the handoff template.

## The four reconciliation findings S00 must answer

**F1. CR-05 was built before CR-04.** CR-05 references CR-04 four times: tagged helpers,
counters, the Reviewer-only Done rule. Subtasks are running on an improvised role model.
S03 migrates it onto the canonical model, it does not add a second one alongside.

**F2. CR-13, CR-15, CR-20 and CR-23 were placed before CR-32 defined the slots.** CR-32
says birthday, holiday eve, Wrapped, Big Deal and Victory Bell share one rotating
Moments band. Two of those five are live as separate cards. S04 retrofits.

**F3. "Keep it plain" does not exist but CR-13 and CR-20 already ship confetti.** The
toggle is specified inside CR-31, unbuilt. It is pulled forward into S04.

**F4. Unknown: does the audit log exist, and are subtask due dates actually locked?**
Neither the Global Clause nor the Date and Calendar Rules is a numbered CR, so neither
appears in the done list, yet CR-05 should already be enforcing locked due dates.

## Before you leave it running

Spot-check the split. Open `docs/specs/CR-14.md` and confirm all 19 award rows and all 11
rules survived. If that table came through mangled, S17 will build fifteen wrong award
rules and S18 will build a carousel on top of them.

One known transcription gap: `docs/specs/CR-20.md` has an emoji that the source PDF did not
export. It is marked `[TRANSCRIPTION GAP]` in place. CR-20 is already built, so this
does not block anything, but do not let a session invent a replacement.

## What is deliberately not in this run

CR-01 (all parts), CR-06c Track pull, CR-07, CR-08, the CR-34 email and meeting pack,
the CR-19 calendar row, the CR-17 digest email. Each needs a credential or an external
account. Session S07 builds `CalendarPort`, `TrackPort` and `MailPort` with stub
adapters so the dependent features still get built and tested. When you have the
credentials, you write one real adapter per port and the acceptance tests already exist.
