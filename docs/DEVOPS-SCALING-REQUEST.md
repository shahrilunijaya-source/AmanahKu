# Request to DevOps: production readiness for 34 users

**From:** Amanahku application development
**Date:** 2026-08-10 (revised the same evening, see "Already answered" below)
**About:** `https://amanahku.unijaya.com` (DigitalOcean droplet `S1-unijaya-quickstart`,
released from GitLab, deployed at `/mnt/volume_sgp1_01/project/amanahku`)

## Why I am asking

Amanahku was built and released without a load target. It now has to carry up to
34 employees, with the busiest moment at month end when everyone submits
timesheets and payroll runs.

I have audited the application code and I am fixing what belongs to me. The
findings below are outside the code, so I cannot see them or change them. I have
no shell access, no database access and no cron visibility on production. Please
treat every item as a question, not an instruction. If something is already
handled, just say so and I will record it.

---

## Already answered — thank you, no action needed

You ran several checks for us on the evening of 2026-08-10. Recording the results
here so nobody is asked for them twice.

**The scheduler cron is running.** Confirmed in `crontab -l`:

```
* * * * * cd /mnt/volume_sgp1_01/project/amanahku && php artisan schedule:run >> /dev/null 2>&1
```

So leave accrual, carry-forward, attendance and timesheet reminders, the weekly
digest, staff auto-archive and the nightly pruning are all live. This was our
number one worry and it is closed. There is no systemd timer, which is why our
first check came back empty — plain cron is doing the job.

**A queue worker is running.** A persistent process, running as `www-data`:

```
/usr/bin/php .../artisan queue:work --sleep=3 --tries=3 --max-time=3600
```

Plus a `*/5` cron running `queue:work --stop-when-empty --max-time=280`. So
invitation and password-setup email is being delivered. Also closed.

**The web user can write to application storage.** `www-data` is in the `devops`
group and `storage/framework/cache/data` is group-writable. This matters because
a change released on 2026-08-10 moved rate limiting onto the local file cache,
and login and clock-in are both rate-limited routes.

**The duplicated root crontab has been removed.** The Amanahku block had been
running from both the `devops` and the `root` crontab. DevOps cleared the `root`
copy on 2026-08-10, so `artisan` no longer runs as root and will not create
root-owned files under `storage/`. That also drops the queue consumers from three
to two, which is the right number: the persistent worker, plus one cron as a
safety net.

**This droplet is shared with at least two other applications** —
`/var/www/nsfirm-test` and `/mnt/volume_sgp1_01/project/eacc`, both with their own
cron entries. We had assumed Amanahku had the droplet to itself. It changes how
items 4, 5 and 6 should be answered, and we have reworded them accordingly.

---

## 1. One leftover from the root crontab, worth a single check

Removing the root cron stops *new* root-owned files being created. It does not
change any that were already written while it was running, and those keep their
ownership until somebody changes it.

**Please run this once** in `/mnt/volume_sgp1_01/project/amanahku`:

```bash
find storage bootstrap/cache -user root
```

If it prints nothing, this item is closed and no action is needed.

If it prints anything, those files are unwritable by the web user, and the fix is:

```bash
sudo chown -R devops:devops storage bootstrap/cache
```

**Why it matters.** A single root-owned `laravel.log` or compiled view is enough to
produce 500 errors on the pages that touch it — and the errors appear unrelated to
their cause, because the file was created days earlier by a cron nobody was
thinking about.

---

## 2. What supervises the persistent queue worker?

The worker we saw runs with `--max-time=3600`, so **by design it exits after an
hour**. Something must be starting it again. It has clearly been working, so
something is — we just cannot see what.

**Please tell us** whether that is `supervisor`, a `systemd` service, or something
else, and confirm it is enabled at boot.

**Why we ask.** Our `deploy.sh` calls `php artisan queue:restart`, which only
signals a *running* worker to finish its current job and exit. It does not start
one. So the supervising mechanism is what makes a deploy safe for the queue. If
that mechanism is the `*/5` cron rather than a supervisor, the queue still works,
but a job can sit for up to five minutes, which is worth knowing for invitation
email.

**Also useful to know:** does anything alert on rows appearing in the
`failed_jobs` table? If not, that is a small win worth adding.

---

## 3. Can you confirm the production `.env` values for these six keys?

I can only see the template committed in the repository, not the real file. I do
not need any secrets, only these six lines:

```
SESSION_DRIVER=
CACHE_STORE=
QUEUE_CONNECTION=
FILESYSTEM_DISK=
CACHE_LIMITER=
APP_DEBUG=
```

**What we expect them to be:** `database`, `database`, `database`, `local`,
`file`, `false`. Those are also the application's built-in defaults, so if a key
is simply absent from `.env` the expected value is what you get. We only need to
know about any key that is set to something *different*.

