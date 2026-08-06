# Knowledge Bank picture attachments

## Problem

Knowledge Bank lessons are text-only (`title`, `body`, `tags`). Authors want to attach pictures to a lesson, the way a Threads post carries images — up to 10 per entry, addable and removable at both create and edit time, shown as a grid in the feed with a fullscreen viewer on click.

## Storage & serving

Mirror the existing `MessageAttachment` pattern (`app/Models/MessageAttachment.php`, `database/migrations/2026_07_23_000001_create_message_attachments_table.php`) rather than inventing a new one:

- New table `knowledge_attachments`: `id`, `tenant_id` (FK, cascade delete), `entry_id` (FK to `knowledge_entries`, cascade delete), `path`, `name`, `mime`, `size`, `sort_order` (unsigned tinyint, default 0 — grid/lightbox order), timestamps. Index on `entry_id`.
- Files live on the private `local` disk under `knowledge-attachments/`, same as `message-attachments/`. Never a public URL.
- Reached only through a new tenant-gated stream route, `GET /app/knowledge-bank/attachments/{attachment}` → `KnowledgeController::attachment()`. Unlike messages (participant-only), a lesson is company-wide, so any employee in the same tenant may view it — the gate is `attachment->tenant_id === current tenant`, not a participant check.
- Pictures only: validation is `['image', 'mimes:jpeg,jpg,png,gif,webp', 'max:4096']` per file (4 MB, matching `AttendanceController`'s photo cap), array max 10.

## Model

`app/Models/KnowledgeAttachment.php`:
- `use BelongsToTenant;`
- `belongsTo(KnowledgeEntry::class, 'entry_id')`
- `$guarded = []`, `$casts = ['size' => 'integer', 'sort_order' => 'integer']`

`KnowledgeEntry`: add `attachments(): HasMany` (`orderBy('sort_order')`).

## Backend changes — `KnowledgeController`

- `store()`: validate `'images' => ['nullable', 'array', 'max:10']`, `'images.*' => ['image', 'mimes:jpeg,jpg,png,gif,webp', 'max:4096']`. After `KnowledgeEntry::create(...)`, loop the validated files (skip invalid, same discipline as `MessageController::store`) and create `KnowledgeAttachment` rows with `sort_order` = loop index. Store-then-attach happens after the entry exists, so a rejected batch never orphans files.
- `update()`: same `images`/`images.*` validation for new adds, plus `'remove_images' => ['nullable', 'array'], 'remove_images.*' => ['integer']`. Author already verified as entry owner earlier in the method (existing `abort_unless($entry->employee_id === $employee->id, ...)`), so:
  1. Delete attachments whose id is in `remove_images` AND belongs to this entry (defence in depth against a spoofed id from another entry) — delete both the DB row and the file on disk.
  2. Append newly uploaded images, `sort_order` continuing from `$entry->attachments()->max('sort_order') + 1`, capped so total (existing minus removed, plus new) never exceeds 10 — return a 422 if it would.
- New `attachment(Request $request, KnowledgeAttachment $attachment): StreamedResponse`: `abort_unless($employee, 403)`, `abort_unless($attachment->tenant_id === current tenant id, 403)`, `abort_unless(Storage::disk('local')->exists($attachment->path), 404)`, return `Storage::disk('local')->response($attachment->path, $attachment->name)` (inline, not `->download()`, so it renders in `<img>`/lightbox).
- `screenData()`: add `'attachments'` to the entry eager-load list, ordered by `sort_order`.

## Routes (`routes/web.php`, inside the existing knowledge-bank group)

```
Route::get('/app/knowledge-bank/attachments/{attachment}', [KnowledgeController::class, 'attachment'])->name('knowledge.attachments.show');
```

## Frontend

**Picker (create + edit forms).** New Alpine module `resources/js/knowledge-attach.js`, adapted from `resources/js/ticket-attach.js` but images-only (drop the PDF/doc branches, drop the paste-into-textarea hook — not asked for here): hidden `<input type="file" name="images[]" multiple accept="image/*">`, thumbnail preview strip with per-image remove (×) before submit, client-side cap at 10 and 4 MB matching the server, `error` state for type/size/max same as `ticket-attach.js`'s pattern. On the edit form, pre-seed the strip from the entry's existing attachments (rendered as `<img>` tiles hitting the stream route) with their own remove button that adds their id to a hidden `remove_images[]` list instead of touching the file input.

**Feed grid.** In `resources/views/screens/knowledge-bank.blade.php`, below the entry body: if `$e->attachments->isNotEmpty()`, render a grid — 1 image full width; 2+ in a responsive CSS grid capped at 4 visible tiles, last visible tile gets a `+N` overlay when more than 4 exist. Each tile is a `<button>` (not a link, keeps it inside the existing card without navigating) that opens a fullscreen lightbox.

**Lightbox.** Small new Alpine component (e.g. `x-data` block already local to the entry loop, or a shared component if the pattern shows up elsewhere later — start local, YAGNI) holding `{ open: false, images: [...], index: 0 }`. Click a tile → `open = true; index = <tile index>`. Renders a fixed-position overlay with the current image, prev/next arrows (disabled/hidden at the ends), a close button, and `@keydown.escape.window="open = false"`.

## Testing

New `tests/Feature/KnowledgeAttachmentTest.php`, structured like `MessageAttachmentTest.php`:
- `store()` accepts up to 10 valid images, rejects an 11th, rejects a non-image file, rejects an oversized file.
- `update()` can add images, remove images by id, and rejects removing/adding past the 10 cap.
- The stream route serves an attachment to any same-tenant employee, 403s for a different tenant's employee even with a guessed id (tenant-isolation regression per the existing "route-model binding isn't tenant-scoped" lesson in this codebase), 404s for a missing file on disk.
- Deleting a `KnowledgeEntry` cascades to its attachments (DB) — cascade is declared in the migration; a quick assertion confirms it, no need to separately test the disk file cleanup path beyond what `update()`'s removal test already covers.

## Out of scope

- No captions/alt text per image — not asked for.
- No drag-to-reorder in the picker — append order is the display order.
- No image resizing/compression on upload — 4 MB cap is the only guard, matching the existing attendance-photo precedent.
