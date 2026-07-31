# Messages screen revamp

Port the approved "Desk" variant from `public/_messages-revamp.html` (gitignored scratch
mock, variant 3) into the real messages screen. Same rhythm the dashboard and attendance
revamps used: mock approved first, then CSS ported into a namespaced block in
`resources/css/app.css`, then the controller data reshaped, then the Blade rewritten
against those classes.

Namespace: `uj-msg-`. Verified free — zero hits in `resources/css/app.css` today, and it
cannot collide with `uj-at-` (attendance), `uj-dq-` (dashboard queue), or the shared
`uj-card` / `uj-btn-*` vocabulary.

Scope is the full screen `resources/views/screens/messages.blade.php`, plus one change to
the shared layout (Task 4). The slide-over panel
`resources/views/partials/messages-panel.blade.php` is **not** touched; see "Deliberately
out of scope" at the end.

## What the revamp changes, and why

Findings from the audit of the live screen. Each one is a real defect, not a preference:

- **The screen does not work on a phone.** The frame is `display:flex` with a fixed
  `330px` first column and no media query. Measured at a 390px viewport, the thread pane
  is **2.8px wide**. Field staff are the primary phone users of this product.
- **Enter can never make a new line.** `@keydown.enter.prevent` blocks every Enter
  (`messages.blade.php:170`). The textarea is `white-space:pre-wrap` and accepts 5000
  characters but physically cannot hold two.
- **Attachment chips have no remove button** on the full screen. The slide-over panel has
  one. Attaching the wrong file means reloading the page.
- **Send has no disabled state** on the full screen. The panel has one.
- **The recipient picker server-renders every active employee** into the DOM
  (`MessageController::screenData()`, no limit) and filters client-side with `x-show`. A
  300-person tenant builds 300 anchors on every page load.
- **"+ New" is a mode toggle that hides the conversation list** to show a second search
  field, while the app header already carries a global people search.
- **Every bubble prints its own timestamp.** Five messages in one minute print the same
  10px mono string five times.
- **There are no date separators.** A three-week thread reads as one continuous run.
- **Read state is invisible to the sender.** `read_at` is stored per message and already
  drives the unread badge, but the sender never sees it.
- **Bubbles are capped in `%`, not `ch`.** `max-width:70%` on a ~1100px pane produces a
  770px bubble for a four-word message.
- **Small text fails WCAG AA.** `--muted-soft` carries every timestamp and measures
  **3.55:1** on white, **3.28:1** on canvas, **2.92:1** on the warm shelf. `--success`
  is **3.97:1** on canvas.

The revamp answers, in order: who is talking to me and is it urgent; what did they say;
reply.

## The chosen direction — "Desk"

The warm shelf material committed by the attendance revamp, applied to messages:

- The **index** is a triage list, not a chat list. Rows group under `Today` /
  `This week` / `Earlier`, carry the person's position, and give the snippet two lines.
  You scan it to decide what needs an answer, not to find a name you already know.
- The **thread** sits on `--shelf` (`#ece9e1`), so incoming white bubbles read as paper
  lying on a desk rather than white-on-white.
- The **composer** is a raised white dock resting on the shelf. It lifts on focus —
  shadow deepens, 1px rise — the only motion the composer owns.

## Layout targets

- **Desktop reference width 1600.** Index `318px` fixed, thread fills the rest. Bubbles
  cap at `48ch`, not a percentage.
- **Mobile ≤820px.** One pane, push/pop, driven by the URL — no JavaScript. No `?c=` and
  no `?to=` means the index fills the screen; either present means the thread fills it,
  with a back link to the bare `messages` route. This is the same routing the screen
  already uses, so nothing new is introduced to make it work.

## Contract that must not change

- `POST route('messages.send')`, `enctype="multipart/form-data"`, `@csrf`
- Fields: `conversation_id` **or** `to`, `body` (max 5000), `attachments[]` (max 6)
- `POST route('messages.read', $conversation)` fired on thread open, updating
  `$store.msgbadge.unread` from the JSON response
- Deep links `?c=<conversationId>`, `?to=<employeeId>`, `?draft=<text>` (the dashboard's
  birthday "Wish" button seeds a blank composer through `?draft`)
- `route('messages.attachment', $a)` as the only path to a file — never a public URL

Also preserved: the `partials.guide` banner, bilingual EN/BM via `$store.ui.lang`, and
Alpine as the only client-side mechanism (no vanilla `<script>` blocks, no new
dependencies).

