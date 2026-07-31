# Changelog — Amanahku

## Unreleased — Governed baseline import
- Imported the existing Amanahku application (Laravel 13 HR platform) on top of the
  `ursb-project-template` skeleton already present on `main`.
- Merged the root `CLAUDE.md`: URSB governance section first, then the existing local-dev,
  deploy and Laravel Boost guidance.
- `README.md` keeps the application setup guide and gains a Governance section mapping the
  URSB artefacts.
- `.editorconfig` kept at the Laravel/PSR-12 setting (4-space) instead of the template's 2-space
  default; YAML stays at 2.
- `.gitignore` narrowed from `.claude` to `.claude/*` with `!.claude/commands/` so the five URSB
  governance commands stay tracked.
- Filled `PROJECT.md` and `STATE.md` from the project's real context; `REQUIREMENTS.md` still
  awaits an approved BRS/URS.
- `CODEOWNERS` populated with provisional handles — confirm before enabling code-owner approval.

---

## Template lineage

### 0.2.0-draft — Pilot Baseline (`ursb-project-template`)
- Added `.gitlab/issue_templates/Engineering-Change.md` — working issue template carrying the
  change-classification checklist, risk indicators and required gates; references the central
  standard `engineering-standard/governance/change-classification-and-minimum-gates.md`.
- Added the standard GitLab label set (`.gitlab/labels.yml`) and `scripts/setup-labels.sh`,
  applied during `/ursb-project-bootstrap`.
- Five URSB governance commands wired into `.claude/commands/` with `allowed-tools` allow-lists.
- Baseline template structure: `docs/`, `.planning/`, `tests/`, `security/`, `DECISIONS/`,
  GSD context files, `CLAUDE.md`, `.gitlab-ci.yml`, `scripts/validate-project.sh`.
