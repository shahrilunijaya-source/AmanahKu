# Google SSO, pre-shift clock nudges, remember-me verification

Date: 2026-08-02
Status: approved, ready to plan

Three independent pieces of work, brainstormed together because they were asked for
together. They share no code and can be built and merged in any order.

---

## 1. Google SSO

### What exists already

The app has hand-rolled OpenID Connect single sign-on, built before this spec:

- `app/Services/OidcClient.php` — authorization-code flow on the Laravel HTTP client. No
  Socialite, no external SSO package.
- `app/Http/Controllers/OidcController.php` — verifies `state`, exchanges the code, accepts
  only an email-verified claim, then signs the user in.
- `routes/web.php` — `oidc.redirect` and `oidc.callback`, guest-accessible.
- `resources/views/auth/login.blade.php` — an SSO button rendered only when
  `OidcClient::configured()` is true.
- `tests/Feature/OidcSsoTest.php` — existing coverage.

Google is a standard OIDC provider. The flow needs **no new SSO code**. This work is
configuration, one security gate, and a button label.

### The problem this must solve

`OidcController::resolveUser` creates a `User` row for any verified email the provider
returns. Against a private company identity provider that set is bounded. Against Google it
is every Gmail account in the world. The created account is tenant-less and role-less, so it
cannot reach any data, but unbounded row creation by an anonymous party is still a defect.

A domain allowlist closes it.

### Configuration

`config/services.php`, `oidc` block, gains two keys. `env.example` gains matching entries.

| Key | Env var | Default | Meaning |
|---|---|---|---|
| `allowed_domains` | `OIDC_ALLOWED_DOMAINS` | empty | Comma-separated email domains permitted to sign in. Empty means any domain, which preserves today's behaviour for a generic provider. |
| `label` | `OIDC_LABEL` | `SSO` | Provider name shown on the login button. |

Google's endpoint values, for the deployment notes:

```
OIDC_ISSUER=https://accounts.google.com
OIDC_AUTHORIZE_URL=https://accounts.google.com/o/oauth2/v2/auth
OIDC_TOKEN_URL=https://oauth2.googleapis.com/token
OIDC_USERINFO_URL=https://openidconnect.googleapis.com/v1/userinfo
OIDC_SCOPES=openid email profile
OIDC_LABEL=Google
OIDC_ALLOWED_DOMAINS=unijaya.com
```

`OIDC_CLIENT_ID`, `OIDC_CLIENT_SECRET` and `OIDC_REDIRECT_URL` come from the Google Cloud
console OAuth client. They are secrets and never enter a tracked file.

### Code changes

**`OidcClient::allowsEmail(string $email): bool`** — returns true when the allowlist is empty,
or when the portion of the address after the last `@`, lowercased, is in the list. Case and
surrounding whitespace in the config value are normalised.

**`OidcClient::redirectUrl()`** — when exactly one domain is allowed, add Google's
`hd=<domain>` parameter to the authorize URL. This filters the Google account chooser so a
staff member is not offered their personal Gmail.

> `hd` is a user-experience hint and nothing more. It is a request parameter, so a caller can
> remove or change it. It is **not** a security control. `allowsEmail`, checked server-side
> after the token exchange, is the gate.

**`OidcController::callback()`** — call `allowsEmail` immediately after `verifiedEmail` and
before `resolveUser`, so a rejected address never reaches the database. On rejection, redirect
to `/login` with an error naming the permitted domain.

**`resources/views/auth/login.blade.php`** — the button text reads `Sign in with {label}`. The
existing padlock icon stays. Google's official coloured G mark is not included; it is a small
addition if the business wants the standard Google button treatment.

### Tests

Extend `tests/Feature/OidcSsoTest.php`:

1. An email on an allowed domain signs in as it does today.
2. An email on a disallowed domain is rejected, **and the users table gains no row**. The row
   assertion is the point of the test, not the redirect.
3. An empty allowlist admits any verified email, proving the generic-provider path is intact.
4. `redirectUrl` carries `hd` for a single allowed domain and omits it for none or several.

### Out of scope

- Signing in with more than one provider at a time. The config holds one provider. Offering a
  customer identity provider and Google side by side needs a provider registry, a second pair
  of routes and a second button, and no one has asked for it.
- Proof Key for Code Exchange. The client is confidential and holds a secret, so the
  authorization-code flow is already sound without it.
- Reading the identity provider's discovery document. Google's endpoints are stable and are
  written into the environment directly.

---

## 2. Clock-in and clock-out nudges, five minutes before

### What exists already

`app/Console/Commands/AttendanceReminder.php` (`attendance:remind`) runs every fifteen minutes
between 06:00 and 22:00, registered in `bootstrap/app.php`. It sends two notifications, both
after the fact, both with a thirty-minute grace period:

- Nobody clocked in by thirty minutes past their start time.
- Still clocked in thirty minutes past their expected end.

`app/Attendance/ReminderTargets.php` decides who qualifies. It already skips weekends, tenant
public holidays, approved leave, staff with no login account, and staff whose site has no
configured hours.

### The change

Add a nudge that arrives **before** the shift boundary. Keep both existing late nudges.

`ReminderTargets` keeps `missingClockIn` and `missingClockOut` untouched and gains two
look-ahead siblings:

- **`dueToClockIn(Carbon $now, int $windowMinutes)`** — active staff, no clock-in recorded
  today, not on leave, not a weekend or public holiday, site has a configured start, and
  `now < shift_start <= now + window`.
- **`dueToClockOut(Carbon $now, int $windowMinutes)`** — today's records with a clock-in, no
  clock-out, an `expected_end` stamped at clock-in, and `now < expected_end <= now + window`.