---

## Task 1 — CSS: the `uj-msg-` block

**Files:** `resources/css/app.css`

Append a namespaced block after the existing `uj-at-` block, following its structure and
comment style. Port the rules from variant 3 of `public/_messages-revamp.html`, dropping
everything under the mock's "PICKER", "viewport toggle", and "APP SHELL STUB" comment
banners — those are prototype scaffolding (`.proto-picker*`, `.mk-*`, `.shell`, `.sb*`,
`.hd*`, `.phone`, `.main`) and must not be ported.

| Class | Role |
|---|---|
| `.uj-msg` / `.uj-msg[data-thread]` | two-pane frame; the attribute drives the mobile push/pop |
| `.uj-msg-index` / `.uj-msg-search` | left column and its single search field |
| `.uj-msg-grp` | `Today` / `This week` / `Earlier` heading |
| `.uj-msg-row` / `.uj-msg-name` / `.uj-msg-pos` / `.uj-msg-snip` / `.uj-msg-badge` | the two-line triage row |
| `.uj-msg-thread` / `.uj-msg-thd` | thread column on the shelf, and its header |
| `.uj-msg-scroll` / `.uj-msg-day` | message area and its date divider |
| `.uj-msg-run` / `.uj-msg-b` / `.uj-msg-meta` | one sender run, its bubbles, its single timestamp |
| `.uj-msg-att` / `.uj-msg-file` | image tile and file card |
| `.uj-msg-dock` / `.uj-msg-db` / `.uj-msg-send` / `.uj-msg-hint` | the composer dock |
| `.uj-msg-chip` | removable attachment chip |
| `.uj-msg-empty` | no-thread-selected state |

Carry these corrections from the mock; they are fixes, not restyling:

- All small text moves off `--muted-soft`. On white and canvas use `--muted` (5.26:1 /
  4.86:1); on the warm shelf use `--body` (5.86:1), because `--muted` is only 4.33:1
  there.
- Add a token `--success-ink: #14614a` beside `--success` and use it for the "Read"
  marker. `--success` is 3.97:1 on canvas and fails AA at 10.5px.
- Spans used as blocks (`.uj-msg-snip`, `.uj-msg-pos`) need explicit `display:block`, or
  `white-space:nowrap` / `text-overflow:ellipsis` silently does nothing and the row
  overflows its column.
- `.uj-msg-dock` respects `prefers-reduced-motion` by dropping the `translateY` and
  keeping only the shadow change.

**Verify:** `bun run build` succeeds; the new block adds no rule outside the `uj-msg-`
prefix except the one `--success-ink` token.

---

## Task 2 — Controller: reshape the screen payload

**Files:** `app/Http/Controllers/MessageController.php`

`screenData()` and its private helpers only. `context()`, `send()`, `markRead()`,
`thread()`, `attachment()`, and `unread()` are untouched, so the slide-over panel keeps
working exactly as it does now.

1. **`messageArr()` gains grouping and read data.** Add `senderId`, `time` (`H:i`),
   `date` (`Y-m-d`), and `read` (`$message->read_at !== null`). Keep `at` as it is —
   `thread()` serves it to the panel and the panel's Blade reads it.
2. **New private `runsFor(array $messages): array`.** Fold the flat message list into
   runs: consecutive messages from the same sender on the same date become one run
   carrying `mine`, `time`, `read`, `bubbles[]`, and `attachments[]`. Emit a day marker
   whenever the date changes. `activePayload()` returns `runs` alongside `messages`;
   `messages` stays so nothing that reads it breaks.
3. **Day labels are relative.** Today, Yesterday, then `l, j F` ("Monday, 21 July") for
   anything older. Bilingual handling follows whatever the existing screens already do
   for dates.
4. **`mapConversation()` gains a recency bucket.** Add `bucket` (`today` | `week` |
   `earlier`) from `last_message_at`, and `atShort` — `H:i` today, `D` within the week,
   `j M` beyond it. Keep `at` (`diffForHumans()`) for the panel. Raise the snippet limit
   from 60 to 120 characters, because the Desk row gives it two lines.
5. **Bound the recipient payload.** Stop Blade-rendering every employee. `screenData()`
   keeps returning `msgRecipients`, but the Blade hands it to Alpine as one `@js()` array
   and renders matched rows with `x-for`, so the resting DOM cost is zero rows instead of
   one per employee.

