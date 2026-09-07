# Amanahku Autonomous Build Plan for Fable 5.1

**Source:** Amanahku Change Request Tracker v1.0 (Yati, 5 Sep 2026)
**Status date:** 7 Sep 2026
**Mode:** long-running, uninterrupted. Nothing in the run may wait on Shazwan.
**Done (8):** CR-02, CR-05, CR-12, CR-13, CR-15, CR-16, CR-20, CR-23

---

## 1. What gets discarded, and why it is a binding not a CR

Five things need you: Google OAuth credentials, a Workspace test account, Track API access, a mail provider, and the Finance project-code format. Each of those is a **provider binding**, not a whole requirement. Discard the binding, keep the requirement, and the autonomous run loses far less than it looks.

| CR | Autonomous now | Deferred to you |
|---|---|---|
| CR-01 Calendar sync | Nothing. It is entirely the binding | All of it: OAuth client, consent screen, Workspace test account, quota |
| CR-06 Project master | 6a schema, versioning, effective dates; 6b variations, approvals, field permissions, closed lock | 6c Track pull (Track API access), project-code format from Finance |
| CR-07 Weekly report card | Nothing useful. Depends on CR-01 and CR-06c | All of it |
| CR-08 Push comments to Track | Nothing. Track binding | All of it |
| CR-11 Events | Attendees, RSVP, T.A.A. cards, post-event photos and lessons, Knowledge feed, Dashboard card | The Google Calendar leg only |
| CR-17 Management panels | Both panels, nudge, reassign, audit, role scoping | The 8:00 AM digest email leg only |
| CR-19 Auto-Done | Every rule except the two calendar-sourced rows | The Google Calendar event card row |
| CR-34 Friday reminder | The per-manager 8:00 AM T.A.A. task, overdue behaviour, exclusion from awards | The 3:00 PM email and the Track meeting pack |

**So build the ports in session S07 and the deferred work becomes a plug-in, not a rewrite.** Three interfaces: `CalendarPort`, `TrackPort`, `MailPort`. Each ships with a local stub adapter that writes to a table and a log instead of calling out. Every downstream CR codes against the port. When you have credentials, you write one real adapter per port and the acceptance tests already exist.

Without this, CR-11, CR-17, CR-19 and CR-34 either get built wrong or get skipped entirely, and you lose 4 more CRs than you need to.

---

## 2. Run scope

**In the run (21 CRs plus 2 cross-cutting sections):**
CR-03, 04, 06a, 06b, 09, 10, 11 (minus calendar), 14, 17 (minus email), 18, 19 (minus calendar rows), 21, 22, 24, 25, 26, 27, 28, 29, 30, 31, 32, 33, plus the Global Clause audit log and the Date and Calendar Rules.

**Deferred queue for when you are back (about 7 sessions):**
CR-01a, CR-01b, CR-01c, CR-06c, CR-07, CR-08, CR-34-email, plus the three real port adapters.

---

## 3. Reconciliation findings, address before new work

**F1. CR-05 was built before CR-04.** CR-05 references CR-04 four times: tagged helpers, counters, the Reviewer-only Done rule. Subtasks are running on an improvised role model. S03 migrates it onto the canonical model, it does not add a second one alongside.

**F2. CR-13, CR-15, CR-20 and CR-23 were placed before CR-32 defined the slots.** CR-32 says birthday, holiday eve, Wrapped, Big Deal and Victory Bell share one rotating Moments band. Two of those five are live as separate cards. S04 retrofits.

**F3. "Keep it plain" does not exist but CR-13 and CR-20 already ship confetti.** The toggle lives in CR-31, unbuilt. Pull it forward into S04.

**F4. Unknown: the audit log and the Date and Calendar Rules.** Neither is a numbered CR so neither is in your done list, yet CR-05 should already be enforcing locked due dates on subtasks. S00 verifies, S01 and S02 build.

---

## 4. Standing rules for the whole run

The generator must never stop to ask. These rules replace you.

