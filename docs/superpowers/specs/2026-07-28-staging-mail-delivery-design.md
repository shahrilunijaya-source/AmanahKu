# Staging mail delivery

Closes **I-022**, **I-023** and **I-024** from [../../ROADMAP.md](../../ROADMAP.md), and
supersedes the deploy-side half of **I-009**.

Mail on staging has never worked. Nothing reported it because until 2026-07-26 no queue
worker existed to attempt a send. When the scheduler and queue crons were finally created,
the failures became visible: two `MemberInvited` jobs in `failed_jobs` and a 3.2 MB log of
one repeated exception.

## Why

Three defects stack on top of each other. All three must be fixed; each one alone still
leaves mail dead.

1. **The mailer config is invalid.** Staging sets `MAIL_SCHEME=tls`, which
   `config/mail.php:42` passes straight to Symfony Mailer. Symfony rejects it: the supported
   schemes for the `smtp` mailer are `smtp` and `smtps`. This throws before a connection is
   attempted, so credentials are irrelevant. `tls` was the value of the old `MAIL_ENCRYPTION`
   key that Laravel 11 replaced with `MAIL_SCHEME`; the value did not carry over.
2. **There are no credentials.** `MAIL_HOST=__smtp_host__` and `MAIL_USERNAME=__smtp_user__`
   are literal placeholders, and `MAIL_FROM_ADDRESS` is on `amanahku.example`, a reserved
   domain that receiving servers reject.
3. **Failures are invisible.** Nothing watches `failed_jobs`, and the log is a single file
   with no rotation. A mail outage stays silent until a human reads the log by hand, which
   is what took several weeks here.

### Blast radius while unfixed

Everything the app sends by mail fails silently: `MemberInvited` (workspace invites),
`WeeklyHrDigest` (the Monday digest), and all Fortify mail, meaning password reset and
email verification. A user who forgets their password has no self-service recovery path.

The in-app bell is **not** affected. The roughly 40 `AppNotification::send()` call sites
write rows to `app_notifications` and never touch the mailer. They work today.

## What the app actually sends

The whole email surface is three paths. Nothing else in the codebase sends mail.

| Path | Trigger | Queued |
|---|---|---|
| `App\Notifications\MemberInvited` | `MemberController@store`, `@resendInvite`, `SuperAdmin\CompanyController@store` | yes, `ShouldQueue` |
| `App\Notifications\WeeklyHrDigest` | `digest:weekly`, scheduled Monday 08:00 | yes, `ShouldQueue` |
| Fortify password reset and email verification | user action | framework default |

Both custom notifications implement `ShouldQueue` and `QUEUE_CONNECTION=database`, so
**two things must be alive for mail to arrive**: a queue worker draining `jobs`, and a
working mailer. The queue cron already exists as of 2026-07-26. This spec fixes the mailer
and makes the failure of either one visible.

---

## Part 1: sending identity

**Provider: Hostinger Business Email.** Not Resend.

Resend was evaluated first and rejected. The reasoning is recorded here because the
conclusion is not obvious and will be questioned again later.

| Factor | Finding |
|---|---|
| Volume needed | Invites are bursty during onboarding, digest is weekly to HR only, resets are a handful a day. A mass invite of an entire company is a few hundred emails at most. |
| Hostinger limit | 1,000 outbound per day per mailbox on Business Starter, rolling 24 hours. Comfortable margin. |
| Permitted use | Hostinger names password resets and account notifications as intended use. Bulk marketing and cold outreach are prohibited, and Amanahku does neither. |
| Cost | The Starter Business Email plan is already paid for and unused until 2027-04-21. Resend's free tier would also be $0. Cost does not separate them. |
| Setup | Hostinger writes MX and SPF automatically, but **not** DKIM: three CNAMEs by hand. Resend needs four records by hand. Both share the same failure mode around relative record names. Closer than assumed at decision time, and it does not change the outcome. |
| Outbound ports | Verified reachable from the staging host on 587 and 465 for both providers. Not a differentiator. |

At this volume, on a domain already owned, a second vendor with a second credential set
solves nothing the first vendor does not already solve.

**Identity: a dedicated subdomain.** Attach the idle Starter Business Email plan to
`amanahku.myappsonline.net` and create the mailbox `noreply@amanahku.myappsonline.net`.

The subdomain matters because sending reputation is per-domain, and `myappsonline.net` is
shared with at least `financeai` and `unijaya-track-staging`. Complaints against another app
on the root domain would otherwise degrade Amanahku's password reset delivery with no
visible cause.

