---
description: Stand up a new URSB project from ursb-project-template with the GSD baseline and governance wired in.
disable-model-invocation: true   # suggest-only; a human types it (gate stays human-owned)
argument-hint: "<project name>"
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
  - Bash(glab auth status:*)
  - Bash(glab repo view:*)
  - Bash(glab ci view:*)
  - Bash(bash scripts/validate-project.sh)
  - Bash(./scripts/validate-project.sh:*)
  - Bash(bash scripts/setup-labels.sh)
  - Bash(./scripts/setup-labels.sh:*)
  - Bash(glab label create:*)
---

# /ursb-project-bootstrap

Bootstrap a new URSB project: apply the rules from **ursb-engineering-standard** to a fresh repo
created from **ursb-project-template**. Serves the **project setup** step (SOP-02 / SOP-03).

## Wraps
- `ursb-project-template` — the standard project structure.
- `/gsd-map-codebase` — read-only, when bootstrapping onto an existing / inherited codebase.
- GSD new-project context gathering for `PROJECT.md`.
- `glab` setup (install + verify), per `ai-assisted-development/gitlab-cli-standard.md`.

## Steps
1. Create the project from `ursb-project-template` — confirm it contains:
   `CLAUDE.md`, `PROJECT.md` / `REQUIREMENTS.md` / `STATE.md`, `docs/` (incl. `docs/intake/`),
   `.planning/clarifications/`, `tests/`, `security/`, `DECISIONS/`, `.claude/commands/`,
   `.gitlab-ci.yml`, `CODEOWNERS`, `.editorconfig`, `env.example`.
2. Run `scripts/validate-project.sh` and fix any `MISS` before continuing.
3. Apply the standard GitLab issue labels: `scripts/setup-labels.sh` (canonical set in `.gitlab/labels.yml`).
4. If an existing codebase is provided, run `/gsd-map-codebase` (mapping only, no changes) and
   save the map under `docs/`.
5. Fill `PROJECT.md` (system, PM / Product Owner, Project Initialiser, repo, status: `baseline-draft`).
6. Ensure `glab` is installed and authenticated; confirm `.gitlab-ci.yml` includes the central
   `ursb-ci-templates` and that `main` will be protected with `CODEOWNERS`.

## Must not
- Commit client BRS/URS, secrets or client data into the repo (only the machine-readable
  `docs/intake/approved-requirements.md` belongs here).
- Begin building features — this is bootstrap only.
- Run state-changing `glab` commands without explicit human approval.

## Gate
**Project Initialiser** confirms the baseline and planning files are correct before
clarification (SOP-04) begins. Claude prepares; it does not approve.

## Output
A validated baseline project repo ready for the clarification and baseline phases.