Both reuse the existing non-working-day and on-leave helpers. `dueToClockOut` reads
`expected_end` off the record rather than re-resolving the schedule, matching what
`missingClockOut` already does, so a mid-day change to branch hours cannot move the bar
retroactively.

### Timing

Lead time is five minutes. The look-ahead window is **six** minutes against a five-minute
scheduler tick, so consecutive ticks overlap by one minute and clock drift between cron and
the shift boundary cannot drop a nudge into a gap. The overlap produces a duplicate candidate
on the following tick; the per-day dedupe key discards it.

`bootstrap/app.php`: `everyFifteenMinutes()` becomes `everyFiveMinutes()`. The 06:00–22:00
bound, `withoutOverlapping()` and the failure handler are unchanged.

The faster tick does not disturb the late nudges. They fire on the first tick past their due
moment and their dedupe keys swallow every tick after it, exactly as at the slower cadence.

### Notifications

Four dedupe keys per day, so the pre and late nudges never collapse into one another and
neither repeats:

| Nudge | Dedupe key | Email |
|---|---|---|
| Clock-in, five minutes before start | `attendance-in-soon-{day}` | no |
| Clock-in, thirty minutes late | `attendance-in-{day}` | yes |
| Clock-out, five minutes before end | `attendance-out-soon-{day}` | no |
| Clock-out, thirty minutes late | `attendance-out-{day}` | yes |

The pre-nudges are bell-only. An email saying a shift starts in five minutes arrives too late
to act on, and sending one would double every employee's daily mail from this feature for no
practical gain. The late nudges keep their email, because those are the ones worth an inbox.

Copy for the new notifications:

- Clock-in: "Your shift starts soon" / "Your shift starts in a few minutes. Open Attendance to
  clock in."
- Clock-out: "Your shift ends soon" / "Your shift ends in a few minutes. Remember to clock
  out."

The command keeps its per-tenant loop, its per-tenant try/catch, and clears the tenant context
at the end. `GRACE_MINUTES` and the fallback window stay, because the late path still uses
them. A new `LEAD_MINUTES` and `WINDOW_MINUTES` pair drives the look-ahead path.

### Tests

`tests/Feature/AttendanceReminderTargetsTest.php` gains cases for both new methods: inside the
window fires, before the window is silent, after the boundary has passed is silent, and each
existing exclusion (weekend, public holiday, approved leave, no user account, no configured
hours, already clocked in) still suppresses the pre-nudge.

`tests/Feature/AttendanceReminderCommandTest.php` gains: a pre-nudge and a late nudge for the
same person on the same day coexist as two separate notifications; a second tick inside the
overlap window creates no duplicate; the pre-nudge sends no mail while the late nudge does.

The existing assertions for the late nudges must keep passing unmodified. They are the
regression guard for this change.

### Known ceilings, unchanged by this work

- Overnight shifts, where the end time is earlier in the clock than the start, are not handled.
  The whole attendance subsystem shares that single-business-day assumption, including
  `ClockService::isLate`, `isEarly` and `minutesBetween`. Fixing it in `ReminderTargets` alone
  would not make night shifts work.
- There is no per-branch working-days column, so a Saturday roster is treated as a weekend and
  receives no nudge.

---

## 3. Remember me, verification

### The state of it

Every part looks correct on inspection:

- `resources/views/auth/login.blade.php` posts a `remember` checkbox, pre-checked.
- The `users` table has `rememberToken()` from the framework's first migration.
- `config/auth.php` uses the standard session guard with the Eloquent user provider.
- Fortify handles the login and reads the checkbox itself.

There is **no test covering it anywhere in the suite**. Reading the code proves nothing about
whether the cookie survives a lost session, which is the only behaviour a user cares about.

### The verification

A new `tests/Feature/RememberMeTest.php` with three cases:

1. **Login with remember ticked** sets a `remember_web_*` cookie on the response, and persists
   a non-null `remember_token` on the user row.
2. **The session is flushed, then an authenticated route is requested carrying only the
   remember cookie**, and the response is not a redirect to login. This case is the actual
   proof. The other two are supporting detail.
3. **Login without remember** sets no such cookie.

Case 2 must clear the session rather than merely starting a new request, otherwise the test
passes on the session alone and proves nothing.

### If it fails

A red test means a real defect, not a test to soften. Debug it to root cause and fix it before
the work is called done. Candidate causes worth checking first: middleware that rejects a
request recovered from a cookie, and any code path that clears `remember_token` on logout or
on a password change.

### Deliverable

The test file, committed, plus the run output pasted into the report. "It works" without the
output is not an answer.

---

## Files touched

| File | Piece |
|---|---|
| `config/services.php` | 1 |
| `env.example` | 1 |
| `app/Services/OidcClient.php` | 1 |
| `app/Http/Controllers/OidcController.php` | 1 |
| `resources/views/auth/login.blade.php` | 1 |
| `tests/Feature/OidcSsoTest.php` | 1 |
| `app/Attendance/ReminderTargets.php` | 2 |
| `app/Console/Commands/AttendanceReminder.php` | 2 |
| `bootstrap/app.php` | 2 |
| `tests/Feature/AttendanceReminderTargetsTest.php` | 2 |
| `tests/Feature/AttendanceReminderCommandTest.php` | 2 |
| `tests/Feature/RememberMeTest.php` | 3, new |

No migration. No new dependency.

## Success criteria

1. A Google account on the allowed domain signs in. An account outside it is refused and
   leaves no user row behind. Both are covered by a test.
2. A staff member receives a bell five minutes before their shift starts and five minutes
   before it ends, and still receives the existing late nudges. Covered by tests, and the old
   late-nudge assertions pass unmodified.
3. `RememberMeTest` runs green, with the output shown. If it runs red, the defect it found is
   fixed and the run is repeated.
4. `vendor/bin/pint --dirty` is clean and the affected test files pass.
