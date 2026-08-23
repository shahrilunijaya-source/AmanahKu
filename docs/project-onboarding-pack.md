# SupportOS Project Onboarding Pack

Send Part A and Part B to the owner of the system being onboarded. Part A is
the information they fill in and return; Part B is the integration contract
their developers implement. Part C is the checklist the SupportOS operator
runs once Part A comes back. Part D states what SupportOS will and will not
do — share it with stakeholders so expectations are set correctly.

Replace `https://<supportos-host>` with your SupportOS URL.
(Current demo installation: `http://localhost:8000`.)

---

## Part A — Information we need from your team

Copy this section, fill in the answers, and send it back.

### 1. Project basics

| Question | Your answer |
|---|---|
| System / product name | |
| One-paragraph description (what it does, who uses it) | |
| Technology stack (language, framework, database) | |
| Staging environment URL | |
| Production environment URL | |
| How is it deployed? (CI/CD tool, who runs it) | |

### 2. People

| Role | Name | Email |
|---|---|---|
| Business contact (receives updates, escalations) | | |
| **Developer in charge** — reviews and approves AI-proposed code fixes | | |
| Additional users who need portal access to view/report tickets | | |
Amanahku has publish it first 1.0 API, help me set it up so Track can get it Project data from Amanahku, and also back fill for projects in Track but not in Amanahku
Accounts are invitation-only; we send invitations to the emails above.

### 3. Source code access

SupportOS reads your code to power commit intelligence and code-grounded
repair proposals. Access is **read-only** — see Part D.

| Question | Your answer |
|---|---|
| Git repository HTTPS URL (github.com and gitlab.com are supported) | |
| Default branch (e.g. `main`) | |
| **Integration target branch** — the branch proposed fixes are drafted against. Must NOT be `main`, `master`, `production`, `prod`, or `release/*` (SupportOS refuses protected branches). A `staging` or `develop` branch is typical. | |
| For a private repository: a **read-only** access token. GitHub: fine-grained PAT, Contents → Read-only. GitLab: project access token, `read_repository` scope, Reporter/Developer role. | (send via a secret channel, not this document) |

The token is stored encrypted, attached per-request only, never written to
git config, and never appears in logs.

### 4. Support widget / ticket intake

| Question | Your answer |
|---|---|
| Domain(s) the support widget will run on (origin allowlist; leave empty if tickets are sent server-to-server) | |
| Release/version identifier you deploy with (e.g. `v2.4.1`, git SHA) — sent with each ticket so we know which version broke | |
| Do your error reports include stack traces or attachments? | |

---

## Part B — Integration contract (for your developers)

Two ways to send tickets to SupportOS. Both use the project's support token
(`pit_…`), which we issue during onboarding. **The token is a secret**: it is
shown once at creation, stored hashed, and can be rotated or revoked at any
time — keep it in your secret manager, never in source control.

### Option 1 — embed the widget

```html
<script src="https://<supportos-host>/widget/v1/supportos.js"
        data-token="pit_XXXXXXXXXXXXXXXX"
        data-release="v2.4.1"></script>
```

Gives your users an in-page "Report a problem" widget plus a "What's new"
tab fed by your project's published release notes.

### Option 2 — send tickets from your backend

```
POST https://<supportos-host>/ingest/v1/tickets
Content-Type: application/json
X-SupportOS-Token: pit_XXXXXXXXXXXXXXXX      (or: Authorization: Bearer pit_…)
```

| Field | Required | Notes |
|---|---|---|
| `message` | **yes** | The report text. Max 10,000 chars. Name the screen/file/button if known — specific reports get better AI fixes. |
| `type` | no | `bug` (default) or `idea` |
| `subject` | no | Falls back to the first line of `message` |
| `url` | no | Page the problem happened on |
| `user_agent` | no | Browser/user agent string |
| `error` | no | Stack trace / error text |
| `context` | no | Any JSON object (app state, user id, etc.) |
| `release` | no | Your deployed version identifier |
| `attachments[]` | no | Multipart only. Max 3 files, 10 MB each: jpg, jpeg, png, gif, webp, pdf, txt, log |

Responses:

