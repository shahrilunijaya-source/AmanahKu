# SupportOS Part A — AmanahKu

## 1. Project basics

| Question | Answer |
|---|---|
| System / product name | AmanahKu |
| One-paragraph description | Multi-tenant HR platform for Unijaya and its clients. Staff submit leave, claim and overtime requests; managers verify and management gives final approval. Also covers attendance and punch records, payroll-adjacent reporting, an internal work board, and a super-admin tenant console. Users are Unijaya staff (HR, managers, directors, employees) plus client tenants. |
| Technology stack | PHP 8.5, Laravel 13, MySQL 8, Redis, Blade + Alpine.js 3 + Tailwind CSS 4, Vite. Fortify auth, Sanctum. |
| Staging environment URL | https://amanahku-staging.myappsonline.net |
| Production environment URL | https://amanahku.unijaya.com |
| How is it deployed? | Staging: Hostinger shared hosting, manual `git pull origin staging && bash deploy.sh` over SSH, run by the developer. Production: DigitalOcean, released from GitLab by the Unijaya devops team. Assets are built locally and `public/build` is committed; neither host builds assets. |

## 2. People

| Role | Name | Email |
|---|---|---|
| Business contact | *(TBC)* | *(TBC)* |
| Developer in charge | Shazwan | shazwanshah.unijaya@gmail.com |
| Additional portal users | *(TBC — HR lead, project manager)* | *(TBC)* |

## 3. Source code access

| Question | Answer |
|---|---|
| Git repository HTTPS URL | https://github.com/shahrilunijaya-source/AmanahKu.git (public; the GitLab mirror `https://gitlab.com/developer-unijaya/claudecode/amanahku.git` is devops-owned and shares the same history) |
| Default branch | `main` |
| Integration target branch | `dev` |
| Read-only access token | Not needed — repository is public. |

Confirmed expectation, per Part D: SupportOS never merges and never pushes to our
branches. A repair proposal arrives as a diff plus a branch name; the developer in
charge opens the pull request into `dev` and merges it under our own rules. Direct
writes to `dev` are not acceptable — we commit to `dev` daily and it is a shared
working branch.

Why `dev` and not `staging`: the release order is dev → staging → PR staging into main.
`dev` is the only branch where new work legitimately starts, so a proposed fix drafted
there flows through the existing gate (deploy to staging, test, then PR into main).
Drafting against `staging` would put unreviewed code one `git pull` away from the
staging host.

## 4. Support widget / ticket intake

| Question | Answer |
|---|---|
| Widget origins | `amanahku.unijaya.com`, `amanahku-staging.myappsonline.net` |
| Release/version identifier | App version, `MAJOR.MINOR` semver tracked in the changelog against GitLab `main` (baseline `1.0` = 2026-08-02 launch). Git SHA can be sent alongside if SupportOS prefers an exact commit. |
| Stack traces / attachments? | Yes. Laravel exceptions carry full stack traces; screenshots are the normal way users report UI problems, so attachments will be used. Both are subject to the privacy requirement below. |

### 4a. Privacy requirement — no employee data leaves AmanahKu

AmanahKu is an HR system. It holds identity-card numbers, bank details, salaries,
statutory contributions (EPF, SOCSO, EIS, PCB) and payslips. None of that is needed
to diagnose a bug and none of it may reach SupportOS. Tickets carry code-level and
UI-level information only: which page, which file, which component, what the user did,
what the error said.

**What we control on our side.** Our backend integration will send only:
route name, controller and view path, the exception class and stack trace, the app
version, and a non-identifying user reference (role and internal id, never name,
email or IC number). No model rows go into `context`. The models that must never be
serialised into a ticket are `Payslip`, `Employee`, `SalaryStructure`, `StatutoryRate`,
along with the screens that render them: `profile`, `directory`, `position`,
`staff-load` and `timesheet-reports`.

**Question for SupportOS.** Does the widget capture a screenshot of the page by
itself, or does it only upload files the user picks?

- If it auto-captures: we need a redaction hook — a CSS class or `data-` attribute
  the widget blanks out before the image is sent. We will mark every salary, IC and
  bank field in AmanahKu with it. Without such a hook, we cannot enable auto-capture
  on production.
- If it only uploads what the user picks: no code change is needed, and we handle it
  as staff guidance — do not attach a payslip or payroll screen; crop to the broken
  part of the page.

Either way, please confirm how long attachments are retained and who inside SupportOS
can view them.

### 4b. Rollout

Trial runs on staging first — staging carries no real employee data and the developer
can deploy there directly. Production rollout is not blocked: devops already have a
GitLab pipeline that deploys production, so the widget script tag reaches production
through the normal release path (dev, then staging, then main, then GitLab). It ships
once the privacy question above is answered.
