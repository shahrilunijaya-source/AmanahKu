# Knowledge Bank picture attachments

## Problem

Knowledge Bank lessons are text-only (`title`, `body`, `tags`). Authors want to attach pictures to a lesson, the way a Threads post carries images — up to 10 per entry, addable and removable at both create and edit time, shown as a grid in the feed with a fullscreen viewer on click.

## Storage & serving

Mirror the existing `MessageAttachment` pattern (`app/Models/MessageAttachment.php`, `database/migrations/2026_07_23_000001_create_message_attachments_table.php`) rather than inventing a new one:

- New table `knowledge_attachments`: `id`, `tenant_id` (FK, cascade delete), `entry_id` (FK to `knowledge_entries`, cascade delete), `path`, `name`, `mime`, `size`, `caption` (nullable string, max 200 — doubles as alt text), `sort_order` (unsigned tinyint, default 0 — grid/lightbox order, drag-reorderable), timestamps. Index on `entry_id`.
- Files live on the private `local` disk under `knowledge-attachments/`, same as `message-attachments/`. Never a public URL.
- Reached only through a new tenant-gated stream route, `GET /app/knowledge-bank/attachments/{attachment}` → `KnowledgeController::attachment()`. Unlike messages (participant-only), a lesson is company-wide, so any employee in the same tenant may view it — the gate is `attachment->tenant_id === current tenant`, not a participant check.
- Pictures only: validation is `['image', 'mimes:jpeg,jpg,png,gif,webp', 'max:8192']` per file (8 MB upload ceiling before server-side compression shrinks it — see Compression below), array max 10.

## Compression

Server-side, on every accepted upload, before the file is persisted to disk — no new dependency, the `gd` PHP extension is already present (`php -m` confirms it):

- Decode with GD, resize only if the longest side exceeds 2000px (preserve aspect ratio), re-encode:
  - JPEG → `imagejpeg(..., quality: 82)` — visually near-lossless, typically 60-80% smaller than a phone-camera original.
  - PNG → `imagepngquant`-style isn't available without a new dependency, so re-save via `imagepng(..., compression: 6)` (GD's built-in zlib level, lossless) — no quality loss, modest size cut.
  - WebP → `imagewebp(..., quality: 82)`, same reasoning as JPEG.
  - GIF → left untouched (animation would break under GD re-encode).
- Store the re-encoded bytes, not the original upload; `size` column reflects the post-compression size.
- Small helper `App\Support\ImageCompressor::compress(string $path): void` (or similar), unit-testable in isolation, called from `KnowledgeController` after `$file->store(...)`. Ponytail note: this is the smallest thing that gets real size reduction without a new Composer dependency — if quality complaints come in later, swapping to Imagick (also already loaded per `php -m`) or adding Intervention Image is a contained change inside this one helper, not a rewrite.

## Model

`app/Models/KnowledgeAttachment.php`:
- `use BelongsToTenant;`
- `belongsTo(KnowledgeEntry::class, 'entry_id')`
- `$guarded = []`, `$casts = ['size' => 'integer', 'sort_order' => 'integer']`

`KnowledgeEntry`: add `attachments(): HasMany` (`orderBy('sort_order')`).

## Backend changes — `KnowledgeController`

- `store()`: validate `'images' => ['nullable', 'array', 'max:10']`, `'images.*' => ['image', 'mimes:jpeg,jpg,png,gif,webp', 'max:8192']`, `'captions' => ['nullable', 'array'], 'captions.*' => ['nullable', 'string', 'max:200']` (index-aligned with `images.*`). After `KnowledgeEntry::create(...)`, loop the validated files (skip invalid, same discipline as `MessageController::store`), compress each via `ImageCompressor::compress()`, and create `KnowledgeAttachment` rows with `sort_order` = loop index and `caption` = the matching `captions[i]` if present. Store-then-attach happens after the entry exists, so a rejected batch never orphans files.
- `update()`: same `images`/`images.*`/`captions`/`captions.*` validation for new adds, plus `'remove_images' => ['nullable', 'array'], 'remove_images.*' => ['integer']` and `'reorder' => ['nullable', 'array'], 'reorder.*' => ['integer']` (full ordered list of surviving attachment ids, sent by the drag-reorder widget). Author already verified as entry owner earlier in the method (existing `abort_unless($entry->employee_id === $employee->id, ...)`), so:
  1. Delete attachments whose id is in `remove_images` AND belongs to this entry (defence in depth against a spoofed id from another entry) — delete both the DB row and the file on disk.
  2. Append newly uploaded images (compressed, captioned) after the removals, `sort_order` continuing from the current max, capped so total (existing minus removed, plus new) never exceeds 10 — return a 422 if it would.
  3. If `reorder` is present, validate every id in it belongs to this entry, then write each attachment's `sort_order` to its position in the list — reject (422) if the id set doesn't exactly match the entry's surviving attachments, so a stale/tampered order never silently drops or duplicates a row.
  4. Existing attachments not touched by `remove_images`/`reorder` may still have their `caption` updated — accept an optional `'caption_updates' => ['nullable', 'array']` keyed by attachment id, same ownership check as removal.
