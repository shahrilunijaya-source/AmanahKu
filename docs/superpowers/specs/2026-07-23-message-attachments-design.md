# Message attachments — design

**Date:** 2026-07-23
**Status:** Approved (design), pending spec review
**Author:** pembasmikuman (with Claude)

## Problem

In-app direct messages ([`MessageController`](../../../app/Http/Controllers/MessageController.php),
`conversations` + `messages` tables) are text-only. Employees want to attach images and
files to a message, on both surfaces the chat is shown:

- The **side panel** (slide-over from the header envelope, on every screen) —
  [`partials/messages-panel.blade.php`](../../../resources/views/partials/messages-panel.blade.php)
  driven by the `messagesPanel` Alpine component in
  [`layouts/app.blade.php`](../../../resources/views/layouts/app.blade.php).
- The **full page** `/app/messages` —
  [`screens/messages.blade.php`](../../../resources/views/screens/messages.blade.php).

Both are two views of the *same* 1-to-1 DM system, so attachments must work in both.

## Decisions (locked)

- **Surfaces:** both panel and full page.
- **Image-only allowed:** `body` is optional when at least one valid file is attached;
  otherwise still required. An empty body with no file is rejected.
- **File rules:** reuse the feedback/leave set exactly —
  `jpg,jpeg,png,gif,webp,pdf,doc,docx,xls,xlsx,txt,csv`, 8 MB each, max 6 per message.
- **Storage:** private `local` disk, streamed through an auth-gated route
  (participant-only). Never a public URL — a DM attachment is private between two people.

## Chosen approach

Mirror the existing attachment pattern already used twice in this codebase
([`FeedbackController::store`/`attachment`](../../../app/Http/Controllers/FeedbackController.php),
`feedback_attachments`, `leave` attachments). Rejected alternatives: a JSON column on
`messages` (no FK integrity, awkward per-file auth) and a public disk (leaks private DMs).

## Architecture

### Data

New table `message_attachments` (copy of `feedback_attachments`):

| column          | type                       | notes                                  |
|-----------------|----------------------------|----------------------------------------|
| id              | id                         |                                        |
| tenant_id       | FK tenants, cascade        | first FK, like every tenant table      |
| message_id      | FK messages, cascade       | indexed                                |
| path            | string                     | location on private `local` disk       |
| name            | string                     | original filename shown to humans      |
| mime            | string nullable            | drives image-vs-chip rendering         |
| size            | unsignedInteger default 0  | bytes                                  |
| timestamps      |                            |                                        |

`MessageAttachment` model: `BelongsToTenant`, `casts size => integer`,
`belongsTo(Message)`, `isImage()` (`str_starts_with(mime, 'image/')`).
`Message::attachments()` hasMany.

### Send flow — `MessageController::send()`

Validation additions:

```php
'body' => ['nullable', 'string', 'max:5000'],              // was required
'attachments' => ['nullable', 'array', 'max:6'],
'attachments.*' => ['file', 'mimes:'.MIMES, 'max:8192'],
```

Plus a guard: reject when `body` is blank **and** no valid file is present
(`422 'Write a message or attach a file.'`). This preserves "no empty messages"
while enabling image-only sends.

After the `Message` row is created (so a rejected batch cannot orphan files), loop
`$request->file('attachments', [])`, `store('message-attachments', 'local')`, and
create a `MessageAttachment` row per file (carrying `tenant_id` from the message).
Update `conversation.last_message_at`. Audit log unchanged in spirit.

### Serve — `MessageController::attachment()`

New action + route `GET /app/messages/attachments/{attachment}` (name
`messages.attachment`). Gate: tenant match (defence in depth over route-model binding)
**and** the viewer is one of the two participants of the attachment's conversation.
Stream inline via `Storage::disk('local')->response($path, $name)`. Non-participant → 403.

### Read shape — `messageArr()`

Add an `attachments` array to the single shaping method (covers all read paths:
`thread()`, `activePayload()`, and the `send()` JSON response):

```php
'attachments' => $message->attachments->map(fn ($a) => [
    'id' => $a->id, 'name' => $a->name, 'isImage' => $a->isImage(),
    'url' => route('messages.attachment', $a),
])->all(),
```

Eager-load `attachments` where messages are fetched for a thread. Both `thread()` and
`activePayload()` call `get(['id','sender_id','body','created_at'])` today — add
`->with('attachments')` to each (the selected `id` is enough for the hasMany to hydrate;
keep the column list). Bounded by `THREAD_LIMIT = 100`, so no N+1 or perf concern.
`context()` / the conversation list does **not** load attachments — it only needs the
snippet.

### List snippet — `mapConversation()`

When the latest message has no body but has attachments, snippet becomes "📎 Attachment"
(localized) instead of `null`/blank.

### UI

**Full page** ([`screens/messages.blade.php`](../../../resources/views/screens/messages.blade.php)):
- Composer `<form>` gains `enctype="multipart/form-data"` and a file input
  (`name="attachments[]"` multiple, `accept` matching the mimes).
- Bubbles render attachments: image → thumbnail `<img>` (links to the stream url, opens
  full); non-image → a file chip (name + download icon) linking to the stream url.

**Side panel** (`messagesPanel` Alpine in
[`layouts/app.blade.php`](../../../resources/views/layouts/app.blade.php) +
[`partials/messages-panel.blade.php`](../../../resources/views/partials/messages-panel.blade.php)):
- `send()` switches from `URLSearchParams` to `FormData` (drop the
  `Content-Type: x-www-form-urlencoded` header so the browser sets the multipart
  boundary). Append `body`, `conversation_id`/`to`, and each selected file.
- Add a file-picker button in the composer; track selected files in Alpine state; clear
  after a successful send; allow send when files present even if `body` is empty.
- Bubble template renders `m.attachments` (thumbnail / chip) like the full page.

## Error handling

- Oversize / wrong-type / too-many files → Laravel validation messages (reuse feedback's
  friendly overrides).
- Blank body + no file → 422 with a clear message.
- Missing file on disk at stream time → 404.
- Cross-tenant or non-participant fetch → 403.

## Testing (TDD)

Feature tests in [`tests/Feature/MessageTest.php`](../../../tests/Feature/MessageTest.php)
using `Storage::fake('local')` and `UploadedFile::fake()`:

1. Send with a text body + image → message row created, attachment row created, file
   stored on the fake disk, JSON response includes the attachment.
2. Image-only send (no body, one file) → succeeds.
3. Empty body + no file → 422, no message row.
4. Oversize file (>8 MB) → 422.
5. A non-participant employee fetching the attachment stream → 403; a participant → 200.

## Out of scope (YAGNI)

- Inline paste-to-upload in the composer (feedback has it; not requested here).
- Thumbnails/resizing/transcoding — serve originals.
- Deleting an attachment after send.
- Attachment support in any surface other than these two.
