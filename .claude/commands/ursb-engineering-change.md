---
description: Create or update a URSB engineering change proposal, then prepare the GitLab issue (human-approved).
disable-model-invocation: true   # suggest-only; a human types it (gate stays human-owned)
argument-hint: "<short description of the change>"
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

# /ursb-engineering-change

Create or update an **engineering change proposal** under URSB governance. Serves the
**engineering proposal** gate (SOP-05).

## Wraps
- `superpowers:brainstorming` — clarify intent and business outcome.
- `superpowers:writing-plans` — structure scope and approach.
- URSB template: `templates/engineering-proposal-template.md`.
- `glab` — prepare (not publish) the GitLab issue.

## Steps
1. If the change is unclear, run `superpowers:brainstorming` to pin down intent, scope and the
   business outcome.
2. Draft `DECISIONS/change-proposals/<slug>.md` from the engineering-proposal template
   (business outcome, scope in/out, acceptance criteria, approach, impact, phases).
3. Map the change to requirement IDs in `REQUIREMENTS.md` for traceability.
4. Prepare a GitLab issue **draft**: title, description from the proposal, labels
   `decision-required` plus the relevant `*-decision`, assignee = PM / Product Owner. Write it
   under `.planning/clarifications/` or show it inline.
5. **Stop at the gate.** Present the proposal and the proposed `glab issue create` command;
   do not run it until the PM + coder pair approve.

## Must not
- Run `glab issue create` or change approved scope without human approval.
- Commit client data.

## Gate
**PM / Product Owner + coder pair** approve the proposal. Claude prepares; it does not approve.

## Output
`DECISIONS/change-proposals/<slug>.md` plus a ready-to-approve GitLab issue draft.
