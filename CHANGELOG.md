# Changelog — ursb-project-template

## 0.2.0-draft — Pilot Baseline
- Added `.gitlab/issue_templates/Engineering-Change.md` — working issue template carrying the
  change-classification checklist, risk indicators and required gates; references the central
  standard `engineering-standard/governance/change-classification-and-minimum-gates.md`.
- Added the standard GitLab label set (`.gitlab/labels.yml`) and `scripts/setup-labels.sh`,
  applied during `/ursb-project-bootstrap`.
- Five URSB governance commands wired into `.claude/commands/` with `allowed-tools` allow-lists.
- Baseline template structure: `docs/`, `.planning/`, `tests/`, `security/`, `DECISIONS/`,
  GSD context files, `CLAUDE.md`, `.gitlab-ci.yml`, `scripts/validate-project.sh`.

_Platform version remains 0.2.0-draft (pilot-baseline refinement; no version bump)._