**DNS records.** Attaching the plan creates MX and SPF by itself. DKIM and DMARC do not
appear on their own, and without DKIM hPanel warns that mail may be rejected or spam-filed.
Added by hand in the `myappsonline.net` zone:

| Type | Name | Value |
|---|---|---|
| CNAME | `hostingermail-a._domainkey.amanahku` | `hostingermail-a.dkim.mail.hostinger.com` |
| CNAME | `hostingermail-b._domainkey.amanahku` | `hostingermail-b.dkim.mail.hostinger.com` |
| CNAME | `hostingermail-c._domainkey.amanahku` | `hostingermail-c.dkim.mail.hostinger.com` |
| TXT | `_dmarc.amanahku` | `v=DMARC1; p=none` |

Two traps. The Name field is **relative to the zone root**, so typing the full
`...amanahku.myappsonline.net` produces a doubled suffix and verification silently never
completes. And all three DKIM selectors are needed: `-b` and `-c` publish empty keys today
and exist for rotation, so omitting them breaks signing later, not now.

`unijaya-track-staging.myappsonline.net` has no DKIM either. Unrelated to Amanahku, but the
same fix applies if that app's mail matters.

### Accepted trade-offs

Two things are knowingly given up.

**No delivery dashboard.** There is no per-message record of delivered, bounced or marked as
spam. When HR reports that an invited person received nothing, there is no way to separate
"in their spam folder" from "bounced" from "never sent".

Mitigation: make `noreply@` a **real mailbox, not a black hole**. Bounces are delivered back
to it. If nobody can sign in, that information is discarded.

**Credential coupling.** The SMTP password is a mailbox password in the server `.env`, not a
scoped API key that can be revoked on its own. Acceptable because nothing else uses that
mailbox.

Both become worth revisiting at real production volume across several tenants. Switching
then is a `.env` change, because nothing in the application is provider-specific.

### Known limitation, not solved here

A password reset arriving from `@amanahku.myappsonline.net` gives an employee no reason to
trust it. It reads like phishing, in exactly the kind of message that asks someone to click
a link and set a password.

This is a **domain problem, not a provider problem**. Resend would not have fixed it either.
The fix is buying a domain the organisation owns, which is a budget decision outside this
spec. The mailbox moves to it with a `.env` change when that happens.

## Part 2: configuration

**Getting mail sending needs no application code and no new dependency.** Resend and
Hostinger both speak SMTP, the app already uses the `smtp` transport, and the notifications
hand a `MailMessage` to whatever `MAIL_MAILER` points at. Parts 1 and 2 are configuration
only. The one code change in this spec is the console banner in Part 3c, which is about
visibility rather than delivery.

Server `.env` on staging:

```
MAIL_MAILER=smtp
MAIL_HOST=smtp.hostinger.com
MAIL_PORT=587
MAIL_USERNAME=noreply@amanahku.myappsonline.net
MAIL_PASSWORD=<mailbox password>
MAIL_SCHEME=smtp
MAIL_FROM_ADDRESS="noreply@amanahku.myappsonline.net"
MAIL_FROM_NAME="Amanahku"
```

**`MAIL_SCHEME` and `MAIL_PORT` are a pair.** Port 587 is STARTTLS and requires
`MAIL_SCHEME=smtp`. Port 465 is implicit TLS and requires `MAIL_SCHEME=smtps`. Changing one
without the other reproduces I-022 exactly. Omitting `MAIL_SCHEME` entirely also works,
because Symfony then infers the scheme from the port.

**Repository change:** `.env.staging.example` is updated to the same shape with placeholders
retained, since the repository is public. The `MAIL_SCHEME=tls` line is removed so that
copying the template cannot reproduce the original bug.

**The mailbox password never enters the repository.** It is set on the server only.

### Note on account ownership

The Hostinger account is being operated in impersonate mode as
`shahril.unijaya@gmail.com`. The mailbox and the email plan live in that account, not in a
personal one. Anyone planning credential rotation later needs that context.

## Part 3: recovery and visibility

### 3a. Drain the failed jobs

One-time operational step, after Part 2 is in place. Two `MemberInvited` jobs sit in
`failed_jobs`. Run `php artisan queue:retry all` and confirm the table empties and the mail
arrives.

This doubles as the acceptance test for the whole fix, because it exercises the exact path
that failed.

### 3b. Bound the log

