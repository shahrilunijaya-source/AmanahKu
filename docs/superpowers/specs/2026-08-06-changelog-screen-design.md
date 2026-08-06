# Design: User-facing changelog screen

## Amendment (2026-08-06, post-implementation)

This exact feature already existed once: `config/changelog.php` + a Feedback-hub
"What's New" tab + a sidebar "New" badge + an `artisan changelog:draft` command,
shipped 2026-08-02 as version `1.0` (commit `c8bca8f`), then deliberately deleted
2026-08-05 (commit `91f4070`, "unused going forward") — already live on `gitlab/main`
and staging by the time this spec was written, so prod had no changelog feature at
all when this design started. Confirmed with Shazwan: keep this build (simpler — no
badge/read-state, no artisan command, just a page), don't resurrect the old shape.
Versioning changed from date-based to semver as a result — see Data below.

## Purpose

Give every Amanahku user a place to see what changed in the app (release notes), and
give Shazwan a single source of truth to reference when answering helpdesk tickets
("that's fixed in 1.1, see /app/changelog"). Not a per-tenant
`Announcement` (HR-authored company notices) and not the repo's `CHANGELOG.md`
(governance/template lineage) — a third, new thing: app release notes, global across
all tenants, authored by whoever ships the code.

## Non-goals

- No "unseen" badge / per-user read-state tracking.
- No admin UI to author entries — entries are hand-written YAML, committed with the
  code change they describe, same discipline as any other release artefact.
- Not public/unauthenticated. Behind the existing `/app/*` auth-and-tenant gate.

## Data

`resources/changelog.yaml`, releases newest-first:

```yaml
- version: "1.1"
  date: "2026-08-06"
  entries:
    - tag: added
      text: "User-facing changelog screen."
      text_ms: "Skrin log perubahan untuk pengguna."
    - tag: fixed
      text: "Timesheet add-entry no longer double-submits."
      text_ms: "Kemasukan lembaran masa tidak lagi hantar dua kali."
```

`tag` is one of `added` / `improved` / `fixed` (closed set, validated nowhere but by
convention — this is a hand-authored file, not user input). `text_ms` is optional;
falls back to `text` when absent, same pattern `Amanahku::page()` already uses for
`title`/`title_ms`.

`App\Support\Changelog::releases(): array` reads the file with
`Symfony\Component\Yaml\Yaml::parseFile()` (already a Composer dependency — Laravel
itself depends on it, no new package). No caching layer to start: the file is small,
the screen is not hot-path traffic. Add `Cache::rememberForever` keyed on the file's
mtime only if this ever shows up in a profile.

Version string is semver `MAJOR.MINOR` (no patch level — not asked for), not CalVer.
Baseline `1.0` is the 2026-08-02 gitlab main launch commit (the old, now-deleted
changelog's own launch entry — see Amendment above) and is not re-listed in this
file. Versions track what actually lands on `gitlab main`, the prod branch, not dev
commits — an entry authored on dev carries the version it will ship as once merged
to main, so double-check the number still fits at merge time if other work landed on
main first. Bump MINOR for an ordinary release; bump MAJOR only for something
genuinely meaningful (a real breaking or headline change), never on a fixed cadence.

## Backend wiring

Follows the existing single-dispatcher screen convention
(`AppController::screen()` / `routes/web.php:461` `/app/{screen?}`) — no new route.

- `AppController::screenData()` match arm: `'changelog' => ['releases' => Changelog::releases()],`
- `Amanahku::page('changelog')`: title "Changelog" / "Log Perubahan", sub "What's new
  in Amanahku." / "Apa yang baharu dalam Amanahku.", crumb `['Changelog']`.
- No role gate (`authorizeTenantRole`), no `FeatureManager::screenAllowed` gate — this
  is release-note content, not a tenant-configurable module. Every authenticated user
  in every tenant sees the same releases.
- Not added to `Amanahku`'s main nav item array — it's a permanent utility page, not a
  work screen. Reachable by direct URL (`/app/changelog`) and the footer link below.

## View

`resources/views/screens/changelog.blade.php`, inside the shared `screens.*` shell
(sidebar/header stay, only `<main>` swaps via `partial-nav.js` like every other
in-app link). Single column, 920px measure per the Layout section of `docs/DESIGN.md`.

One bare card per release (`.uj-card-bare` — rhythm without a frame, since this is a
simple read-only list, not a data surface needing its own boundary):

- Release header: version in mono/label style (`--font-mono`, `--t-sm`, ink) next to
  the date in `--muted` subline, separated by `·` — same idiom the sidebar clock/date
  pairing already uses.
- Entries as a plain list under the header, each line: a `uj-stamp` tag
  (`data-tone="success"` for added, no `data-tone` — neutral grey — for improved,
  `data-tone="error"` for fixed — never `red`, per the One Voice Rule: red means "act",
  and nothing here is actionable; `uj-stamp` itself only wires `red`/`amber`/`success`/
  `error` tones, so "improved" takes the component's own neutral default rather than
  a non-existent `info` tone) followed by the bilingual text via
  `x-text="$store.ui.lang==='en' ? @js($entry['text']) : @js($entry['text_ms'] ?? $entry['text'])"`.
- 1px hairline between releases (`--hairline`), no shadows at rest, 12px radius if a
  card frame ends up warranted for the first release (most-recent gets `.uj-card`
  instead of bare, to match "the one live thing on this screen" without needing a
  second `--shelf` material for a plain list).
- Empty state (`releases` is empty): centred muted text, matches other screens'
  `screens.empty`-adjacent empty patterns — not expected to ever actually render since
  the file always has at least the release that introduces this screen.

## Entry point

A "Changelog" link in the sidebar footer (`resources/views/partials/sidebar.blade.php`,
`.uj-sb-foot`, alongside the existing "Raise a ticket" button) — same visual treatment
(icon + label, `uj-feedback-btn`-style row), `href="{{ route('app.screen', 'changelog') }}"`.
Always visible, no feature-flag guard (unlike "Raise a ticket", which hides when
`module.helpdesk` is off — changelog isn't a module, it's always there).

## Testing

A feature test (`tests/Feature/ChangelogScreenTest.php`): authenticated user in a
tenant hits `/app/changelog`, gets 200, sees the newest release's version string in
the response. Covers the happy path; no failure/edge path worth a second test since
there's no user input and no gating logic to get wrong.