1. **Never block.** If a decision is undecided, implement the safest reversible option, add it to `/OPEN.md` with the alternatives, and continue. Safest means: more restrictive permission, no destructive action, feature flag defaulted off.
2. **Never call out.** No network calls to Google, Track or any mail provider. Everything external goes through a port with a stub adapter.
3. **Never invent scope.** One CR per session. Adjacent CRs stay untouched even when they look like a two-line change.
4. **Never resolve a contract conflict.** If the CR text contradicts a frozen contract, implement nothing for that item, write the conflict to `/OPEN.md`, continue with the rest of the CR.
5. **Hard stops, no exceptions.** Do not run migrations against anything but the dev database. Do not enable the CR-19 auto-Done scheduler (build it, ship it flagged off). Do not write to attendance, timesheet or claim tables before S01 audit logging is live. Do not send a real email even if a provider config appears.
6. **Every session ends with a handoff file and a stop.** Do not chain into the next CR inside one session.

---

## 5. Contract files, frozen in Wave A

Every generator session reads all of these before touching code.

| File | Holds | Written in |
|---|---|---|
| `contracts/audit-log.md` | Append-only schema, actor, old to new, reason, source, Asia/Kuala_Lumpur, 7-year retention, no edit or delete by any role | S01 |
| `contracts/dates.md` | Locked work due dates, reschedulable Event dates, cancel-and-recreate, calendar snap-back | S02 |
| `contracts/roles.md` | Assigned (exactly one), Tagged with Helper and FYI, Creator, Reviewer (never equals Assigned). Counters, chips, labels | S03 |
| `contracts/dashboard-slots.md` | CR-32 slot map, band render conditions, column insertion points, Keep it plain text-only variants | S04 |
| `contracts/reactions.md` | The eight reactions, once per person per item, Send Help notifies nobody, Request Help is separate and creates a Helper tag | S05 |
| `contracts/recurring.md` | Frequency, pause, skip, end, holiday shift, idempotency, owner-as-role resolution | S06 |
| `contracts/ports.md` | CalendarPort, TrackPort, MailPort signatures, stub behaviour, what a real adapter must guarantee | S07 |
| `contracts/project-master.md` | Immutable code, version, effective date, variation record, approval states, field permissions | S09 |

---

## 6. Session sequence

`Exit` is the gate. Any failed exit item fails the whole session. No partial credit.

### Wave A: foundations

| # | Scope | Exit |
|---|---|---|
| S00 | Reconcile audit, read-only. Dump role schema, subtask model, dashboard component tree, audit-log presence, due-date mutability. Capture the dashboard baseline screenshot. Stand up Playwright. Generate seed fixtures: 3 months of cards, timesheets, attendance and clock records for award and panel testing | `findings.md`, baseline image committed, seed script reproducible, zero code changes |
| S01 | Global Clause audit log | Append-only table, write helper, an update attempt is rejected and that rejection is itself tested, retention config |
| S02 | Date and Calendar Rules engine | Task due date immutable via UI and API, Event date mutable, cancel-and-recreate flow, every change audited |
| S03 | CR-04 roles, migrate CR-05 onto them | Four roles, board query `Assigned OR Tagged OR Reviewer`, counters split, filter chips, subtasks reading one shared role model with no duplicate table |
| S04 | CR-32 slot map, retrofit CR-13 / 15 / 20 / 23, add Keep it plain | Plain staff dashboard on a quiet Tuesday is pixel-identical to the S00 baseline, bands render only when active, toggle silences all four shipped animations |
| S05 | CR-30 reactions, platform-wide | Picker replaced everywhere it exists today, retired reactions still render on old items, Send Help notifies nobody |
| S06 | CR-18 recurring engine, generic | Pause, skip and end with reason, public-holiday shift, running the job twice creates one occurrence, owner resolves to the current role holder |
| S07 | Ports and stub adapters | Three interfaces defined, three stubs writing to `port_outbox` plus a log, a fake adapter usable in tests, no code anywhere calls an external SDK directly |

### Wave B: core features

