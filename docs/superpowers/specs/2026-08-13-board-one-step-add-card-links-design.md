# Task board: one-step add card, plus links on a card

**Date:** 2026-08-13
**Status:** design approved, not implemented

## Problem

Adding a card today is two separate steps. Clicking "+ Add a card" opens a small
textarea inline in the column (`toggleComposer`, `resources/views/screens/board.blade.php:119-140`).
Typing a title and pressing Enter (`submitAdd`, `resources/js/work-board.js:799-825`)
POSTs to `WorkItemController::store()`, which only accepts `title`, `type`,
`priority` and `status` — no description, due date, project, labels or people. The
new card lands on the board bare. To fill in anything else, the user has to click
the card again, which opens the full detail drawer (`openCard` →
`openCardCore`, `resources/js/work-board.js:445-521`, markup at
`board.blade.php:151-410`).

That second click is the "double entry" this closes. There is also no way to
attach a link (a Google Doc, a Drive folder, a meeting link) to a card at all —
neither field nor column exists on `WorkItem` today.

## Decisions taken

| Question | Decision |
|---|---|
| What replaces the inline textarea? | Clicking "+ Add a card" creates a real (placeholder-titled) card immediately and opens the same detail drawer used for editing, in place. No new UI surface, no draft-before-save concept — the drawer's existing per-field autosave becomes the entry form. |
| Placeholder title | `"Untitled card"` / `"Kad tanpa tajuk"`. `store()` requires a title; the user overtypes it in the drawer like any other title edit. |
| What "links" means | Multiple `{label, url}` pairs per card, editable in the drawer, shown as clickable buttons. Mirrors `TotSession::$links` exactly (`app/Models/TotSession.php`, `TotController.php:185-228`, `resources/views/partials/tot-edit-form.blade.php:60-73`, `tot-drawer.blade.php:50-56`) — this codebase already solved this shape once, reuse it rather than invent a second one. |
| Storage | One new `json` column, `work_items.links`, nullable, cast to `array`. Not a separate table: it's a small bounded list with no need to query or join on individual links, same reasoning `TotSession` used. |
| Card face indicator | None. Links show only inside the drawer. The board face doesn't show description or due-project either without opening the card; a link-count badge is a separate ask nobody made. |

## 1. One-step add card

### Client, `resources/js/work-board.js`

Delete `toggleComposer` (794-797), `submitAdd` (799-825), the `draft`/`open` state
they use, and the inline textarea markup (`board.blade.php:119-140`) — dead code
once the new path replaces them, not left behind per the "remove orphans your own
change creates" rule.

New `addCard(status)`, replacing `submitAdd` at the same call site (the button's
`@click`):

```js
async addCard(status) {
    if (this.busy) return;
    this.busy = true;
    try {
        // Same type-from-active-filter rule submitAdd used, so a card added while
        // viewing one type of work stays visible under that filter.
        const type = ['task', 'assignment', 'adhoc'].includes(this.filter) ? this.filter : 'assignment';
        const title = this.t('Untitled card', 'Kad tanpa tajuk');
        const { card, html } = await this.api('/app/board', {
            method: 'POST',
            body: JSON.stringify({ title, status, type, priority: 'medium' }),
        });
        const list = this.$root.querySelector(`[data-list="${status}"]`);
        const empty = list.querySelector('[data-empty]');
        if (empty) empty.remove();
        list.insertAdjacentHTML('beforeend', html);
        this.playEnter(list.lastElementChild);
        this.applyFilter();
        await this.openCardCore(String(card.id), list.lastElementChild);
    } finally {
        this.busy = false;
    }
},
```

The card still lands in its column immediately (same `insertAdjacentHTML` +
`playEnter` as today), so closing the drawer without typing anything leaves a
real, visible "Untitled card" — same as clicking away from any other card mid-edit
leaves whatever was last autosaved. No draft/cancel concept is introduced; the
drawer's autosave already handles "user changed their mind halfway."

`openCardCore` needs no change — it already accepts any id plus an optional node
and does its own fetch, loading state and focus handling.

### Server

No change. `WorkItemController::store()` (`app/Http/Controllers/WorkItemController.php:35-67`)
already accepts exactly the fields sent (`title`, `type`, `priority`, `status`)
and already returns `card` (with `id`) and `html` on `expectsJson()`.

### Blade

`board.blade.php:121-124`: change the button's `@click="toggleComposer(...)"` to
`@click="addCard('{{ $key }}')"`. Delete the composer `<div>` (125-138) — the
textarea, its two buttons, and the block wrapper.

## 2. Links

### Migration

`database/migrations/2026_08_13_xxxxxx_add_links_to_work_items.php`:

```php
Schema::table('work_items', function (Blueprint $table) {
    $table->json('links')->nullable(); // [{label, url}]
});
```

Same shape as `TotSession.links` — no default rows, unlike Tot's two pre-labelled
slots; a `WorkItem` starts with none.

### Model, `app/Models/WorkItem.php`

Add `'links' => 'array'` to `casts()` (line 42). No relation, no constant — it's a
plain JSON array of `{label, url}`, same as Tot.

### Controller, `app/Http/Controllers/WorkItemController.php`

**`update()` (165-198)** — add the field to the `sometimes` set, same pattern as
`labels`:

```php
'links' => ['sometimes', 'array', 'max:12'],
'links.*.label' => ['required_with:links', 'string', 'max:60'],
'links.*.url' => ['required_with:links', 'url', 'max:2000'],
```

Before `$request->validate($rules)`, drop fully-blank rows exactly as
`TotController::store()` does at lines 218-223, using a new private
`isUntouchedLinkRow()` — simpler than Tot's version since `WorkItem` has no
pre-seeded default labels to also check for:

