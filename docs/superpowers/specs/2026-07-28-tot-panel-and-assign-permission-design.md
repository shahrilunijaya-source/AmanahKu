# TOT panel redesign and assign permission

Follow-up to [2026-07-27-tot-sessions-design.md](2026-07-27-tot-sessions-design.md), which
shipped the TOT board and is live on staging. That spec left roster assignment as KIV. This
spec closes it, and reworks the session panel after the first real use showed two problems.

## Why

Three things came out of using the deployed screen.

1. **Only HR can set a presenter.** In the sheet, Kussairi does this. The app has no way to
   give one named person that right without making them HR.
2. **The open panel is mostly empty space.** A slot nobody has touched still renders the full
   comment thread, the composer, the 1 to 5 row and the note box. That is about 400px to say
   "no comments yet".
3. **Every action reloads the page.** React, rate, watch and comment are plain form posts that
   redirect. A person who wants to react, then rate, then comment pays three full page loads
   and loses their scroll position each time.

---

## Part A: the `tot.assign` permission

### The key

`tot.assign`. One ability: set, change or clear who presents a month.

- Added to `Permissions::ROLE_PERMISSIONS` for `hr` and `management`. `director` inherits
  through `effectiveRole()`. Not given to `manager` or `employee` by role.
- Added to `Permissions::overridable()`, which is what makes the toggle appear.

No new screen. `/app/roles` already loops over `overridableGrouped()` and renders a toggle per
member per permission, saved as `UserPermission` rows and read back by `User::canInTenant()`.
Adding the key puts a "tot" group in that grid with one switch in it. HR turns it on for
Kussairi.

This obeys the rule written above `overridable()`: widen the list only together with new
`canInTenant()` enforcement, so an admin never sees a toggle that does nothing.

### Who can grant it

`/app/roles` stays gated to `management` and `hr`. Managers are not given access to it.

Reason: that screen grants **every** overridable permission, `staff.create` and `staff.update`
included. Letting each team manager in would let any manager give staff-editing rights to
anyone. Holding a permission and handing it out are different powers.

### What the holder can do

`TotController` gains one flag:

```php
$canAssignPresenter = $privileged || $user->canInTenant($tenant, 'tot.assign');
```

It controls exactly three things:

- whether `presenter_employee_id` is in the validated rule set on update,
- whether `store()` accepts the request at all, see "Creating a slot" below, and
- whether the presenter picker renders, which means `screenData()` must return the flag.

Title, status, links, held date, delete, and linking an imported nickname to an employee all
stay on `$privileged`. The defence is the one already used in this controller: `validate()`
returns only the keys it has rules for, so a holder who posts `status=done` has that key
dropped rather than blocked.

### Creating a slot

`store()` currently refuses anybody who is not privileged. It now also accepts a
`tot.assign` holder, but on a narrower rule set: `year`, `month` and `presenter_employee_id`
only. `status` is forced to `planned` and every other field stays null. The existing refusal on
a duplicate slot, a 422, does not change.

Reason: the seeder filled 2024 through 2026. Without this, the holder cannot assign anybody in
January 2027 and HR has to pre-create each year, which puts back the person this permission
removes.

### Clearing a presenter

Allowed. Sending an empty `presenter_employee_id` clears both `presenter_employee_id` and
`presenter_name` and leaves the slot `planned`.

A cleared slot does not revoke any Knowledge Bank credit. That rule has not changed and the
reason has not changed: revoking could erase a contribution earned by writing a lesson, and
that failure would be invisible.

### Notification

When a presenter is set or changed, the new presenter gets an `AppNotification` at once. No
email. The dedupe key is the session id plus the employee id, so re-saving the same person
does not send twice.

Copy: "You are presenting TOT on <date>." Link goes to the TOT screen.

Clearing sends nothing. A person who is removed learns it from the screen, and a "you are no
longer presenting" message is noise.

### Audit

`AuditLog::record('Assigned TOT presenter', ...)` on every write that changes the presenter,
including a clear. This is the only way to answer "who put Roy on March" once the right lives
outside HR.

### Who sees the edit control

| Viewer | Sees edit | Can change |
|---|---|---|
| HR, management, director | Yes | Everything |
| `tot.assign` holder | Yes | Presenter only |
| The slot's own presenter | Yes, once assigned | Title, links, notes on their own slot |
| Everybody else | No | Nothing |

---

## Part B: the panel

Direction B from the design round, with an icon metric row.

### Card anatomy

Top block is unchanged: red date tile, presenter name at 19px in brand red, title under it,
mono meta line.

Below it, two rows.

**Reaction pills.** One pill per emoji that has at least one reaction, showing the emoji and
the count. Never names. A slot with no reactions shows no pills.

**Icon row**, separated by a hairline:

| Icon | Shows | Action |
|---|---|---|
| Heart | Total reactions | Opens the emoji flyout |
| Speech bubble | Comment count | Opens the comment modal |
| Eye | Watched count | Toggles your own watched flag |
| Star | Average and rater count, **to the presenter and management only** | Opens the rating flyout |
| Pencil | Nothing | Opens the edit form, gated as in Part A |

Everybody else sees a plain star with no numbers.

### The emoji flyout

Opens on hover, on keyboard focus, and on tap. **No press and hold.**

Press and hold was considered and dropped. It fights the browser on both mobile platforms, the
iOS selection callout and the Android context menu, and needs `touch-action`,
`-webkit-touch-callout: none`, `user-select: none` and pointer-event cancelling. Facebook needs
it because a plain tap already means "like". The heart here has no default reaction, so a tap
has nothing else to do. Tap to open is less code and is discoverable.

Contents: the six whitelisted emoji from `TotSession::EMOJI`. Your own reactions read as
selected. Picking one toggles it.

### The rating flyout