| # | Scope | Exit |
|---|---|---|
| S08 | CR-03 daily timesheet | Single-day submit, submitted day locked, Return for Correction with reason logs old to new, late flag after 10:00 next working day, leave and holidays auto-filled and never blocking, 3-day backdate limit |
| S09 | CR-06a master schema, versioning, effective dates | All fields, every change creates a version with who / when / old to new / reason, existing projects migrated as v1. Project code format uses a configurable pattern with a documented default, logged to OPEN |
| S10 | CR-06b variations, approvals, field permissions, closed lock | Contract value not editable in place, variation goes Awaiting approval, PM blocked from contract value, Finance blocked from PM field, closed project read-only until Director reopens with reason |
| S11 | CR-09 TOT session model | Session with chair, ordered slots, attendance with absentee reason, per-slot discussion, next-month agenda, single-title slots migrated |
| S12 | CR-10 TOT Tindakan to T.A.A. | Card created on save, Sasaran editable until first save then locked, status syncs both ways, previous-month block on the next session. Next TOT date computed internally, not from calendar |
| S13 | CR-11 Events, calendar leg via CalendarPort stub | Attendee cards created and removed with the attendee, post-event tabs unlock after end time, lessons searchable in Knowledge, Dashboard Events card in its slot, stub records the calendar intent in `port_outbox` |
| S14 | CR-21 Office Requests | Duplicate check and upvote, routing to the Admin team card, urgent notifies MN and Director in-app, reopen within 3 days, no auto-Done |
| S15 | CR-17 management panels, digest via MailPort stub | Role-scoped visibility, overdue grouped by assignee including subtasks and tagged, nudge limited to 1 per card per day and logged, reassign keeps the overdue record against the original owner |
| S16 | CR-34 internal half | Per-manager task at 8:00 AM via the recurring engine, each manager Primary Owner of their own card, overdue on the CR-17 panel after 5 PM, never counts toward any award, holiday shifts to Thursday. Email queued to the stub only |
| S17 | CR-14a awards computation and frozen snapshot | 11:59 PM month-end freeze, all 15 auto rules against the S00 seed data, max two per person with runner-up overflow, no consecutive repeat, system cards excluded |
| S18 | CR-14b awards UI, Nominate and Select, auto-tasks | Carousel in its band from the 1st to the 7th, one nomination per award per person, no self-nomination, auto-tasks created and auto-closed on submission |
| S19 | CR-19 auto-Done, calendar rows behind the port | Every non-calendar row of the CR-19 table, Auto badge and activity entry, excluded from overdue and awards, reopen behaves as manual, manual cards never auto-closed by date. Scheduler ships flagged off with a dry-run log |

### Wave C: culture pack

| # | Scope | Exit |
|---|---|---|
| S20 | CR-33 creative greeting | 60+ line bank EN and BM, priority Personal to Situation to Day to Time, no immediate repeat, plain fallback. Weather bucket built but skipped when no source is configured |
| S21 | CR-31 easter eggs | Once per day per user, never blocks an action, late-night message not judgemental and paired with the overtime shortcut |
| S22 | CR-24 Big Deal Alert | PM and above only, fixed type list, 3-day life then Wins page, client names hidden unless approved |
| S23 | CR-28 Victory Bell | Milestone-flagged cards only, max 3 per project per month, 24-hour life, respects Keep it plain |
| S24 | CR-25 Plot Twist poll | Vote stored without identity, hashed participation receipt not joinable to the choice, Friday 3 PM reveal, named-person opt-out before publication |
| S25 | CR-29 Friday sign-off | Same anonymity model, aggregate hidden under 5 responses, never on management dashboards, not exportable |
| S26 | CR-26 Side Quests | 2 to 3 live quests, self-declared completion, 30-day badge counting nowhere |
| S27 | CR-27 Mystery Award | Category hidden until publish, excluded from Hall of Fame and streaks, no consecutive repeat |
| S28 | CR-22 Wrapped | Same frozen snapshot as awards so numbers match, private by default, no comment-text analysis, no comparison between people, plain text under Keep it plain |

---

## 7. Generator prompt template