```php
private function isUntouchedLinkRow(array $link): bool
{
    return blank($link['label'] ?? null) && blank($link['url'] ?? null);
}
```

A row with only one of the two fields filled still fails validation (`required_with`)
and is reported as an error — only a row nobody touched at all is silently dropped.

**`cardPayload()` (594-623)** — add `'links' => $item->links ?? [],` alongside
`labels`.

### Client, `resources/js/work-board.js`

`openCardCore` (478-486): add `links: card.links ?? [],` to the `drawer.card`
assignment, alongside the existing `labels`/`participants` defaults.

Three new methods, following the existing `toggleLabel`/`addPerson` shape (local
mutation, then `commitField`) and the existing `scheduleCommit`/`commitFieldFromCard`
debounce shape used for `title`/`description`:

```js
addLink() {
    if (this.drawer.locked) return;
    this.drawer.card.links.push({ label: '', url: '' });
},

removeLink(idx) {
    if (this.drawer.locked) return;
    this.drawer.card.links.splice(idx, 1);
    this.commitField('links', this.drawer.card.links);
},

onLinkInput() {
    this.scheduleCommit('links');
},
```

`commitFieldFromCard` (323-337) gains a `links` branch: filter out fully-blank rows
from a *copy* before sending, same rule as the server's `isUntouchedLinkRow`, but
leave `drawer.card.links` itself untouched so a half-typed row (label filled, url
not yet) doesn't vanish from the screen mid-edit:

```js
if (field === 'links') {
    const filled = this.drawer.card.links.filter((l) => l.label.trim() || l.url.trim());
    this.commitField('links', filled);
    return;
}
```

`links` is not added to `FIELD_DERIVED` — nothing else on the card is computed
from it.

### Blade, `board.blade.php`

New section after Description (after line 308, before the `<hr class="wd-rule">`
at 310), matching the existing property-row/chip-row visual language:

```blade
<h3 class="wd-sech" x-text="$store.ui.lang==='en' ? 'Links' : 'Pautan'">Links</h3>
<template x-if="!drawer.locked">
    <div>
        <template x-for="(link, idx) in drawer.card.links" :key="idx">
            <div style="display:grid;grid-template-columns:140px 1fr 30px;gap:8px;margin-bottom:8px;">
                <input class="wd-inline" style="margin:0;" x-model="link.label" @input="onLinkInput()" @blur="commitFieldFromCard('links')" placeholder="Label" maxlength="60">
                <input class="wd-inline" style="margin:0;" x-model="link.url" @input="onLinkInput()" @blur="commitFieldFromCard('links')" placeholder="https://...">
                <button type="button" @click="removeLink(idx)" style="border:0;background:none;color:var(--muted);font-size:14px;cursor:pointer;">×</button>
            </div>
        </template>
        <button type="button" class="wd-add" @click="addLink()">
            <span x-text="$store.ui.lang==='en' ? '+ Add a link' : '+ Tambah pautan'"></span>
        </button>
    </div>
</template>
<template x-if="drawer.locked">
    <div class="wd-chiprow">
        <template x-for="link in drawer.card.links" :key="link.url">
            <a :href="link.url" target="_blank" rel="noopener noreferrer" class="wd-inline" x-text="link.label"></a>
        </template>
        <template x-if="!drawer.card.links.length">
            <span class="wd-inline wd-inline--empty" style="margin:0;padding-left:0;" x-text="$store.ui.lang==='en' ? 'None' : 'Tiada'"></span>
        </template>
    </div>
</template>
```

Read-only participants renders unescaped `x-text`, so `link.label` through
`x-text` is safe the same way; the `href` is set via Alpine's `:href` binding
(DOM property, not string-interpolated HTML), so no manual escaping is needed on
either side. `url` is still validated server-side as an actual URL
(`required_with:links` + Laravel's `url` rule), so a non-`http(s)` scheme like
`javascript:` is rejected before it ever reaches the database.

## Testing

Extending `tests/Feature/BoardCardTest.php`, following its existing style
(`test_owner_sets_labels_and_real_due_date` at line 178 is the closest match):

| Case | Expected |
|---|---|
| `store()` with a placeholder title, then `update()` with real fields | Same behaviour as today's `test_inline_add_returns_card_json` (95) — unchanged, since `store()` itself doesn't change. |
| Owner sets two links | `update()` returns them in `card.links`; `WorkItem::fresh()->links` matches. |
| A link row with only a label, no URL | Rejected (422), same as an unknown label key today (`test_unknown_label_key_is_rejected`, 260). |
| A fully blank link row mixed with a real one | Only the real one is saved; the blank one is silently dropped, not rejected. |
| More than 12 links | Rejected (422). |
| Locked participant (`test_locked_participant_can_move_and_comment_but_not_patch_properties`, 161) | `links` PATCH still refused, same as every other property field. |

No JS test suite exists for `work-board.js` today (verified by its absence from
the test list), so the client-side add-card flow is verified manually in the
browser per this repo's UI-change convention, not with an automated test.

## Rollout

Run `php artisan migrate` on the dev database after this lands — tests use a
separate database and green tests alone won't add the column to the one the app
actually runs against.

## Success criteria

1. Clicking "+ Add a card" in any column creates a card and opens its detail
   drawer in the same motion — no intermediate textarea, no second click.
2. The new card appears in its column immediately, titled "Untitled card" until
   edited.
3. A card can hold up to 12 `{label, url}` links, added/edited/removed from the
   drawer, autosaving like every other field.
4. A link with a missing label or a non-URL value is rejected with a clear error;
   an untouched blank row is silently dropped, not rejected.
5. A locked (read-only) viewer sees links as clickable buttons, not editable rows.
6. `php artisan test --compact tests/Feature/BoardCardTest.php` passes.
