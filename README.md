# URSB Project Template

Standard starting structure for a new URSB project. A project repo is created from this
template by `/ursb-project-bootstrap`. It applies the rules defined in
**ursb-engineering-standard** and includes the **ursb-ci-templates** pipeline.

**No client data is committed here.** Approved BRS/URS and approval records live in
Google Drive; only the machine-readable approved requirement copy lives at
`docs/intake/approved-requirements.md` inside the project repo.

## What this template provides
- Repo structure with `docs/`, `.planning/`, `.claude/`, `tests/`, `security/`, `DECISIONS/`
- `.gitlab-ci.yml` stub that includes the central `ursb-ci-templates`
- Security baseline (`security/README.md`) and `CODEOWNERS` stub
- `env.example`, `.editorconfig`, `LICENSE`
- `scripts/validate-project.sh` to check the project is correctly wired
- Governance `CLAUDE.md` and the four URSB governance commands in `.claude/commands/`
- GSD project context files: `PROJECT.md`, `STATE.md`, `REQUIREMENTS.md`

## Change governance
Raise changes with `.gitlab/issue_templates/Engineering-Change.md`, which carries the
change-classification checklist and required gates and references the master standard
`ursb-engineering-standard/governance/change-classification-and-minimum-gates.md`.

## First steps
1. Install the toolchain once: `scripts/setup-dev.sh` (bash) or `scripts/setup-dev.ps1` (Windows).
2. Run `scripts/validate-project.sh`.
3. Place approved requirements at `docs/intake/approved-requirements.md`.
4. Start GSD (`/gsd-map-codebase` for inherited code; otherwise initialise new-project).
