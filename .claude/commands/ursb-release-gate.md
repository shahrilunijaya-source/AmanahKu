---
description: Assemble the production release package (Definition of Done + all prior-gate evidence) for the release owner.
disable-model-invocation: true   # suggest-only; a human types it (gate stays human-owned)
argument-hint: "<release / tag>"
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
  - Bash(glab release list:*)
  - Bash(glab release view:*)
  - Bash(git tag -l:*)
---

# /ursb-release-gate

Assemble the **production release** package and stop at the release gate. Serves the
**production release** gate (SOP-09). This is the final gate — handle with extra care.

## Wraps
- `quality/definition-of-done.md` — the completion bar for the release.
- All prior-gate evidence: technical-plan approval, code review, security review,
  QA sign-off (`/ursb-qa-report`) and UAT acceptance.
- URSB templates: `templates/release-checklist.md`, and `delivery/rollback-standard.md`.
- `ursb-ci-templates` `ci-release.yml` — the **manual** production job.

## Steps
1. Verify Definition of Done is met for everything in scope.
2. Collect and link prior-gate evidence on the issue / MR:
   - technical plan approved (`technical-approved`)
   - code review approved (Conventional Coder / Code Owner)
   - security review complete or justified N/A
   - QA signed off (`qa-approved`, from `/ursb-qa-report`)
   - UAT accepted by PM / Product Owner (or Client)
3. Complete `release-checklist.md`; confirm CHANGELOG / release notes are updated.
4. Confirm a **rollback plan** exists and is current (`delivery/rollback-standard.md`).
5. Confirm the central release pipeline is green; identify the manual `release-prod` job in
   `ci-release.yml` (do **not** trigger it).
6. **Stop at the gate.** Present the assembled package and the exact manual step the release
   owner must take. Do not deploy.

## Must not
- Trigger the production deploy, run the manual `release-prod` job, or merge/tag for release.
- Mark the release gate passed.
- Release without a current rollback plan or with open blocker/critical defects.

## Gate
**DevOps / Platform Lead (Release Owner)** approves and performs the production release.
Claude assembles the package; it does not release.

## Output
A complete, checklist-backed release package with linked prior-gate evidence and a confirmed
rollback plan, ready for the release owner to action.
