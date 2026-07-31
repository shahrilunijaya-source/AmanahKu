---
description: Review a technical/implementation plan against URSB standards and record the technical-plan gate.
disable-model-invocation: true   # suggest-only; a human types it (gate stays human-owned)
argument-hint: "<path to plan or phase>"
allowed-tools:
  - Read
  - Grep
  - Glob
  - Write
  - Edit
  - Bash(git status:*)
  - Bash(git diff:*)
  - Bash(git log:*)
  - Bash(git branch:*)
  - Bash(glab issue view:*)
  - Bash(glab issue list:*)
  - Bash(glab mr view:*)
  - Bash(glab mr list:*)
  - Bash(glab label list:*)
---

# /ursb-plan-review

Review a technical plan **before** any code is written. Serves the **technical plan** gate (SOP-06).

## Wraps
- `/gsd-plan-phase` — produces the plan being reviewed.
- `/plan-eng-review` — engineer's critique of the plan.
- URSB standards: `architecture/`, `development/`, `security/`.

## Steps
1. Read the plan (phase plan from `/gsd-plan-phase`, or `templates/implementation-plan-template.md`).
2. Run `/plan-eng-review` for the engineering critique.
3. Check the plan against `architecture/architecture-standard.md`, the `development/` standards,
   security-review triggers (`security/security-review-standard.md`),
   `requirements/acceptance-criteria-standard.md` and `quality/definition-of-done.md`.
4. Produce a review summary: strengths, gaps, required changes, and a **security flag (yes/no)**.
5. **Gate:** a Conventional Coder / Technical Lead approves and records it on the issue
   (label `technical-approved`).

## Must not
- Approve the gate yourself.
- Start building from an unapproved plan.

## Gate
**Conventional Coder / Technical Lead** approves the technical plan. Claude prepares; it does not approve.

## Output
A plan-review summary with a clear approve / changes-requested recommendation for the gate owner.