**Why it matters.** If `SESSION_DRIVER` is `file` rather than `database`, users
would be logged out at random the moment a second application instance is ever
added. If `APP_DEBUG` is `true`, full stack traces including configuration values
are shown to anyone who triggers an error. Both are quick to check and important
to know.

---

## 4. What is the droplet specification, and how is it shared?

**Please tell us:** vCPU count, RAM, disk size and type — and roughly how much of
that is already committed to the other applications on this box.

**Why we now ask it that way.** We had assumed the droplet was Amanahku's alone.
It is not: `nsfirm-test` and `eacc` live on it too, both with their own cron
schedules, and `eacc` runs a reconciliation job every 15 minutes. So the number
that matters for sizing is not the droplet's total RAM but what is left for
Amanahku at month end, when our own load peaks.

This is the input to items 5 and 6. We are not asking you to move anything — only
to tell us what share we should be sizing against.

---

## 5. What is the PHP-FPM worker configuration, and how close does it get to the limit?

**Please tell us** the values of these settings in the PHP-FPM pool config:

```
pm =
pm.max_children =
pm.start_servers =
pm.min_spare_servers =
pm.max_spare_servers =
```

**And if possible**, the highest value `max children reached` has hit in the
PHP-FPM log, or the peak `active processes` from the status page.

**Why it matters.** `pm.max_children` is the hard ceiling on how many requests
the site can serve at the same moment. Request number `max_children + 1` waits in
a queue. If the queue fills, users see a 502 error.

A rough sizing rule for a Laravel application: a PHP-FPM child uses roughly 40 to
80 MB. Take the RAM available after MySQL and the operating system take their
share, and divide by that. As a guide, a 4 GB droplet running MySQL on the same
box usually lands somewhere around 20 to 30 children. Please use the real
measured process size rather than my estimate.

**Context on the traffic we send you — this has already improved.** The
application used to make a background browser request every 5 seconds per open
tab, from every logged-in user, even when the tab was hidden. With 34 people that
was roughly 7 requests per second, sustained.

That is fixed and **the fix is live in production as of the 2026-08-10 release**:
the poll now runs about once every 15 to 20 seconds, randomised so tabs do not
land on the same instant, and it skips entirely while a tab is hidden. Real-world
load should be well under a quarter of the old figure.

So please size against current measurements, not against anything from before
that release. If the PHP-FPM peak you report was recorded earlier in the day, it
is describing the old behaviour.

---

## 6. What is MySQL `max_connections`, and what is the observed peak?

**Please run and share:**

```sql
SHOW VARIABLES LIKE 'max_connections';
SHOW STATUS LIKE 'Max_used_connections';
```

**Why it matters.** PHP does not pool database connections. Each PHP-FPM child
opens one connection while it serves a request. So `max_connections` must be
comfortably above `pm.max_children`, with extra room for:

- the queue worker (item 2), one connection
- the scheduler, one connection each minute
- any admin or backup process

If `Max_used_connections` is at or near `max_connections`, users are already
seeing "too many connections" errors under load.

One thing that makes this heavier than it looks: sessions, the cache and the job
queue **all currently live in MySQL** on this application. Every single page
request performs a session read and a session write against the database. That
leads to the next item.

---

## 7. Is Redis available on this droplet, or can it be added?

**Please tell us** whether Redis is installed, or whether it could be. **And if it
is, whether the PHP `redis` extension (phpredis) is installed as well.** Laravel
talks to Redis through that extension by default, and this repository does not
ship the pure-PHP fallback client, so Redis without the extension would not work
for us as-is.

**Why it matters, and why this is the single biggest improvement available.**
Sessions, the application cache and the job queue currently all use MySQL tables.
Every page load writes to the `sessions` table. Every cache entry is a row.
Moving those three to Redis takes that entire write load off MySQL and out of the
same lock space as real business data such as payroll and timesheets.

**What it costs on my side: nothing.** No code change at all. The application is
already fully driven by environment variables. If Redis exists, the change is
three lines in the production `.env`:

```
SESSION_DRIVER=redis
CACHE_STORE=redis
QUEUE_CONNECTION=redis
```

I would want to do this on staging first and confirm it, then apply it to
production during a quiet window. Two things happen at the moment of the switch:

- Everyone is logged out once, because existing sessions live in the old store.
- Any jobs still waiting in the MySQL `jobs` table are stranded, because the
  worker starts reading from Redis instead. So the `jobs` table must be drained
  to empty before the switch. That is what makes "a quiet window" the
  requirement rather than a preference.

That is the whole impact.

If Redis is not possible, that is fine. The current setup works at this size. I
would just like to know, so I can plan around it.