`LOG_CHANNEL=stack` writes one file forever, which is how `laravel.log` reached 3.2 MB of
one repeated exception. The `daily` channel is already configured with 14-day retention at
`config/logging.php:72`.

Set `LOG_CHANNEL=daily` in the staging `.env` and in `.env.staging.example`, and truncate
the existing file once. No code.

### 3c. Surface failed jobs to the super admin

**The alert must not travel by email.** The condition being reported is that email is
broken, so any email-based alert is silent exactly when it is needed.

The in-app bell is also unavailable. `AppNotification` uses `BelongsToTenant`, which fails
closed and throws when a row is created with no active tenant
(`app/Models/Concerns/BelongsToTenant.php:39`). Super admins operate above any single
tenant, so there is no correct tenant to write the row under.

**Chosen approach: a banner on the super-admin provisioning console.** When `failed_jobs` is
not empty, `superadmin.companies.index` shows a warning with the count and the failing job
class.

- One count query added to `SuperAdmin\CompanyController@index`.
- One conditional block added to the `superadmin.companies.index` Blade view.

This is passive rather than a push, and that is an honest weakness. It was chosen because it
reuses a page the super admin already visits, adds no notification channel, and has no
tenant coupling. Alternatives considered and rejected: doing nothing, which preserves the
exact silence that caused this issue; and logging an error from a scheduled check, which
moves the silence into the log file that nobody read last time.

### Testing

3a and 3b are configuration and operations, with nothing to test programmatically.

3c is application code and gets one feature test:

- A super admin sees the banner when a row exists in `failed_jobs`.
- A super admin does not see the banner when the table is empty.

## Out of scope

- Any change to notification content or wording.
- Any new email the app does not already send.
- Automatic retry of failed jobs. Retry stays a manual, considered act.
- The roughly 40 `AppNotification` call sites. They never used mail and are unaffected.
- Local development mail, which already works. Verified during this design: a probe sent
  through the configured mailer arrived in Mailpit. Note that queued notifications need
  `php artisan queue:work` running locally, or `QUEUE_CONNECTION=sync`, or they sit in
  `jobs` and appear to have failed.
- Buying a production domain.

## Acceptance criteria

Outcome recorded 2026-07-28, after deploy `2e515d9`.

| # | Criterion | Result |
|---|---|---|
| 1 | `MAIL_SCHEME` is a scheme Symfony accepts and matches `MAIL_PORT` | met, `smtp` + 587 |
| 2 | A test mail from the staging host reaches a real external inbox | met, Gmail **inbox**, SPF/DKIM/DMARC all pass |
| 3 | `failed_jobs` is cleared | met by `queue:flush`, **not** `queue:retry`, see below |
| 4 | An end-to-end invite from the app arrives | **not tested**, see below |
| 5 | A password reset from the staging login page arrives | met, Fortify reset link delivered |
| 6 | `laravel.log` rotates daily instead of growing as one file | met, channel is `daily`, old 3.3 MB file truncated |
| 7 | Console warns when `failed_jobs` is non-empty, tests pass | met, 3 tests, 13 passing with the existing suite |
| 8 | `.env.staging.example` no longer contains `MAIL_SCHEME=tls` | met |

**Criterion 3 changed on purpose.** The spec assumed retrying the stranded jobs. There were
30, not the 2 recorded in I-024, and 26 were real invites to real Unijaya staff. The app is
not being shown to anyone yet, so they were flushed rather than sent. Their signed links
would have expired 2026-08-02 in any case.

**Criterion 5 was closed the same day**, by requesting a reset for a developer-owned
address from the staging login page. The link arrived. This is the stronger of the two
remaining checks: it exercises the whole app-level path, meaning Fortify's notification,
Blade mail rendering and signed-URL generation, not only the raw transport that criterion
2 covers.

**Criterion 4 stays deliberately unmet.** An invite has to go to a real person, and the
decision was to tell nobody about the app yet. What criterion 5 does not cover is
`MemberInvited` specifically: its own template, its 7-day `temporarySignedRoute`
activation link, and the fact that it is `ShouldQueue` while Fortify's reset is not. So
the queued path to a real mailbox is still unproven. Check it when the first real invite
goes out.

**Note on the HR-side reset button.** `MemberController@resetPassword` sends no email by
design: it mints a temp password and flashes it once on screen for HR to relay in person
(AK-SEC-10). It is not part of these criteria and its behaviour did not change here.
Worth knowing, because "HR reset a password and no email arrived" looks like a mail bug
and is not one.