**Verify:** `php artisan test --compact --filter=Message`.

---

## Task 3 — Blade: rewrite the screen

**Files:** `resources/views/screens/messages.blade.php`

Rewrite against the `uj-msg-` classes. Every inline `style="…"` in the current file is
replaced by a class; the file should carry no layout in attributes when it is done.

- **One search field**, always present, no `+ New` mode toggle. It filters the rendered
  conversations and, below them, offers matching colleagues under a "Start a new
  conversation" heading. Both result groups come from Alpine over `@js()` data.
- **Index rows** are the two-line triage row under `Today` / `This week` / `Earlier`,
  driven by the `bucket` field. A group with no rows renders nothing.
- **Thread** renders `runs`, not `messages`: a day divider per marker, one timestamp per
  run, `Read` appended to the last own run when `read` is true.
- **Composer fixes**, all four:
  - `@keydown.enter.exact.prevent="$el.form.requestSubmit()"` so Shift+Enter falls
    through to a real newline.
  - Chips get a remove button that rebuilds `$refs.file.files` through `DataTransfer`,
    matching how `add()` already builds a batch.
  - Send carries `:disabled="!body.trim() && files.length === 0"`.
  - Keep both hidden inputs and the `uj-cam-only` camera button exactly as they are.
- **Mobile push/pop** is `data-thread` on `.uj-msg` when `$a` is set, plus a back link in
  the thread header that is CSS-hidden above 820px.
- Keep the `partials.guide` include, the `?draft` seed with its `Str::limit(…, 5000)`
  cap, and every `x-text` EN/BM pair.

**Verify:** load `/app/messages` at 1600px and at 390px behind
`http://localhost:9100/dev/login?email=employee@amanahku.test&tenant=unijaya`; confirm the
thread pane is full width on both, Shift+Enter makes a newline, and removing a chip
removes the file from the real input.

---

## Task 4 — Drop the breadcrumb everywhere

**Files:** `resources/views/layouts/app.blade.php`

The breadcrumb row renders on every screen except `dash`, which opts out of the whole page
head. Delete it outright. The sidebar already marks where you are, and the crumb's last
segment is always the same word as the `<h1>` directly beneath it — "Unijaya Resources /
Messages" above a heading that says "Messages". The comment already in the layout makes
that argument for `dash`; it holds for every screen.

Remove the `@foreach ($crumbs …)` block and its wrapper `<div>` from the page head. Leave
`$crumbs` / `$crumbsMs` in `AppController` alone — grep first, then keep whatever still
consumes them (the `<title>`, and anything else the grep turns up).

**Verify:** no screen renders a crumb; `<h1>` and subtitle still render everywhere except
`dash`; `dash` is unchanged; nothing references an undefined `$crumbs`.

---

## Task 5 — Tests

**Files:** `tests/Feature/MessageTest.php`

The existing write-path coverage stays as it is. Add assertions on the new
`screenData()` shape:

- consecutive messages from one sender on one date fold into a single run
- a sender change starts a new run
- a date change emits a day marker and starts a new run
- an own message whose `read_at` is set reports `read => true`, and `null` reports false
- `bucket` is `today` / `week` / `earlier` for `last_message_at` of now, 3 days ago, and
  30 days ago
- `atShort` is `H:i` today and `j M` for an old thread
- an attachment-only message still produces a run with no bubbles

**Verify:** `php artisan test --compact --filter=Message` — all green, including the
existing `MessageAttachmentTest`.

---

## Finish

- `vendor/bin/pint --dirty --format agent`
- `node <impeccable>/scripts/detect.mjs --json resources/views/screens/messages.blade.php`
- `bun run build`, then commit `public/build` alongside the change (the staging host has
  no Node)
- Delete `public/_messages-revamp.html` once the port is verified

## Deliberately out of scope

- **The slide-over panel is not merged.** `partials/messages-panel.blade.php` is a second
  133-line implementation of the same feature, and the two have already drifted: the
  panel sends by `fetch`, removes chips, and disables Send; the full screen does none of
  those. This plan makes the full screen the better one. Collapsing the two into a shared
  component is a separate change with its own risk, and doing it here would double the
  diff for no user-visible gain.
- **Thread switching still reloads the page.** Every index row is a link to `?c=`, so
  changing thread re-renders the whole shell. Turning that into a fetch is a real
  improvement, but it is a behavioural change to routing, not a design port, and the
  mobile push/pop above depends on the URL carrying the state.
