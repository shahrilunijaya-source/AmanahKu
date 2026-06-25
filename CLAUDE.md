# Project Governance — Claude Code

This project runs under the **URSB Engineering Standard** (`ursb-engineering-standard`).

## Golden rule
Claude Code may **prepare** evidence, drafts, plans, code and tests. It may **never**:
- approve any gate,
- merge to a protected branch,
- run `glab issue create` / `glab issue close` or any state-changing `glab` command,
without explicit human approval.

## Approval gates (one human owner each)
Requirement & scope → PM / Product Owner · Engineering proposal → PM + coder pair ·
Technical plan → Conventional Coder / Tech Lead · Security → Security reviewer ·
Code review → Conventional Coder / Code Owner · QA → QA Owner · UAT → PM / Client ·
Production release → DevOps / Platform Lead.

## Approved AI toolkit
- **GSD:** `/gsd-map-codebase` (read-only baseline), `/gsd-plan-phase`, `/gsd-execute-phase`, `/gsd-verify-work`.
- **Superpowers:** brainstorming, writing-plans, test-driven-development, systematic-debugging,
  requesting-code-review, verification-before-completion.
- **URSB governance commands** (`.claude/commands/`): `/ursb-project-bootstrap`,
  `/ursb-engineering-change`, `/ursb-plan-review`, `/ursb-qa-report`, `/ursb-release-gate`.
  These set `disable-model-invocation: true` — Claude may **suggest** them but a human must type
  them; they never auto-run, so the gate stays human-owned.
- **MCP (read-only / non-prod):** context7 (docs), playwright (verify/QA), Google Drive/Gmail/Calendar (office layer).

## Data handling
No Restricted data (credentials, client PII, prod data) is ever sent to AI tooling.
Confidential BRS/URS stays local. See `ursb-engineering-standard/security/data-classification.md`.

## Build one approved phase at a time
Vibe Coders build only the currently approved phase. Wait for the baseline and the plan gate.
