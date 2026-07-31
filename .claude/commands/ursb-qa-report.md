---
description: Produce the URSB QA evidence report for a change, ready for QA sign-off.
disable-model-invocation: true   # suggest-only; a human types it (gate stays human-owned)
argument-hint: "<issue/MR or change>"
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
  - Bash(glab ci view:*)
  - Bash(glab ci status:*)
---

# /ursb-qa-report

Assemble the QA evidence report. Serves the **QA sign-off** gate (SOP-08).

## Wraps
- `/gsd-verify-work` — verification that the phase met its goal.
- `superpowers:verification-before-completion` — completion proof.
- `/testgen` results and `quality/test-evidence-standard.md`.
- URSB template: `templates/qa-report-template.md`.

## Steps
1. Gather evidence: automated test results and coverage, verification output (`/gsd-verify-work`),
   relevant logs / screenshots.
2. Map each acceptance criterion (`requirements/acceptance-criteria-standard.md`) to its test(s)
   and result.
3. List open defects with severity (`quality/defect-management.md`).
4. Fill `qa-report-template.md`: scope, results table, coverage, defects.
5. Attach to the issue / MR and store under `tests/`.
6. **Gate:** the QA / Test Owner signs off (label `qa-approved`).

## Must not
- Sign off QA yourself.
- Claim coverage without the evidence attached.

## Gate
**QA / Test Owner** signs off. Claude prepares evidence; it does not sign off.

## Output
A complete QA report attached to the issue / MR, ready for human sign-off.