| Status | Body | Meaning |
|---|---|---|
| `201` | `{"reference":"TKT-XXXXXXXXXX"}` | Ticket created |
| `200` | `{"reference":"…","deduplicated":true}` | Same fingerprint as a recent ticket — no duplicate created |
| `401` | message | Missing/invalid/revoked token, or project inactive |
| `403` | message | Origin not on the allowlist, or widget disabled for the project |

Also available with the same token:

- `GET /status/v1/tickets/{reference}` — ticket status for "track my report"
- `GET /widget/v1/releases` — your published release notes (widget "What's new")
- `GET /widget/v1/meta` — widget boot metadata

Rate limit: 120 requests/minute per IP, plus a per-token bucket. Back off on
`429`.

### Worked example

```bash
curl -X POST https://<supportos-host>/ingest/v1/tickets \
  -H 'Content-Type: application/json' \
  -H 'X-SupportOS-Token: pit_XXXXXXXXXXXXXXXX' \
  -d '{
    "type": "bug",
    "subject": "Save button wrong colour on invoice form",
    "message": "The primary Save button on the invoice edit form renders grey instead of brand blue. File: resources/js/pages/InvoiceEdit.vue",
    "url": "https://app.example.com/invoices/123/edit",
    "release": "v2.4.1"
  }'
```

---

## Part C — SupportOS operator checklist (run when Part A returns)

1. **Create the client and project.** Clients → New (if a new tenant), then
   Projects → New with the Part A basics.
2. **Run the project onboarding wizard** on the project page:
   Basics → Connect code → Support intake → Team → Go live.
3. **Register the repository.** Repositories → New: HTTPS URL, default
   branch, target branch (the form rejects protected branches), and the
   read-only token for private repos. Commit sync runs hourly
   (`php artisan git:sync` to trigger immediately). For repair proposals the
   deployment needs `GIT_DRIVER=local` with a provisioned checkout — see
   `docs/guides/git-driver-enablement.md`.
4. **Enable the support widget** on the project and **issue the ingest
   token**. Copy the `pit_…` value at creation — it is shown exactly once.
   Set the origin allowlist from Part A §4.
5. **Invite the users** from Part A §2 with the right roles. The developer
   in charge needs helpdesk-update authority to approve repair proposals
   and project membership on this project.
6. **Publish a first release note** (project → Releases) so the widget's
   "What's new" tab isn't empty.
7. **Test end-to-end:** send the Part B curl with the real token → confirm
   `201` → confirm the ticket appears triaged (category + confidence) within
   a minute → confirm an agent run started (Agent activity page).
8. **Hand over** the widget snippet, the token (secret channel), and the
   Part B contract to the client team.

Prerequisites on the SupportOS deployment: queue worker + scheduler running
(`php artisan ops:health` must be green), `ANTHROPIC_API_KEY` set, and
`AI_MONTHLY_BUDGET_USD` set to a real cap.

---

## Part D — What SupportOS does and guarantees

**Does:**

- AI triage of every ticket (category, severity, priority, risk tier) with a
  confidence score; low-confidence results route to a human review queue,
  and a human's triage decision is never overwritten by AI.
- AI first-level response drafts and similar-issue lookup, grounded in your
  ticket history and knowledge base.
- Code-grounded repair proposals: the AI reads your code with **read-only**
  tools and produces a unified diff, test plan, and branch name for a human
  to review. Higher-risk tickets require more approvals or are analysis-only.
- Separation of duties: whoever proposed a fix can never approve it.
- Every AI call is metered and capped by a monthly budget.
- A tamper-evident audit trail on all state changes.

**Never (by recorded architecture decision, enforced in code at every risk
tier):**

- Deploys to production or mutates production data or schema.
- Merges its own changes — a human approves, and the merge happens in your
  git host under your rules.
- Reads or writes your secrets; repository access is read-only by
  construction.
- Acts past an approval gate: repair proposals, change requests, and
  first-level replies all wait for a named human.

Production deployment remains your CI/CD's job after your developer merges;
SupportOS records the deployment in its governance ledger (staging must be
verified before a production deploy can be recorded, and the deployer must
differ from the planner).