---

## 8. Is OPcache enabled, and how is it cleared on deploy?

**Please confirm** OPcache is on and share these values:

```
opcache.enable =
opcache.memory_consumption =
opcache.max_accelerated_files =
opcache.validate_timestamps =
```

**Why it matters.** Without OPcache, PHP recompiles every source file on every
request. This application has about 275 of its own files plus the vendor
libraries, so the difference is large and it costs nothing to switch on.

`opcache.max_accelerated_files` needs to be above the total file count or the
cache silently starts evicting. 20000 is a common safe value.

**The important half of this question is how it gets cleared.** If
`validate_timestamps` is `0`, which is the fastest setting, PHP will keep serving
the *old* code after a deploy until OPcache is reset. Our `deploy.sh` does not
reset OPcache. So please tell us either that `validate_timestamps` is `1`, or
what mechanism resets the cache on release. If neither is true, a deploy can
appear to do nothing.

---

## 9. Please tell us before adding a second application server

Not needed now. Recording it so it does not become a surprise.

The application currently assumes one machine in three places:

1. **Rate limiting** uses the local file system, live in production since the
   2026-08-10 release. On two servers, limits become per-server instead of shared.
2. **Uploaded files** are written to local disk. Attendance photos, claim
   receipts, leave attachments and employee documents. A second server would not
   see the first server's files.
3. **The `public/storage` symlink** points at local storage.

None of these blocks a second server. They just need code changes first, and the
code changes are listed in my own audit. **If a second application server or a
load balancer is ever planned, please give me notice before it goes in**, so the
storage move to object storage can land first.

---

## 10. Could you enable the MySQL slow query log through one month-end cycle?

**Request:** set `long_query_time = 1` and `slow_query_log = ON`, leave it running
through a full month-end (timesheet submission and payroll run), then share the
summary, for example `mysqldumpslow` output.

**Why it matters.** I have reviewed the queries by reading the code, and I have
checked the index coverage against the live schema. That finds structural
problems, and I found three missing indexes which I am adding. It cannot tell me
what is actually slow on real production data under real load. A month-end slow
query log would tell me that directly, and I would rather fix what is measurably
slow than guess.

Low priority compared to items 1 and 2, but it is the item that would most
improve the *next* round of work.

---

---

## 11. Is outbound HTTPS from the droplet unrestricted?

**Please confirm** the server can make outbound HTTPS connections to
`*.ingest.us.sentry.io`, or tell us if egress is filtered.

**Why it matters.** The 2026-08-10 release added error reporting to Sentry, which
works by the application posting to that host. If outbound traffic is filtered,
the reports silently never arrive and the dashboard simply stays empty — which
reads as "no errors" rather than "no delivery". This is a new dependency that did
not exist when this document was first drafted, so it is not something you would
have had reason to check.

Nothing breaks if it is blocked. We just need to know, so we do not spend a week
trusting an empty dashboard.

---

## Summary

| # | Request | Priority | Why |
|---|---|---|---|
| — | ~~Confirm `schedule:run` cron~~ | **Answered** | Running in the `devops` crontab |
| — | ~~Confirm a queue worker runs~~ | **Answered** | Persistent `www-data` worker plus a `*/5` cron |
| — | ~~Remove the Amanahku block from `root`'s crontab~~ | **Done** | Cleared 2026-08-10 |
| 1 | One `find storage -user root` check | High | Files the root cron already created keep their ownership |
| 2 | What supervises the persistent queue worker | High | It exits hourly by design; something restarts it |
| 3 | Confirm six `.env` values | High | Session and debug settings we cannot see |
| 4 | Droplet spec **and how much is left after the other two apps** | High | Input to items 5 and 6 |
| 5 | PHP-FPM `pm.max_children` and observed peak | High | Ceiling on concurrent requests |
| 6 | MySQL `max_connections` and observed peak | High | Must exceed PHP-FPM workers plus overhead |
| 7 | Is Redis available | Medium | Largest available gain, and zero code change |
| 8 | OPcache settings and how it clears on deploy | Medium | Free speed, plus a real deploy hazard |
| 11 | Is outbound HTTPS to Sentry allowed | Medium | New in this release; fails silently if blocked |
| 9 | Warn us before adding a second app server | Low, standing | Three single-machine assumptions in code |
| 10 | Slow query log through one month end | Low | Measured data instead of my estimates |

Both original urgent items came back clean and the root crontab is already
cleaned up, so nothing is silently broken in production today. That lowers the
temperature of this whole document: what remains is sizing and headroom, not
breakage.

Item 1 is a single `find` command and is the only thing left that could bite
without warning. Everything after it is planning.

Happy to get on a call for any of it.
