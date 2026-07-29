# HR password reset also emails a link

Follow-up to [2026-07-28-staging-mail-delivery-design.md](2026-07-28-staging-mail-delivery-design.md),
which made mail work on staging for the first time.

## Why

`MemberController@resetPassword` sends no email. It mints a one-time password and flashes
it on screen for HR to relay in person. Its docblock states the reason plainly: this is the
deliberate exception to the never-echo-a-credential rule (AK-SEC-10), *"for the case where
an employee has forgotten their password and email is unreliable."*

Email **was** unreliable. It had never delivered a single message. The design was correct
for the world it was written in.

That world ended on 2026-07-28. Self-service password reset now works end to end, verified
by a real reset link arriving in a real inbox. The justification for a mail-free reset has
expired, so the behaviour should catch up.

This does not remove the on-screen reveal. HR keeps it. The email is added alongside.

## Behaviour

Unchanged on click:

1. A fresh 12-character password is minted and hashed
2. `password_change_required` is set
3. `AuditLog::record('Reset password', ...)` fires, without the credential
4. The plaintext is flashed once to the acting HR user

Added: the user is also sent a standard Laravel password-reset link.

The flash gains a second line reporting what happened to that email:

| Broker result | HR sees |
|---|---|
| `RESET_LINK_SENT` | "A reset link was also emailed to `<address>`." |
| `RESET_THROTTLED` | "A reset link was emailed recently, so another was not sent." |
| exception, or any other status | "The reset email could not be sent. Give them the password directly." |

There is no "user has no email" case. `users.email` is `NOT NULL UNIQUE` and doubles as the
login username, so a User row always has an address. Handling that branch would be dead
code. (The `no_email` guard in `createLogin` is different: it runs against an **Employee**
directory record before any User exists.)

## The rule that drives the implementation

**The temp password must reach HR even if the email fails.**

Order matters: rotate first, then attempt the send inside `try/catch`, then return the
flash. A mail failure must never propagate out of this action.

If it did, HR would get an error page **after** the password had already rotated. The flash
would be lost, so nobody would know the new password, the old one would no longer work, and
the user would be locked out with HR unable to help.

This inverts the usual instinct that exceptions should bubble. Here the credential reveal is
the critical path and the email is best-effort. The email failing is an inconvenience; the
flash failing is a lockout.

## Mechanism

Reuse Laravel's password broker, the same path behind the login page's "Forgot password"
link that was verified working on 2026-07-28:

```php
Password::broker()->sendResetLink(['email' => $user->email]);
```

No new notification, no new mail template, no new route, no new dependency.

**Link lifetime is 60 minutes** (`config/auth.php:99`), and the broker throttles repeats to
one per 60 seconds. Sixty minutes is shorter than the 7-day `MemberInvited` activation link,
and that is fine: if it expires, the user can recover through self-service reset, which now
works. The temp password also remains valid regardless.

**Sent synchronously, not queued.** HR needs a truthful answer immediately about whether to
read the password out loud. A queued send would report "probably fine" and fail later in a
table. This is the opposite of the choice made for `MemberInvited`, which is queued because
nobody is waiting on it.

The cost is that HR's request now waits on an SMTP round trip, roughly one to three seconds
against `smtp.hostinger.com`. Acceptable for an action taken a few times a week.

## Testing

Four feature tests:

1. A reset sends the link email to the user's address
2. The temp password is still flashed when the email succeeds
3. **The temp password is still flashed when the mailer throws.** This is the lockout guard
   and the reason the feature is worth testing at all
4. Existing behaviour intact: password rotated, `password_change_required` set, plaintext
   absent from the audit log

Test 3 drives the `try/catch`. Without it the catch block is untested and can rot.

## Out of scope

- Changing the reset link expiry or the throttle window
- Queuing the send
- Telling the user in the email that HR initiated the reset rather than themselves
- Any change to `MemberInvited` or the invite flow
- Removing or gating the on-screen password reveal. It stays, always, per the decision to
  keep HR's offline path

## Acceptance criteria

1. Clicking Reset password sends a password-reset link to that user
2. The on-screen one-time password still appears in every case, including when the mailer
   throws
3. HR is told which of the three outcomes occurred
4. The audit log still records the action and never the plaintext
5. All four tests pass, and the existing coverage of this route still passes:
   `tests/Feature/AuthFlowsTest.php` and `tests/Feature/HardeningTest.php` both exercise
   `members.reset-password`
