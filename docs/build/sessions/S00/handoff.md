# Session S00 handoff: CR-00 (reconcile survey)

## Delivered
- `docs/build/findings.md`: F1 to F4 answered from the code on this branch, plus the session-plan changes they force. Read it first.
- `docs/build/contracts/{roles,audit-log,dates,dashboard-slots,ports}.md`: frozen from the real schema, models and views.
- `CLAUDE.md`: rules block appended verbatim after the lerd/deploy sections, plus the repo half (test, migrate, browser, pint, assets, session files). Existing content untouched.
- `docs/build/baseline/dashboard.png`: staff user (Shazwan, employee 26), dev clock at Tuesday 8 Sep 2026 10:00, viewport 1440 by 1950 so the whole `main` scroll area is in frame, welcome modal and hint tooltip dismissed, no band active.
- `database/seeders/BuildFixturesSeeder.php` + `tests/Feature/BuildFixturesSeederTest.php`: three months of attendance, cards with subtasks and progress stints, weekly timesheets for the five quick-login employees. Proven on the test database only: 2 tests, 344 assertions, green; second run is identical (delete-by-window then insert). Run on dev with `php artisan db:seed --class=BuildFixturesSeeder`. Fixture cards are titled `Fixture: ...` so they can be found and removed.

## Schema changes
- none. S00 writes no migrations.

## Contracts touched
- all five created. Frozen from here on.

## Port calls stubbed
- none yet. S07 builds `port_outbox`.

## Deferred
- Running `BuildFixturesSeeder` against the dev database, deferred to whichever session first needs the data (S01 at the earliest). See OPEN.
- `stub` drivers, `port_outbox`, `PortsServiceProvider`: S07.

## OPEN, decided without Shazwan
- Appendix B left/right mismatch for My work summary and My working style: code wins.
- Existing participants become `helper` in S03.
- Fixture seeder not run on dev in S00.
- Existing Google Calendar client kept, wrapped in S07.

## Requested contract change (generator may not make it itself)
- none.

## Traps for the next session
- The greeting H1 rotates per request (CR-33 bank) and the dev-clock chip sits bottom-left. When grading against the baseline, compare the widget grid and bands, mask the H1 and the chip; a pixel diff on the whole page will never be clean.
- Widget order in the baseline is Shazwan's saved drag order (`users.dashboard_prefs`), not the registry default. `style` shows before `calendar` and `work` last. Do not "fix" it.
- `ParentOnly` global scope on `WorkItem` hides subtasks from every query; anything that counts, audits or locks subtasks must opt out explicitly.
- `BelongsToTenant::creating` throws without a tenant context; jobs and seeders pass `tenant_id`.
- Board writes through the web UI are unaudited today. S01 must cover `WorkItemController` update, move, assign, archive, restore, not just add columns.
- `work_items.type` has no `event` value yet. S02 reserves the rule, S13 adds the enum value.
- Roles live on the tenant membership row, not on `users` or `employees`. `Permissions::effectiveRole()` folds director into management.
- The `/session` and `/qa` commands are gitignored in `.claude/commands/`; a fresh clone needs them copied in.