Same trigger rules. Opens with the five score circles.

After a score is picked, the flyout swaps in place to a one-line note box, pre-filled if you
already wrote one. The score saves on the click, not on the note. The note saves on blur or on
Enter.

Reason for swapping in place instead of sending people to the modal: most people never write a
note, and the ones who do should not lose their place.

Ratings stay pseudonymous. Only the presenter and management see any score at all, and never
with a name attached. That has not changed.

### The comment modal

The speech bubble opens a centred modal.

- Header: session title, close button.
- Sub-header: presenter avatar, name, session date, comment count.
- Body: the thread, oldest first.
- Footer: the composer, with the same "everyone in the workspace can read this" note.

The thread loads when the modal opens, not with the page. The card only needs a count, which
comes from a `withCount` on the screen query.

### What this removes from the card

The always-open thread, the always-open composer, the 1 to 5 button row, the note textarea, and
the "0 comments / no comments yet" block. An untouched slot costs about 100px instead of about
400px.

### Motion

| Thing | Value |
|---|---|
| Flyout fade in | 150ms ease |
| Flyout scale in | 200ms `cubic-bezier(.2, .9, .3, 1.35)` from `translateY(8px) scale(.92)` |
| Emoji stagger | 30ms per emoji, six total |
| Emoji hover | `scale(1.4) translateY(-5px)`, 140ms, same easing |
| Modal open | 150ms fade on the backdrop, 180ms scale from `.96` on the card |
| Reduced motion | `prefers-reduced-motion: reduce` drops every transform and keeps only the fades |

---

## Part C: actions without a page reload

Every TOT action becomes a fetch call. No redirect, no scroll loss.

### Server

Each of `react`, `watched`, `rate`, `comment` and `comment delete` returns JSON when the
request wants JSON, and keeps its current redirect otherwise:

```php
return $request->expectsJson()
    ? response()->json($this->sessionState($session))
    : back();
```

`sessionState()` returns exactly what the card and the modal draw from: reaction counts by
emoji, the caller's own reactions, watched count, the caller's watched flag, comment count, the
caller's score and note, and the score summary **only when the caller is allowed to see it**.
The visibility rule lives in one place and is the same rule the screen already uses.

Keeping the redirect branch matters for two reasons: the feature still works with JavaScript
off, and every existing feature test keeps passing unchanged.

### Client

One Alpine component per session panel holding the state above, following the pattern already
in `layouts/app.blade.php`: `fetch` with `X-CSRF-TOKEN` from the meta tag and
`Accept: application/json`.

Rules:

- The UI updates optimistically, then reconciles with the response.
- A failed request rolls the optimistic change back and shows the existing flash style error.
- The double-press races already handled on the server, the SQLSTATE 23 catches on react and
  rate, stay exactly as they are. Fetch makes them more likely, not less.

---

---

## Part D: flash messages become toasts

### The duplicate

`layouts/app.blade.php` renders a `uj-alert` banner for `session('ok')` and another for
`session('error')`. Eleven screens then render their own copy of the same message:

`benefits`, `events`, `feedback`, `goals`, `ideas`, `learning`, `position`, `skills`,
`surveys`, `wellness`, `tot`.

Every one of those screens shows every confirmation twice. This is not a TOT bug. TOT is the
eleventh screen to inherit it, and the screenshot that found it would have been reproducible on
any of the other ten.

### The fix

The toast system is already built and already mounted: `resources/js/toast.js` registers a
`toast` Alpine store with a queue, hover to hold, a timed progress bar, three tones and a
bilingual dismiss label, and `partials/toast-host.blade.php` renders it. It is included at the
bottom of `layouts/app.blade.php`. Nothing new is needed.

So:

1. Delete the eleven per-screen `@if (session('ok'))` blocks.
2. Delete the two `uj-alert` blocks from the layout.
3. Seed the store once on boot, after `registerToast`, from `session('ok')` and
   `session('error')`.

This also serves Part C. Once actions stop reloading the page there is no next request to carry
a flash, so the fetch handlers push to the same store directly. One confirmation path for both
the JavaScript and the no-JavaScript case.

### What stays a banner

A toast is for something transient that a person may miss without harm. These are not that, and
they keep their current in-page treatment:

- The profile completion banner. It is a standing state, not an event.
- The "What is this screen for?" help box.
- The one-time password reveal after an HR password reset. It must stay on screen and stay
  copyable, and auto-dismissing it would lose the only copy.
- Form validation error lists, which belong beside their fields.

## Testing

**Part A**

- Manager with the override sets a presenter: saved.
- Same manager sends `title` and `status` in the same request: both dropped, slot unchanged.
- Same manager gets 403 on `/app/roles`.
- Manager without the override: presenter rejected.
- Override revoked: presenter rejected.
- Override granted in tenant B does not grant anything in tenant A.
- Holder creates a slot with a presenter: created as `planned`.
- Holder clears a presenter: both presenter columns null, Knowledge Bank credit untouched.
- Assigning sends one notification; re-saving the same presenter sends none.
- Every presenter write appends an audit row.

**Part C**

- Each action returns JSON with the expected keys when the request wants JSON.
- Each action still redirects when it does not.
- A viewer who may not see scores gets no score keys in the JSON.
- The concurrent double-react and double-rate tests keep passing over the JSON path.

**Part D**

- A screen that flashes `ok` renders no `uj-alert` markup and no per-screen banner.
- The toast host is present and the message reaches it once, not twice.

**Part B** is view work. Cover the server-visible parts: the comment count comes from
`withCount`, the modal route returns only that session's comments, and it refuses a session
from another tenant.

## Out of scope

- Threaded replies. Comments stay flat.
- Reaction or rating names, in any view.
- Email for anything.
- Changing who may read scores.
- Press and hold gestures.
