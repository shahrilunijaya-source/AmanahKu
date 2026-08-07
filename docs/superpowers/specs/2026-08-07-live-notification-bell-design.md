# Design: Live notification bell

## Purpose

The header bell (`partials/header.blade.php:160-192`) renders `$notifications` /
`$unreadCount` once, server-side, from the `View::composer('partials.header', ...)`
in `AppServiceProvider.php:77-89`. A new bell (leave approved, ticket updated,
mention, etc.) never appears until the page is reloaded. Fix: poll and refresh the
badge + dropdown list live, without a page reload.

## Non-goals

- `resources/js/notifier.js` (OS-level push notifications, gated on browser
  permission) is untouched — separate concern, its own poll loop, already works.
- `GET /app/notifications/unseen` (the cursor-based endpoint `notifier.js` polls)
  is untouched — different shape (unread-only, capped-5, incremental cursor) built
  for a different consumer. Not reused or extended for this.
- "Mark all read" stays a plain form POST (full reload). It already resets the
  composer's SSR values on reload; no live-badge interaction needed there.

## Approach

Mirror the existing `msgbadge` store (`layouts/app.blade.php:238-246` +
`MessageController::summary()`), which already solves this exact problem for the
Messages envelope: unread count + a list, seeded server-side, kept live by a plain
poll-and-full-replace loop, no cursor. Picked over extending `/unseen` (wrong shape,
wrong semantics — unread-only vs. mixed-read list) and over a new module-pattern
store via `registerX(Alpine)` (kbadge/msgbadge don't use that pattern either, so a
third badge store shouldn't invent a second convention).

1. **New endpoint** `GET /app/notifications/summary`, `NotificationController`,
   throttled `120,1` (same as `/unseen`). Returns
   `{unread: int, notifications: [{id, title, body, url, read_at, at}]}` where
   `read_at` is a bool and `at` is `created_at->diffForHumans()` — same shape the
   composer already renders, just flattened to an array + JSON instead of Blade.
   Query: latest 8 (all read states) + true unread count, same as
   `AppServiceProvider.php:84-85`.
2. **`AppServiceProvider` composer** changes to build that same array (instead of
   raw `AppNotification` models) so both the first-paint Blade render and the
   `@js(...)` store seed come from one mapping — same trick `MessageController`
   uses for `mapConversation()` across `context()` and `summary()`.
3. **New `notifbell` Alpine store**, registered inline in `layouts/app.blade.php`
   next to `kbadge`/`msgbadge`: seeded from `$unreadCount`/`$notifications`, polls
   `notifications.summary` every 15s (HR events aren't chat-speed urgent — chose a
   slower interval than `msgbadge`'s 5s to cut request volume), full-replaces
   `unread` + `notifications` each tick.
4. **`header.blade.php`** bell badge (`@if ($unreadCount > 0)`) and list
   (`@forelse ($notifications as $n)`) become `x-show`/`x-for` off
   `$store.notifbell`, same as the Knowledge/Messages badges. The bell's
   `x-data="{ notif: false }"` stays exactly as-is — `NotifierMarkupTest` pins that
   exact string for the unrelated OS-push wiring, and the local open/close toggle
   doesn't need to move into the store anyway.

## Testing

- Feature test for `GET /app/notifications/summary`: auth required, tenant-scoped
  (no cross-tenant/cross-user leak), returns both read and unread rows, `unread`
  count matches, capped at 8 — same shape as `UnseenNotificationsTest` but against
  the new route.
- `NotifierMarkupTest` must still pass unmodified (pins the OS-push contract this
  change doesn't touch).