- New `attachment(Request $request, KnowledgeAttachment $attachment): StreamedResponse`: `abort_unless($employee, 403)`, `abort_unless($attachment->tenant_id === current tenant id, 403)`, `abort_unless(Storage::disk('local')->exists($attachment->path), 404)`, return `Storage::disk('local')->response($attachment->path, $attachment->name)` (inline, not `->download()`, so it renders in `<img>`/lightbox).
- `screenData()`: add `'attachments'` to the entry eager-load list, ordered by `sort_order`.

## Routes (`routes/web.php`, inside the existing knowledge-bank group)

```
Route::get('/app/knowledge-bank/attachments/{attachment}', [KnowledgeController::class, 'attachment'])->name('knowledge.attachments.show');
```

## Frontend

**Picker (create + edit forms).** New Alpine module `resources/js/knowledge-attach.js`, adapted from `resources/js/ticket-attach.js` but images-only (drop the PDF/doc branches, drop the paste-into-textarea hook — not asked for here): hidden `<input type="file" name="images[]" multiple accept="image/*">`, thumbnail preview strip with per-image remove (×) before submit, client-side cap at 10 and 8 MB matching the server, `error` state for type/size/max same as `ticket-attach.js`'s pattern. Each thumbnail gets a small text input underneath for its caption, bound into a parallel `captions[]` array so index alignment survives reordering and removal. On the edit form, pre-seed the strip from the entry's existing attachments (rendered as `<img>` tiles hitting the stream route, `alt` = caption) with their own remove button that adds their id to a hidden `remove_images[]` list instead of touching the file input, and their caption input bound into `caption_updates[id]`.

Drag-to-reorder uses `sortablejs` (already a dependency, already used the same way in `resources/js/work-board.js`): `window.Sortable.create()` on the thumbnail strip container, `onEnd` rebuilds the ordered array of attachment/temp ids and syncs a hidden `reorder[]` input (existing attachments) — new, not-yet-persisted uploads reorder within the client-side `files` array only, their final position becomes their `sort_order` on submit since `store()`/new adds in `update()` use loop-index order.

**Feed grid.** In `resources/views/screens/knowledge-bank.blade.php`, below the entry body: if `$e->attachments->isNotEmpty()`, render a grid — 1 image full width; 2+ in a responsive CSS grid capped at 4 visible tiles, last visible tile gets a `+N` overlay when more than 4 exist. Each tile is a `<button>` (not a link, keeps it inside the existing card without navigating), `<img alt="{{ $att->caption }}">` falling back to the entry title when no caption is set, that opens a fullscreen lightbox.

**Lightbox.** Small new Alpine component (e.g. `x-data` block already local to the entry loop, or a shared component if the pattern shows up elsewhere later — start local, YAGNI) holding `{ open: false, images: [...], index: 0 }` where each image carries its `caption`. Click a tile → `open = true; index = <tile index>`. Renders a fixed-position overlay with the current image, its caption as a visible line under it (also used as the `<img alt>`), prev/next arrows (disabled/hidden at the ends), a close button, and `@keydown.escape.window="open = false"`.

## Testing

New `tests/Feature/KnowledgeAttachmentTest.php`, structured like `MessageAttachmentTest.php`:
- `store()` accepts up to 10 valid images with captions, rejects an 11th, rejects a non-image file, rejects an oversized file, persists captions aligned to the right image.
- `update()` can add images, remove images by id, reorder via `reorder[]`, update captions via `caption_updates`, and rejects removing/adding past the 10 cap and a `reorder` payload whose id set doesn't match the entry's surviving attachments.
- `ImageCompressor::compress()` unit test: a large-dimension/high-byte-size fixture image shrinks after compression while remaining a valid, openable image (decode after compress and assert dimensions/format).
- The stream route serves an attachment to any same-tenant employee, 403s for a different tenant's employee even with a guessed id (tenant-isolation regression per the existing "route-model binding isn't tenant-scoped" lesson in this codebase), 404s for a missing file on disk.
- Deleting a `KnowledgeEntry` cascades to its attachments (DB) — cascade is declared in the migration; a quick assertion confirms it, no need to separately test the disk file cleanup path beyond what `update()`'s removal test already covers.

## Out of scope

- No client-visible EXIF/orientation editing beyond what GD's re-encode naturally normalizes.
- No per-image alt text distinct from caption — one field serves both, matching how Threads-style UIs treat it.
