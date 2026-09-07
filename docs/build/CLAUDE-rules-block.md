# Block to paste into CLAUDE.md during session S00

`CLAUDE.md` already exists at the repo root (stack, lerd commands, dev quick-login, deploy rules). Session S00 appends this block to it verbatim as a new section. It does not rewrite or remove what is already there.

`CLAUDE.md` is auto-loaded every session. Rules live here rather than only in a slash command, because a rule that depends on invoking the right command is a rule that gets skipped at 3am.

---

## Amanahku autonomous build, standing rules

This repository is being built by an unattended multi-session agent run. Shazwan is not available. Read `docs/build/RULES.md` in full before any work.

**Every session, without exception:**

- Read `docs/build/RULES.md`, all of `docs/build/contracts/`, the CR spec file, `docs/specs/global-clause.md`, `docs/specs/date-calendar-rules.md`, `docs/build/OPEN.md`, and the previous session's handoff.
- Never block on a question. Safest reversible option, log to `docs/build/OPEN.md`, continue.
- Never call an external service. Google, Track and mail go through ports in `docs/build/contracts/ports.md`.
- One CR per session. No adjacent work, no refactoring outside the CR's files.
- Never edit `CLAUDE.md`, `docs/build/RULES.md`, `docs/build/contracts/*` or `tests/Acceptance/*`. Those are input.
- Work-item due dates are immutable after first save, in UI and API. Event dates are not.
- Roles come from `docs/build/contracts/roles.md`. One role model only.
- Dashboard changes go into the slots in `docs/build/contracts/dashboard-slots.md`. Never build a new dashboard. Never move or rename an existing card.
- Every state change listed in `docs/specs/global-clause.md` writes an audit entry.
- End with `docs/build/sessions/<id>/handoff.md`, then stop.

**Hard stops:** no migrations outside dev, the CR-19 scheduler ships flagged off, nothing writes to attendance/timesheet/claim tables before S01, no real email or calendar or Track write, no audit-log row is ever deleted or rewritten.
