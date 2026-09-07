# Standing Rules for the Amanahku Autonomous Build

Read in full at the start of every session. These rules replace Shazwan, who is not available during the run.

## The six rules

1. **Never block.** If a decision is undecided, implement the safest reversible option, append it to `docs/build/OPEN.md` with the alternatives and how to reverse it, and continue. Safest means: the more restrictive permission, no destructive action, feature flag defaulted off.

2. **Never call out.** No network calls to Google, Track, or any mail provider. Everything external goes through a port defined in `docs/build/contracts/ports.md` with its stub adapter. No third-party SDK import, no outbound HTTP, no real email, ever, even if a credential appears in the environment.

3. **Never invent scope.** One CR per session. Adjacent CRs stay untouched even when they look like a two-line change. No refactoring outside the files this CR touches.

4. **Never resolve a contract conflict.** If the CR text contradicts a frozen contract in `docs/build/contracts/`, implement nothing for that item, write the conflict to `docs/build/OPEN.md`, deliver the rest of the CR.

5. **Rules are input, never output.** The generator never edits `CLAUDE.md`, `RULES.md`, anything in `docs/build/contracts/`, or anything in `tests/Acceptance/`. A session that wants a contract changed writes the request into its handoff and stops. A session that thinks an acceptance test is wrong writes that into its handoff and stops. It does not edit the test it is graded against.

6. **Every session ends with a handoff and a stop.** Write `docs/build/sessions/<id>/handoff.md`, then stop. Do not chain into the next CR.

## Hard stops, no exceptions

- Do not run migrations against anything but the dev database.
- Do not enable the CR-19 auto-Done scheduler. Build it, ship it flagged off, with a dry-run log.
- Do not write to attendance, timesheet or claim tables before session S01 audit logging is live.
- Do not send a real email, calendar invite, or Track write.
- Do not delete or rewrite an audit-log row under any circumstance, including in a migration.
- Do not remove, rename or reorder an existing dashboard card. CR-32 is additive only.

## Non-negotiables in every session

1. Every state change listed in `docs/specs/global-clause.md` writes an audit entry.
2. Work-item due dates are immutable after first save, in UI and in API. Event dates are not.
3. Roles come from `docs/build/contracts/roles.md`. Never introduce a second role model.
4. Dashboard work goes into the slots in `docs/build/contracts/dashboard-slots.md`. Never build a new dashboard.
5. Anything external goes through a port. See rule 2.

## Session order

Do not reorder. Each session's exit criteria are the next session's assumptions.

```
Wave A   S00 reconcile audit (no code)     S01 audit log
         S02 date rules                    S03 CR-04 roles + migrate CR-05
         S04 CR-32 slots + retrofit        S05 CR-30 reactions
         S06 CR-18 recurring engine        S07 ports and stubs

Wave B   S08 CR-03 timesheet               S09 CR-06a master schema
         S10 CR-06b variations             S11 CR-09 TOT sessions
         S12 CR-10 TOT Tindakan            S13 CR-11 Events
         S14 CR-21 Office Requests         S15 CR-17 mgmt panels
         S16 CR-34 internal half           S17 CR-14a awards compute
         S18 CR-14b awards UI              S19 CR-19 auto-Done

Wave C   S20 CR-33 greeting                S21 CR-31 easter eggs
         S22 CR-24 Big Deal                S23 CR-28 Victory Bell
         S24 CR-25 Plot Twist              S25 CR-29 Friday sign-off
         S26 CR-26 Side Quests             S27 CR-27 Mystery Award
         S28 CR-22 Wrapped
```

## Deferred, not in this run

CR-01 (all three parts), CR-06c Track pull, CR-07, CR-08, the CR-34 email and meeting pack, the CR-19 calendar row, the CR-17 digest email. Each needs a credential or an external account that only Shazwan can provide. Build against the port, record the intent in `port_outbox`, move on.
