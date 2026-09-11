# S21 / CR-31 mockup (awaiting Shazwan's ok)

Screenshots made by injecting the markup into the live dashboard and board, so the chrome
is real. Files: `dash-friday-egg.png`, `dash-friday-full.png`, `dash-late-night-egg.png`,
`dash-late-night-phone.png`, `board-inbox-zero-toast.png`.

## Where an egg lives

One quiet aside under the greeting row, inside `.uj-dw-head` (flex-basis 100%, so it sits
on its own line under the h1 and the date). Never a band, never a widget, never a modal.
Nothing renders when no egg is active. On the board the inbox-zero egg is the existing
success toast (`Alpine.store('toast').success(text)`), nothing new.

```html
<div class="uj-egg" role="status" data-egg="late_night" data-egg-en="Respectfully, why are you still here?">
    <svg ...spark...></svg>
    <span x-text="$store.ui.lang==='en' ? en : ms">Respectfully, why are you still here?</span>
    <a class="uj-egg-shortcut" href="/app/overtime" data-egg-shortcut>Log your hours as overtime?</a>   <!-- late_night only -->
    <button type="button" class="uj-egg-x" aria-label="Dismiss">×</button>   <!-- hides it for the page, no request -->
</div>
```

```css
.uj-egg { display:flex; align-items:center; flex-wrap:wrap; gap:6px 9px; flex-basis:100%; width:fit-content; max-width:100%; margin:-8px 0 0; padding:7px 12px 7px 10px; border:1px solid color-mix(in srgb, var(--amber) 28%, var(--hairline)); border-radius:10px; background:color-mix(in srgb, var(--amber) 8%, #fff); font-size:var(--t-sm); color:var(--body); line-height:1.35; }
.uj-egg svg { width:14px; height:14px; color:var(--amber-ink); flex-shrink:0; }
.uj-egg-shortcut { color:var(--amber-ink); font-weight:600; text-decoration:underline; text-underline-offset:2px; text-decoration-color:color-mix(in srgb, var(--amber-ink) 40%, transparent); }
.uj-egg-x { margin-left:auto; width:22px; height:22px; border:0; background:none; color:var(--muted); border-radius:6px; cursor:pointer; font-size:16px; line-height:1; display:inline-flex; align-items:center; justify-content:center; }
.uj-egg-x:hover { color:var(--ink); background:var(--hairline-soft); }
```

Spark icon (stroke, 2px, eight short rays):
`<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v3M12 18v3M3 12h3M18 12h3M5.6 5.6l2.1 2.1M16.3 16.3l2.1 2.1M18.4 5.6l-2.1 2.1M7.7 16.3l-2.1 2.1"/></svg>`

Tone: amber tint by mix (8 percent), amber-ink for the icon and the shortcut, body ink for
the words. No motion on entry (it is in the server HTML), no sound, no timer. Under Keep it
plain the element is not rendered at all.

## Company Settings

The egg bank card copies the CR-33 "Dashboard greetings" card exactly (same list, same
add form with a kind picker instead of a trigger picker, same approve / edit / delete
buttons). No new pattern, so no separate screenshot.

## Profile

"Keep it plain" appears on the profile screen as the same checkbox row as the dashboard
picker's, posting to `/app/dashboard/prefs` (same key). Text: "Keep it plain — no
animations, no cheeky messages, anywhere." / "Biar ringkas — tiada animasi, tiada mesej
nakal, di mana-mana."