```
You are implementing exactly one change request for Amanahku, inside a
long-running autonomous build. Shazwan is not available. You may not ask
a question, wait for input, or stop early.

READ FIRST, IN ORDER:
  /RULES.md                        (standing rules, section 4)
  /contracts/*.md                  (all of them, frozen)
  /spec/<CR-ID>.md
  /spec/date-calendar-rules.md
  /spec/global-clause.md
  /sessions/<prev>/handoff.md
  /OPEN.md                         (decisions taken without Shazwan so far)
  /tests/acceptance/<CR-ID>.spec.ts

NON-NEGOTIABLE:
  1. Every state change listed in the Global Clause writes an audit entry.
  2. Work-item due dates are immutable after first save, in UI and API.
  3. Roles come from contracts/roles.md. Never a second role model.
  4. Dashboard work goes into the slots in contracts/dashboard-slots.md.
     Never build a new dashboard. Never move or rename an existing card.
  5. Anything external goes through a port from contracts/ports.md.
     No SDK import, no HTTP call to a third party, no real email.
  6. Undecided detail: implement the safest reversible option, append it
     to /OPEN.md with the alternatives, keep going.
  7. CR text contradicts a contract: skip that item, log the conflict to
     /OPEN.md, deliver the rest of the CR.

BEFORE CODE:
  Write /sessions/<id>/contract.md: files you will touch, schema changes,
  and how each numbered acceptance item will be verified.

AT THE END:
  Run the acceptance tests, write /sessions/<id>/handoff.md, then STOP.
  Do not begin the next CR.
```

## 8. Evaluator prompt template

```
You are QA for Amanahku. You did not write this code and you are not here
to be encouraging.

Drive the running application with Playwright, as a user. Do not review
the diff. "The code looks correct" is not evidence that a behaviour works.
Click it.

For each numbered item in the Acceptance section of /spec/<CR-ID>.md:
  - Reproduce it end to end in the browser or against the API.
  - Record PASS or FAIL with the exact file and line where it breaks.
  - Partially working is FAIL.

Run these every session regardless of the CR:
  - Change a Task due date via the API. Must be rejected.
  - Edit an audit-log row. Must be rejected.
  - Load the Dashboard as a plain staff user on a day with nothing active.
    Must match /baseline/dashboard.png.
  - Toggle "Keep it plain". No animation, no cheeky text, anywhere.
  - Grep the diff for direct external SDK or HTTP usage. Any hit is FAIL.
  - Confirm every new OPEN.md entry names the alternatives not taken.

You may not pass a session with any FAIL. Do not soften a finding because
it seems minor or because the rest of the work is good. Write failures as
a list the generator can act on without further investigation.
```

## 9. Handoff template

```markdown
# Session <id> handoff: <CR-ID>

## Delivered
- <behaviour>, verified by acceptance item <n>

## Schema changes
- <table>: <columns>, migration <file>

## Contracts touched
- <file>: <what and why>   (or: none)

## Port calls stubbed
- <port>.<method>, <n> rows in port_outbox, real adapter still owed

## Deferred
- <thing> to <session>, reason

## OPEN, decided without Shazwan
- <decision>, alternatives, how to reverse it

## Traps for the next session
- <what will bite whoever touches this next>
```

---

## 10. Review by hand when you are back

| Area | Why |
|---|---|
| Everything in `/OPEN.md` | This is the whole cost of running unattended. Read it before you read the code |
| `port_outbox` contents | Shows exactly what the app would have sent to Google, Track and mail. Wrong intent here means the real adapters will be wrong |
| CR-25 and CR-29 anonymity | The hashed receipt must not be joinable to the answer. Timestamp correlation, row order and small response counts all leak identity. Read the schema and the query yourself |
| CR-14 award rules | Fifteen rules with interacting caps. Hand-check one month of real data against the computed result |
| CR-19 scheduler | Ships flagged off. Read one week of dry-run log before enabling. It closes real work every 15 minutes |
| CR-03 and CR-06b permissions | Payroll and contract-value adjacent. Verify the field-level blocks by attempting them as each role |

## 11. Send Yati these while the run is going

| CR | Question |
|---|---|
| CR-02 | Read-only, or approval rights too |
| CR-06 | Mandatory vs optional fields, and the project-code format agreed with Finance |
| CR-09 | Does TOT attendance feed the Attendance module for Saturday work |
| CR-13 | Weekend or holiday birthday: show again on the next working day |
| CR-16 | BM label for The Playground |
| CR-18 | Minimum attendance percentage satisfying the social-activity Done rule |
| CR-19 | Track WBS-linked card at 100 percent, auto-Done or not |
| CR-14 | Does any award carry a tangible reward |
