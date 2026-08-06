# Knowledge Bank Picture Attachments Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a Knowledge Bank lesson carry up to 10 pictures (add/remove/reorder/caption at create and edit time), server-compressed on upload, shown as a grid in the entry list and a fullscreen lightbox in the detail drawer.

**Architecture:** Mirrors the existing `MessageAttachment` pattern — a child `knowledge_attachments` table on the private `local` disk, reached only through a tenant-gated stream route. A new `App\Support\ImageCompressor` (GD, no new dependency) shrinks every accepted upload before it's persisted. Frontend reuses `resources/js/ticket-attach.js`'s picker shape (images-only variant) and the already-installed `sortablejs` for drag-reorder (same call shape as `resources/js/work-board.js`).

**Tech Stack:** Laravel 13 / PHP 8.5, GD extension (compression), Alpine.js, SortableJS (already a package.json dependency), PHPUnit.

## Global Constraints

- Pictures only: `image`, mimes `jpeg,jpg,png,gif,webp`, max 8 MB per upload (pre-compression), max 10 per entry.
- Compression: resize only if longest side > 2000px; JPEG/WebP re-encode quality 82; PNG re-encode lossless (`imagepng` compression level 6); GIF untouched (animation-safe).
- Files never get a public URL — always served through the tenant-gated stream route.
- `caption` is nullable, max 200 chars, doubles as `<img alt>`.
- No new Composer or npm dependencies.
- Run `vendor/bin/pint --dirty --format agent` after PHP edits in each task before committing.
- Every task's tests run via `php artisan test --compact --filter=<Test>` before commit.

---

### Task 1: `knowledge_attachments` table, model, and `KnowledgeEntry::attachments()`

**Files:**
- Create: `database/migrations/2026_08_06_000001_create_knowledge_attachments_table.php`
- Create: `app/Models/KnowledgeAttachment.php`
- Modify: `app/Models/KnowledgeEntry.php`
- Test: `tests/Feature/KnowledgeAttachmentTest.php` (created here, extended by later tasks)

**Interfaces:**
- Produces: `KnowledgeAttachment` model with columns `id, tenant_id, entry_id, path, name, mime, size, caption, sort_order, created_at, updated_at`; `belongsTo(KnowledgeEntry::class, 'entry_id')` relation named `entry()`. `KnowledgeEntry::attachments(): HasMany` ordered by `sort_order`.

- [ ] **Step 1: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A picture attached to a Knowledge Bank lesson. Files live on the private 'local'
        // disk and are only ever reached through KnowledgeController::attachment (tenant-gated
        // stream), never a public URL. Mirrors message_attachments; unlike messages a lesson
        // is company-wide, so the gate is tenant membership, not participant membership.
        Schema::create('knowledge_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('entry_id')->constrained('knowledge_entries')->cascadeOnDelete();
            $table->string('path');                          // location on the private 'local' disk
            $table->string('name');                           // original filename shown to humans
            $table->string('mime')->nullable();
            $table->unsignedInteger('size')->default(0);      // bytes, post-compression
            $table->string('caption', 200)->nullable();       // doubles as <img alt>
            $table->unsignedTinyInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('entry_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_attachments');
    }
};
```

- [ ] **Step 2: Run the migration on the test DB path (RefreshDatabase runs migrations automatically, so just verify it's syntactically sound)**

Run: `php artisan migrate --pretend | grep -i knowledge_attachments`
Expected: prints the `create table` SQL for `knowledge_attachments` with no errors.

- [ ] **Step 3: Write the model**

```php
<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A picture attached to a Knowledge Bank lesson. Files live on the private 'local' disk
 * and are only ever reached through KnowledgeController::attachment (tenant-gated stream),
 * never a public URL.
 */
class KnowledgeAttachment extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['size' => 'integer', 'sort_order' => 'integer'];
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(KnowledgeEntry::class, 'entry_id');
    }
}
```

- [ ] **Step 4: Add the relation to `KnowledgeEntry`**

In `app/Models/KnowledgeEntry.php`, add the `HasMany` import is already present; add after the existing `reactions()` method:

```php
    public function attachments(): HasMany
    {
        return $this->hasMany(KnowledgeAttachment::class, 'entry_id')->orderBy('sort_order');
    }
```

- [ ] **Step 5: Write the failing model test**

Create `tests/Feature/KnowledgeAttachmentTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\KnowledgeAttachment;
use App\Models\KnowledgeEntry;
use App\Models\KnowledgeSegment;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class KnowledgeAttachmentTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $author;

    private Employee $authorEmployee;

    private User $other;

    private Employee $otherEmployee;

    private KnowledgeSegment $segment;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        app(CurrentTenant::class)->set($this->tenant);

        $this->author = User::create(['name' => 'Author', 'email' => 'author@example.com', 'password' => Hash::make('password')]);
        $this->author->tenants()->attach($this->tenant->id, ['role' => 'employee']);
        $this->authorEmployee = Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $this->author->id,
            'name' => 'Author', 'status' => 'active', 'workload' => 'green',
        ]);

        $this->other = User::create(['name' => 'Other', 'email' => 'other@example.com', 'password' => Hash::make('password')]);
        $this->other->tenants()->attach($this->tenant->id, ['role' => 'employee']);
        $this->otherEmployee = Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $this->other->id,
            'name' => 'Other', 'status' => 'active', 'workload' => 'green',
        ]);

        $this->segment = KnowledgeSegment::create([
            'tenant_id' => $this->tenant->id, 'label' => 'Lessons', 'sort_order' => 1,
        ]);
    }

    private function actingInTenant(?User $as = null): self
    {
        $this->actingAs($as ?? $this->author)->withSession(['current_tenant' => $this->tenant->id]);

        return $this;
    }

    public function test_entry_has_many_ordered_attachments(): void
    {
        $entry = KnowledgeEntry::create([
            'tenant_id' => $this->tenant->id, 'seg_id' => $this->segment->id, 'employee_id' => $this->authorEmployee->id,
            'title' => 'T', 'body' => 'B',
        ]);
        $entry->attachments()->create(['tenant_id' => $this->tenant->id, 'path' => 'a.jpg', 'name' => 'a.jpg', 'mime' => 'image/jpeg', 'size' => 1, 'sort_order' => 1]);
        $entry->attachments()->create(['tenant_id' => $this->tenant->id, 'path' => 'b.jpg', 'name' => 'b.jpg', 'mime' => 'image/jpeg', 'size' => 1, 'sort_order' => 0]);

        $ordered = $entry->attachments()->pluck('path')->all();
        $this->assertSame(['b.jpg', 'a.jpg'], $ordered);
    }
}
```

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test --compact --filter=KnowledgeAttachmentTest`
Expected: PASS (1 test)

- [ ] **Step 7: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add database/migrations/2026_08_06_000001_create_knowledge_attachments_table.php app/Models/KnowledgeAttachment.php app/Models/KnowledgeEntry.php tests/Feature/KnowledgeAttachmentTest.php
git commit -m "feat(knowledge-bank): add knowledge_attachments table and model"
```

---

### Task 2: `ImageCompressor` helper

**Files:**
- Create: `app/Support/ImageCompressor.php`
- Test: `tests/Unit/ImageCompressorTest.php`

**Interfaces:**
- Produces: `App\Support\ImageCompressor::compress(string $absolutePath, string $mime): void` — reads the file at `$absolutePath`, resizes if needed, re-encodes in place, overwriting the original bytes. No return value; throws nothing on unsupported mime (silently no-ops), since validation upstream already guarantees an accepted image mime.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit;

use App\Support\ImageCompressor;
use Tests\TestCase;

class ImageCompressorTest extends TestCase
{
    private string $path;

    protected function tearDown(): void
    {
        if (isset($this->path) && file_exists($this->path)) {
            unlink($this->path);
        }
        parent::tearDown();
    }

    public function test_large_jpeg_is_resized_and_shrunk(): void
    {
        $im = imagecreatetruecolor(3000, 1500);
        imagefill($im, 0, 0, imagecolorallocate($im, 200, 50, 50));
        $this->path = tempnam(sys_get_temp_dir(), 'kbimg').'.jpg';
        imagejpeg($im, $this->path, 100);
        imagedestroy($im);

        $originalSize = filesize($this->path);

        ImageCompressor::compress($this->path, 'image/jpeg');

        [$width, $height] = getimagesize($this->path);
        $this->assertSame(2000, $width);
        $this->assertSame(1000, $height);
        $this->assertLessThan($originalSize, filesize($this->path));
    }

    public function test_small_image_is_not_upscaled(): void
    {
        $im = imagecreatetruecolor(400, 300);
        imagefill($im, 0, 0, imagecolorallocate($im, 10, 10, 10));
        $this->path = tempnam(sys_get_temp_dir(), 'kbimg').'.jpg';
        imagejpeg($im, $this->path, 100);
        imagedestroy($im);

        ImageCompressor::compress($this->path, 'image/jpeg');

        [$width, $height] = getimagesize($this->path);
        $this->assertSame(400, $width);
        $this->assertSame(300, $height);
    }

    public function test_gif_is_left_untouched(): void
    {
        $im = imagecreate(10, 10);
        imagecolorallocate($im, 0, 0, 0);
        $this->path = tempnam(sys_get_temp_dir(), 'kbimg').'.gif';
        imagegif($im, $this->path);
        imagedestroy($im);

        $before = file_get_contents($this->path);
        ImageCompressor::compress($this->path, 'image/gif');
        $after = file_get_contents($this->path);

        $this->assertSame($before, $after);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=ImageCompressorTest`
Expected: FAIL — class `App\Support\ImageCompressor` not found.

- [ ] **Step 3: Write the implementation**

```php
<?php

namespace App\Support;

/**
 * Server-side compression for uploaded Knowledge Bank pictures. No new dependency — the
 * `gd` PHP extension is already loaded. Resizes only if the longest side exceeds 2000px
 * (aspect ratio preserved) and re-encodes: JPEG/WebP at quality 82 (visually near-lossless,
 * typically 60-80% smaller than a phone-camera original), PNG losslessly at zlib level 6.
 * GIFs are left untouched — a GD re-encode would drop animation frames.
 *
 * ponytail: GD is the smallest thing that gets real size reduction without a new Composer
 * dependency. If quality complaints come in, Imagick (also already loaded) or Intervention
 * Image is a contained swap inside this one class, not a rewrite.
 */
class ImageCompressor
{
    private const MAX_DIMENSION = 2000;

    private const QUALITY = 82;

    public static function compress(string $absolutePath, string $mime): void
    {
        $image = match ($mime) {
            'image/jpeg', 'image/jpg' => @imagecreatefromjpeg($absolutePath),
            'image/png' => @imagecreatefrompng($absolutePath),
            'image/webp' => @imagecreatefromwebp($absolutePath),
            default => null,
        };

        if ($image === null || $image === false) {
            return;
        }

        $width = imagesx($image);
        $height = imagesy($image);

        if (max($width, $height) > self::MAX_DIMENSION) {
            $scale = self::MAX_DIMENSION / max($width, $height);
            $newWidth = (int) round($width * $scale);
            $newHeight = (int) round($height * $scale);

            $resized = imagecreatetruecolor($newWidth, $newHeight);
            imagealphablending($resized, false);
            imagesavealpha($resized, true);
            imagecopyresampled($resized, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
            imagedestroy($image);
            $image = $resized;
        }

        match ($mime) {
            'image/jpeg', 'image/jpg' => imagejpeg($image, $absolutePath, self::QUALITY),
            'image/png' => imagepng($image, $absolutePath, 6),
            'image/webp' => imagewebp($image, $absolutePath, self::QUALITY),
            default => null,
        };

        imagedestroy($image);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact --filter=ImageCompressorTest`
Expected: PASS (3 tests)

- [ ] **Step 5: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Support/ImageCompressor.php tests/Unit/ImageCompressorTest.php
git commit -m "feat(knowledge-bank): add GD-based image compression helper"
```

---

### Task 3: `store()` accepts pictures + captions

**Files:**
- Modify: `app/Http/Controllers/KnowledgeController.php:182-221` (`store()`)
- Test: `tests/Feature/KnowledgeAttachmentTest.php` (add cases)

**Interfaces:**
- Consumes: `App\Support\ImageCompressor::compress(string $absolutePath, string $mime): void` (Task 2), `App\Models\KnowledgeAttachment` (Task 1).
- Produces: `store()` now persists up to 10 `KnowledgeAttachment` rows per entry with `sort_order` = submission order and `caption` from the index-aligned `captions[]` input.

- [ ] **Step 1: Write the failing tests**

Add to `tests/Feature/KnowledgeAttachmentTest.php`:

```php
    public function test_store_accepts_images_with_captions_in_order(): void
    {
        $this->actingInTenant()->post('/app/knowledge-bank', [
            'seg_id' => $this->segment->id,
            'title' => 'Lesson with pictures',
            'body' => 'Body text.',
            'images' => [
                UploadedFile::fake()->image('first.jpg', 100, 100),
                UploadedFile::fake()->image('second.jpg', 100, 100),
            ],
            'captions' => ['First caption', ''],
        ])->assertRedirect();

        $entry = KnowledgeEntry::where('title', 'Lesson with pictures')->firstOrFail();
        $this->assertSame(2, $entry->attachments()->count());

        $attachments = $entry->attachments()->orderBy('sort_order')->get();
        $this->assertSame('First caption', $attachments[0]->caption);
        $this->assertNull($attachments[1]->caption);
        $this->assertSame(0, $attachments[0]->sort_order);
        $this->assertSame(1, $attachments[1]->sort_order);
        Storage::disk('local')->assertExists($attachments[0]->path);
    }

    public function test_store_rejects_an_eleventh_image(): void
    {
        $images = [];
        for ($i = 0; $i < 11; $i++) {
            $images[] = UploadedFile::fake()->image("img{$i}.jpg", 50, 50);
        }

        $this->actingInTenant()->post('/app/knowledge-bank', [
            'seg_id' => $this->segment->id,
            'title' => 'Too many',
            'body' => 'Body text.',
            'images' => $images,
        ])->assertSessionHasErrors('images');

        $this->assertSame(0, KnowledgeEntry::where('title', 'Too many')->count());
    }

    public function test_store_rejects_a_non_image_file(): void
    {
        $this->actingInTenant()->post('/app/knowledge-bank', [
            'seg_id' => $this->segment->id,
            'title' => 'Bad file',
            'body' => 'Body text.',
            'images' => [UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf')],
        ])->assertSessionHasErrors('images.0');

        $this->assertSame(0, KnowledgeEntry::where('title', 'Bad file')->count());
    }

    public function test_store_rejects_an_oversized_image(): void
    {
        $this->actingInTenant()->post('/app/knowledge-bank', [
            'seg_id' => $this->segment->id,
            'title' => 'Too big',
            'body' => 'Body text.',
            'images' => [UploadedFile::fake()->create('huge.jpg', 9000)->size(9000)],
        ])->assertSessionHasErrors('images.0');

        $this->assertSame(0, KnowledgeEntry::where('title', 'Too big')->count());
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact --filter=KnowledgeAttachmentTest`
Expected: FAIL — `images` field not validated/stored, entries created without attachments, or validation missing (first test fails on attachment count assertion).

- [ ] **Step 3: Implement in `KnowledgeController`**

Add imports at the top of `app/Http/Controllers/KnowledgeController.php`:

```php
use App\Models\KnowledgeAttachment;
use App\Support\ImageCompressor;
use Illuminate\Http\StreamedResponse;
use Illuminate\Support\Facades\Storage;
```

Add a private constant near the existing ones (`PAGE_SIZE`, etc.):

```php
    /** Pictures per lesson. */
    private const MAX_IMAGES = 10;

    /** Private disk pictures live on — reached only via attachment(). */
    private const ATTACHMENT_DISK = 'local';

    private const IMAGE_MIMES = 'jpeg,jpg,png,gif,webp';

    private const IMAGE_MAX_KB = 8192;
```

Replace the `store()` validation array (currently `'tags' => ['nullable', 'string', 'max:200'],`) by adding two rules right after it, and add the store loop after entry creation:

```php
    public function store(Request $request): RedirectResponse
    {
        $employee = $request->attributes->get('employee');
        abort_unless($employee, 403, 'No employee profile in this workspace.');

        $segIds = $this->segmentIds();
        $data = $request->validate([
            'seg_id' => ['required', Rule::in($segIds)],
            'subseg_id' => ['nullable', Rule::in($segIds)],
            'title' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:5000'],
            'tags' => ['nullable', 'string', 'max:200'],
            'images' => ['nullable', 'array', 'max:'.self::MAX_IMAGES],
            'images.*' => ['image', 'mimes:'.self::IMAGE_MIMES, 'max:'.self::IMAGE_MAX_KB],
            'captions' => ['nullable', 'array'],
            'captions.*' => ['nullable', 'string', 'max:200'],
        ], [
            'images.max' => 'You can attach up to '.self::MAX_IMAGES.' pictures.',
            'images.*.image' => 'Attachments must be pictures.',
            'images.*.mimes' => 'Pictures must be JPG, PNG, GIF, or WebP.',
            'images.*.max' => 'Each picture must be 8 MB or smaller.',
        ]);

        $tags = collect(explode(',', (string) ($data['tags'] ?? '')))
            ->map(fn ($t) => trim($t))->filter()->values()->all();

        $entry = KnowledgeEntry::create([
            'seg_id' => $data['seg_id'],
            'subseg_id' => $data['subseg_id'] ?? null,
            'employee_id' => $employee->id,
            'title' => $data['title'],
            'body' => $data['body'],
            'tags' => $tags ?: null,
        ]);

        $this->storeImages($entry, (array) $request->file('images', []), (array) ($data['captions'] ?? []));

        // Mark this calendar month's contribution as fulfilled (clears the reminder
        // and turns the panel banner green). Author never "owes" on entries they
        // just wrote — mark the entry as read for them too.
        $this->markContributed($employee);
        $this->forgetStatsCache();
        KnowledgeRead::firstOrCreate(
            ['employee_id' => $employee->id, 'entry_id' => $entry->id],
            ['read_at' => now()],
        );

        AuditLog::record('Shared a lesson', $entry->title);

        return back()->with('ok', 'Lesson shared with the company — "'.$entry->title.'".');
    }

    /**
     * Persist validated image uploads as ordered, captioned KnowledgeAttachment rows,
     * compressing each before it lands on disk. Called after the entry already exists so a
     * rejected batch can never orphan files.
     *
     * @param  array<int, \Illuminate\Http\UploadedFile|null>  $files
     * @param  array<int, string|null>  $captions
     */
    private function storeImages(KnowledgeEntry $entry, array $files, array $captions, int $startOrder = 0): void
    {
        $order = $startOrder;
        foreach (array_values($files) as $i => $file) {
            if (! $file || ! $file->isValid()) {
                continue;
            }

            $path = $file->store('knowledge-attachments', self::ATTACHMENT_DISK);
            abort_unless($path !== false, 500, 'Picture could not be stored.');

            ImageCompressor::compress(Storage::disk(self::ATTACHMENT_DISK)->path($path), (string) $file->getMimeType());

            $entry->attachments()->create([
                'tenant_id' => $entry->tenant_id,
                'path' => $path,
                'name' => $file->getClientOriginalName() ?: 'picture',
                'mime' => $file->getMimeType(),
                'size' => Storage::disk(self::ATTACHMENT_DISK)->size($path),
                'caption' => trim((string) ($captions[$i] ?? '')) ?: null,
                'sort_order' => $order,
            ]);
            $order++;
        }
    }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --compact --filter=KnowledgeAttachmentTest`
Expected: PASS (all tests so far)

- [ ] **Step 5: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/KnowledgeController.php tests/Feature/KnowledgeAttachmentTest.php
git commit -m "feat(knowledge-bank): accept and compress pictures on lesson creation"
```

---

### Task 4: `update()` supports add / remove / reorder / caption edits

**Files:**
- Modify: `app/Http/Controllers/KnowledgeController.php:224-254` (`update()`)
- Test: `tests/Feature/KnowledgeAttachmentTest.php` (add cases)

**Interfaces:**
- Consumes: `storeImages()` (Task 3, extended with `$startOrder`), `KnowledgeAttachment`.
- Produces: `update()` accepts `images[]`/`captions[]` (new adds), `remove_images[]` (ids to delete), `reorder[]` (full ordered id list of survivors), `caption_updates` (id-keyed map) — same request, since the picker submits one form.

- [ ] **Step 1: Write the failing tests**

Add to `tests/Feature/KnowledgeAttachmentTest.php`:

```php
    private function entryWithImages(int $count = 2): KnowledgeEntry
    {
        $entry = KnowledgeEntry::create([
            'tenant_id' => $this->tenant->id, 'seg_id' => $this->segment->id, 'employee_id' => $this->authorEmployee->id,
            'title' => 'Original', 'body' => 'Original body.',
        ]);
        for ($i = 0; $i < $count; $i++) {
            $entry->attachments()->create([
                'tenant_id' => $this->tenant->id, 'path' => "knowledge-attachments/img{$i}.jpg",
                'name' => "img{$i}.jpg", 'mime' => 'image/jpeg', 'size' => 10, 'sort_order' => $i,
            ]);
        }

        return $entry;
    }

    public function test_update_can_add_and_remove_images(): void
    {
        $entry = $this->entryWithImages(2);
        $keep = $entry->attachments()->first();
        $remove = $entry->attachments()->skip(1)->first();

        $this->actingInTenant()->putJson("/app/knowledge-bank/{$entry->id}", [
            'title' => 'Original', 'body' => 'Original body.',
            'images' => [UploadedFile::fake()->image('new.jpg', 50, 50)],
            'remove_images' => [$remove->id],
        ])->assertOk();

        $this->assertDatabaseMissing('knowledge_attachments', ['id' => $remove->id]);
        $this->assertDatabaseHas('knowledge_attachments', ['id' => $keep->id]);
        $this->assertSame(2, $entry->attachments()->count());
    }

    public function test_update_rejects_exceeding_the_cap(): void
    {
        $entry = $this->entryWithImages(9);
        $images = [UploadedFile::fake()->image('a.jpg', 20, 20), UploadedFile::fake()->image('b.jpg', 20, 20)];

        $this->actingInTenant()->putJson("/app/knowledge-bank/{$entry->id}", [
            'title' => 'Original', 'body' => 'Original body.',
            'images' => $images,
        ])->assertStatus(422);

        $this->assertSame(9, $entry->attachments()->count());
    }

    public function test_update_reorders_existing_images(): void
    {
        $entry = $this->entryWithImages(2);
        $ids = $entry->attachments()->orderBy('sort_order')->pluck('id')->all();
        $reversed = array_reverse($ids);

        $this->actingInTenant()->putJson("/app/knowledge-bank/{$entry->id}", [
            'title' => 'Original', 'body' => 'Original body.',
            'reorder' => $reversed,
        ])->assertOk();

        $this->assertSame($reversed, $entry->attachments()->orderBy('sort_order')->pluck('id')->all());
    }

    public function test_update_rejects_reorder_with_mismatched_id_set(): void
    {
        $entry = $this->entryWithImages(2);
        $ids = $entry->attachments()->pluck('id')->all();

        $this->actingInTenant()->putJson("/app/knowledge-bank/{$entry->id}", [
            'title' => 'Original', 'body' => 'Original body.',
            'reorder' => [$ids[0], 999999],
        ])->assertStatus(422);
    }

    public function test_update_can_edit_captions(): void
    {
        $entry = $this->entryWithImages(1);
        $att = $entry->attachments()->first();

        $this->actingInTenant()->putJson("/app/knowledge-bank/{$entry->id}", [
            'title' => 'Original', 'body' => 'Original body.',
            'caption_updates' => [$att->id => 'New caption'],
        ])->assertOk();

        $this->assertSame('New caption', $att->fresh()->caption);
    }

    public function test_another_employee_cannot_touch_the_lessons_images(): void
    {
        $entry = $this->entryWithImages(1);
        $att = $entry->attachments()->first();

        $this->actingInTenant($this->other)->putJson("/app/knowledge-bank/{$entry->id}", [
            'title' => 'Hijack', 'body' => 'Hijack body.',
            'remove_images' => [$att->id],
        ])->assertForbidden();

        $this->assertDatabaseHas('knowledge_attachments', ['id' => $att->id]);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact --filter=KnowledgeAttachmentTest`
Expected: FAIL — `update()` has no image handling yet.

- [ ] **Step 3: Extend `storeImages()` to accept a start order, then implement `update()`**

Change the `storeImages()` signature call site is already parameterized (`$startOrder = 0` default from Task 3) — no change needed there. Replace `update()`:

```php
    /** The author may edit their own entry's title/body/tags/pictures at any time. */
    public function update(Request $request, KnowledgeEntry $entry): RedirectResponse|JsonResponse
    {
        $employee = $request->attributes->get('employee');
        abort_unless($employee, 403, 'No employee profile in this workspace.');
        abort_unless($entry->tenant_id === app(CurrentTenant::class)->id(), 403);
        abort_unless($entry->employee_id === $employee->id, 403, 'You can only edit your own lesson.');

        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:5000'],
            'tags' => ['nullable', 'string', 'max:200'],
            'images' => ['nullable', 'array'],
            'images.*' => ['image', 'mimes:'.self::IMAGE_MIMES, 'max:'.self::IMAGE_MAX_KB],
            'captions' => ['nullable', 'array'],
            'captions.*' => ['nullable', 'string', 'max:200'],
            'remove_images' => ['nullable', 'array'],
            'remove_images.*' => ['integer'],
            'reorder' => ['nullable', 'array'],
            'reorder.*' => ['integer'],
            'caption_updates' => ['nullable', 'array'],
            'caption_updates.*' => ['nullable', 'string', 'max:200'],
        ], [
            'images.*.image' => 'Attachments must be pictures.',
            'images.*.mimes' => 'Pictures must be JPG, PNG, GIF, or WebP.',
            'images.*.max' => 'Each picture must be 8 MB or smaller.',
        ]);

        $tags = collect(explode(',', (string) ($data['tags'] ?? '')))
            ->map(fn ($t) => trim($t))->filter()->values()->all();

        $entry->update([
            'title' => $data['title'],
            'body' => $data['body'],
            'tags' => $tags ?: null,
        ]);

        $removeIds = array_map('intval', $data['remove_images'] ?? []);
        if ($removeIds !== []) {
            $toRemove = $entry->attachments()->whereIn('id', $removeIds)->get();
            foreach ($toRemove as $att) {
                Storage::disk(self::ATTACHMENT_DISK)->delete($att->path);
                $att->delete();
            }
        }

        $newFiles = array_values(array_filter((array) $request->file('images', []), fn ($f) => $f && $f->isValid()));
        $remainingCount = $entry->attachments()->count();
        abort_if($remainingCount + count($newFiles) > self::MAX_IMAGES, 422, 'A lesson can carry up to '.self::MAX_IMAGES.' pictures.');

        if ($newFiles !== []) {
            $startOrder = (int) ($entry->attachments()->max('sort_order') ?? -1) + 1;
            $this->storeImages($entry, $newFiles, (array) ($data['captions'] ?? []), $startOrder);
        }

        if (! empty($data['reorder'])) {
            $reorder = array_map('intval', $data['reorder']);
            $survivingIds = $entry->attachments()->pluck('id')->sort()->values()->all();
            $requestedIds = collect($reorder)->sort()->values()->all();
            abort_unless($survivingIds === $requestedIds, 422, 'The picture order no longer matches this lesson\'s pictures.');

            foreach ($reorder as $position => $id) {
                $entry->attachments()->where('id', $id)->update(['sort_order' => $position]);
            }
        }

        if (! empty($data['caption_updates'])) {
            foreach ($data['caption_updates'] as $id => $caption) {
                $entry->attachments()->where('id', (int) $id)->update(['caption' => trim((string) $caption) ?: null]);
            }
        }

        $this->forgetStatsCache();
        AuditLog::record('Edited a lesson', $entry->title);

        if ($request->expectsJson()) {
            return response()->json([
                'title' => $entry->title,
                'body' => $entry->body,
                'tags' => $entry->tags,
                'attachments' => $entry->attachments()->get()->map(fn (KnowledgeAttachment $a) => [
                    'id' => $a->id,
                    'url' => route('knowledge.attachments.show', $a),
                    'caption' => $a->caption,
                ]),
            ]);
        }

        return back()->with('ok', 'Lesson updated.');
    }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --compact --filter=KnowledgeAttachmentTest`
Expected: PASS (all tests so far). Note: this task references `route('knowledge.attachments.show', ...)`, which doesn't exist until Task 5 — run this task's tests with `KnowledgeEntryUpdateTest` too since `update()` was touched:

Run: `php artisan test --compact --filter=KnowledgeEntryUpdateTest`
Expected: PASS (pre-existing tests still pass — title/body edit path unchanged)

If the route-name reference fails resolution in a test that hits `expectsJson()`, that's expected until Task 5 lands the route; the tests added in this task use `putJson` without asserting the JSON attachments payload shape, so this is safe to land now — Task 5 completes the route.

- [ ] **Step 5: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/KnowledgeController.php tests/Feature/KnowledgeAttachmentTest.php
git commit -m "feat(knowledge-bank): support add/remove/reorder/caption edits on lesson pictures"
```

---

### Task 5: Tenant-gated attachment stream route

**Files:**
- Modify: `app/Http/Controllers/KnowledgeController.php` (add `attachment()` method)
- Modify: `routes/web.php:332-340` (add route inside the existing knowledge-bank group)
- Test: `tests/Feature/KnowledgeAttachmentTest.php` (add cases)

**Interfaces:**
- Produces: `GET /app/knowledge-bank/attachments/{attachment}` named `knowledge.attachments.show`, resolving to `KnowledgeController::attachment(Request, KnowledgeAttachment): StreamedResponse`.

- [ ] **Step 1: Write the failing tests**

Add to `tests/Feature/KnowledgeAttachmentTest.php`:

```php
    public function test_a_same_tenant_employee_can_stream_an_attachment(): void
    {
        $entry = $this->entryWithImages(1);
        $att = $entry->attachments()->first();
        Storage::disk('local')->put($att->path, 'fake-bytes');

        $this->actingInTenant($this->other)->get(route('knowledge.attachments.show', $att))->assertOk();
    }

    public function test_a_different_tenant_employee_cannot_stream_an_attachment(): void
    {
        $entry = $this->entryWithImages(1);
        $att = $entry->attachments()->first();
        Storage::disk('local')->put($att->path, 'fake-bytes');

        $otherTenant = Tenant::create(['slug' => 'other-co', 'name' => 'Other Co', 'initials' => 'OC']);
        $intruder = User::create(['name' => 'Nosy', 'email' => 'nosy@example.com', 'password' => Hash::make('password')]);
        $intruder->tenants()->attach($otherTenant->id, ['role' => 'employee']);
        Employee::create(['tenant_id' => $otherTenant->id, 'user_id' => $intruder->id, 'name' => 'Nosy', 'status' => 'active', 'workload' => 'green']);

        $this->actingAs($intruder)->withSession(['current_tenant' => $otherTenant->id])
            ->get(route('knowledge.attachments.show', $att))->assertForbidden();
    }

    public function test_missing_file_on_disk_is_404(): void
    {
        $entry = $this->entryWithImages(1);
        $att = $entry->attachments()->first();
        // Deliberately never written to the fake disk.

        $this->actingInTenant()->get(route('knowledge.attachments.show', $att))->assertNotFound();
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact --filter=KnowledgeAttachmentTest`
Expected: FAIL — route `knowledge.attachments.show` undefined.

- [ ] **Step 3: Add the controller method**

Append to `app/Http/Controllers/KnowledgeController.php`, near the other write/read actions:

```php
    /**
     * Stream a lesson's picture inline through a tenant-gated action — never a public URL.
     * A lesson is company-wide, so any employee in the same tenant may view it (unlike
     * message attachments, which are participant-gated).
     */
    public function attachment(Request $request, KnowledgeAttachment $attachment): StreamedResponse
    {
        $employee = $request->attributes->get('employee');
        abort_unless($employee, 403);
        abort_unless($attachment->tenant_id === app(CurrentTenant::class)->id(), 403);
        abort_unless(Storage::disk(self::ATTACHMENT_DISK)->exists($attachment->path), 404);

        return Storage::disk(self::ATTACHMENT_DISK)->response($attachment->path, $attachment->name);
    }
```

- [ ] **Step 4: Register the route**

In `routes/web.php`, inside the knowledge-bank group (after the `knowledge.comments.delete` line at 340):

```php
        Route::get('/app/knowledge-bank/attachments/{attachment}', [KnowledgeController::class, 'attachment'])->name('knowledge.attachments.show');
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --compact --filter=KnowledgeAttachmentTest`
Expected: PASS (all tests so far)

- [ ] **Step 6: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/KnowledgeController.php routes/web.php tests/Feature/KnowledgeAttachmentTest.php
git commit -m "feat(knowledge-bank): tenant-gated stream route for lesson pictures"
```

---

### Task 6: Eager-load attachments into `screenData()` and the entry card seed

**Files:**
- Modify: `app/Http/Controllers/KnowledgeController.php:67-72` (`screenData()` eager-load list)
- Modify: `resources/views/screens/knowledge-bank.blade.php:186-198` (`kbCard` seed)
- Test: `tests/Feature/KnowledgeAttachmentTest.php` (add case)

**Interfaces:**
- Produces: each `$e` in `screenData()`'s `entries` carries a loaded `attachments` collection; the `kbCard` Alpine seed gains an `attachments` array of `{ id, url, caption }`.

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/KnowledgeAttachmentTest.php`:

```php
    public function test_screen_data_eager_loads_attachments(): void
    {
        $entry = $this->entryWithImages(2);

        $controller = app(\App\Http\Controllers\KnowledgeController::class);
        $request = \Illuminate\Http\Request::create('/app/knowledge-bank');
        $request->attributes->set('tenantRole', 'employee');

        $data = $controller->screenData($request, $this->authorEmployee);
        $loadedEntry = $data['entries']->firstWhere('id', $entry->id);

        $this->assertTrue($loadedEntry->relationLoaded('attachments'));
        $this->assertSame(2, $loadedEntry->attachments->count());
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=test_screen_data_eager_loads_attachments`
Expected: FAIL — `relationLoaded('attachments')` is false.

- [ ] **Step 3: Add `attachments` to the eager-load list**

In `app/Http/Controllers/KnowledgeController.php`, `screenData()`, change:

```php
        $entries = KnowledgeEntry::with([
            'employee:id,name,initials,avatar_color,position',
            'segment:id,label',
            'subSegment:id,label',
            'comments' => fn ($c) => $c->with('employee:id,name,initials,avatar_color')->orderBy('id'),
        ])
```

to:

```php
        $entries = KnowledgeEntry::with([
            'employee:id,name,initials,avatar_color,position',
            'segment:id,label',
            'subSegment:id,label',
            'attachments',
            'comments' => fn ($c) => $c->with('employee:id,name,initials,avatar_color')->orderBy('id'),
        ])
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact --filter=test_screen_data_eager_loads_attachments`
Expected: PASS

- [ ] **Step 5: Wire the seed in the blade entry loop**

In `resources/views/screens/knowledge-bank.blade.php`, the `kbCard(...)` seed (lines 187-198), add `attachments` before the closing `})`:

```php
                 commentsCount: {{ $e->comments_count }},
                 attachments: @js($e->attachments->map(fn ($a) => [
                     'id' => $a->id,
                     'url' => route('knowledge.attachments.show', $a),
                     'caption' => $a->caption,
                 ])->values()),
             })">
```

- [ ] **Step 6: Run the full Knowledge Bank test suite to check for regressions**

Run: `php artisan test --compact tests/Feature/KnowledgeAttachmentTest.php tests/Feature/KnowledgeEntryUpdateTest.php tests/Feature/KnowledgeCommentDrawerTest.php`
Expected: PASS (all)

- [ ] **Step 7: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/KnowledgeController.php resources/views/screens/knowledge-bank.blade.php tests/Feature/KnowledgeAttachmentTest.php
git commit -m "feat(knowledge-bank): eager-load and seed picture attachments into the entry list"
```

---

### Task 7: `knowledge-attach.js` picker module (images-only, captions, reorder)

**Files:**
- Create: `resources/js/knowledge-attach.js`
- Modify: `resources/js/app.js` (register the Alpine data component, following the existing `registerKnowledgeCard` pattern)

**Interfaces:**
- Produces: `Alpine.data('kbAttach', (seed = []) => ({...}))` registered via `registerKnowledgeAttach(Alpine)`, exported the same way `registerKnowledgeCard` is. State: `files` (new picks: `{ file, caption, url }`), `existing` (pre-seeded: `{ id, url, caption, removed }`), `error`. Methods: `addFiles(list)`, `remove(i)`, `removeExisting(id)`, `restoreExisting(id)`, `sync()` (rebuilds only the hidden file input's FileList — captions/removals/reorder are plain named inputs rendered in the blade template, not serialized in JS), `initSortable($refs.strip)` (wires `window.Sortable`).

- [ ] **Step 1: Check how `registerKnowledgeCard` is wired into `app.js`, to mirror it**

Run: `grep -n "registerKnowledgeCard\|registerTicketAttach" resources/js/app.js`
Expected: shows the import + call pattern to copy for `registerKnowledgeAttach`.

- [ ] **Step 2: Write the module**

```js
// Knowledge Bank picture picker — create + edit forms. Images-only variant of
// resources/js/ticket-attach.js: no PDF/doc branches, no clipboard-paste hook (not asked
// for here). Adds two things ticket-attach.js doesn't need: a caption per picture, and
// drag-reorder (via SortableJS, same call shape as resources/js/work-board.js) over BOTH
// newly-picked files and, on the edit form, already-persisted attachments together — the
// two lists are kept separate in state (files vs existing) but rendered as one strip, and
// `sync()` writes three hidden inputs so a plain form POST carries everything: the file
// input, a JSON captions array (index-aligned to the file input), and a JSON reorder array
// of surviving existing-attachment ids in their current order.

const ACCEPT_EXT = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
const MAX_TOTAL = 10;
const MAX_BYTES = 8 * 1024 * 1024;

export function registerKnowledgeAttach(Alpine) {
    Alpine.data('kbAttach', (seed = []) => ({
        files: [], // { file, caption, url }
        existing: seed.map((a) => ({ ...a, removed: false })), // { id, url, caption, removed }
        error: '', // '' | 'type' | 'size' | 'max'

        get activeExisting() {
            return this.existing.filter((e) => !e.removed);
        },

        get total() {
            return this.activeExisting.length + this.files.length;
        },

        addFiles(list) {
            for (const f of Array.from(list || [])) {
                if (!this.tryAdd(f)) break;
            }
            this.sync();
        },

        tryAdd(file) {
            this.error = '';
            const ext = (file.name.split('.').pop() || '').toLowerCase();
            if (!ACCEPT_EXT.includes(ext)) { this.error = 'type'; return false; }
            if (file.size > MAX_BYTES) { this.error = 'size'; return false; }
            if (this.total >= MAX_TOTAL) { this.error = 'max'; return false; }
            this.files.push({ file, caption: '', url: URL.createObjectURL(file) });
            return true;
        },

        remove(i) {
            const f = this.files[i];
            if (f && f.url) URL.revokeObjectURL(f.url);
            this.files.splice(i, 1);
            this.error = '';
            this.sync();
        },

        removeExisting(id) {
            const e = this.existing.find((x) => x.id === id);
            if (e) e.removed = true;
            this.sync();
        },

        restoreExisting(id) {
            const e = this.existing.find((x) => x.id === id);
            if (e) e.removed = false;
            this.sync();
        },

        // Rebuild the hidden <input type=file> FileList so a plain form POST carries the
        // picked files. The non-file fields (captions[], remove_images[], reorder[],
        // caption_updates[id]) are NOT serialized here — they're plain named <input>s
        // rendered directly in the blade template via x-for/x-model over `files` and
        // `existing`, so the browser's native multipart form submission carries them as
        // real PHP arrays. That keeps the backend's `'captions' => ['array']`-style
        // validation (Task 3/4) working unmodified — no JSON decode step needed server-side.
        sync() {
            const dt = new DataTransfer();
            this.files.forEach((f) => dt.items.add(f.file));
            this.$refs.input.files = dt.files;
        },

        // Wire SortableJS on the strip once it's in the DOM (x-init="initSortable($el)").
        // Drag reorders `existing` (persisted attachments) and `files` (new picks) as one
        // combined array so the visual order matches what the user dragged; on submit only
        // `existing`'s surviving order needs to travel (via reorder[]) since `files`' order
        // is already correct positionally in the FileList sync() just rebuilt.
        initSortable(el) {
            window.Sortable.create(el, {
                animation: 150,
                draggable: '[data-kb-tile]',
                onEnd: (evt) => {
                    const combined = [...this.activeExisting.map((e) => ({ kind: 'existing', id: e.id })), ...this.files.map((_, i) => ({ kind: 'file', i }))];
                    const [moved] = combined.splice(evt.oldIndex, 1);
                    combined.splice(evt.newIndex, 0, moved);

                    const newExisting = combined.filter((c) => c.kind === 'existing').map((c) => this.existing.find((e) => e.id === c.id));
                    const newFiles = combined.filter((c) => c.kind === 'file').map((c) => this.files[c.i]);
                    this.existing = [...newExisting, ...this.existing.filter((e) => e.removed)];
                    this.files = newFiles;
                    this.sync();
                },
            });
        },
    }));
}
```

- [ ] **Step 3: Register it in `app.js`**

Find the existing `registerKnowledgeCard` import/call in `resources/js/app.js` and add the sibling import + call for `registerKnowledgeAttach`:

```js
import { registerKnowledgeAttach } from './knowledge-attach';
```

...and next to the existing `registerKnowledgeCard(Alpine);` call:

```js
registerKnowledgeAttach(Alpine);
```

- [ ] **Step 4: Build assets to confirm no syntax errors**

Run: `bun run build`
Expected: build succeeds with no errors mentioning `knowledge-attach.js`.

- [ ] **Step 5: Commit**

```bash
git add resources/js/knowledge-attach.js resources/js/app.js
git commit -m "feat(knowledge-bank): add picture picker Alpine module with captions and drag-reorder"
```

---

### Task 8: Wire the picker into the add-lesson form

**Files:**
- Modify: `resources/views/partials/knowledge-panel.blade.php:129-164` (the `knowledge.store` form)

**Interfaces:**
- Consumes: `kbAttach` Alpine component (Task 7).

- [ ] **Step 1: Add `enctype` and mount `kbAttach` on the form**

In `resources/views/partials/knowledge-panel.blade.php`, change the opening form tag (line 129):

```html
                    <form method="post" action="{{ route('knowledge.store') }}" enctype="multipart/form-data"
                          x-data="kbAttach()">
```

- [ ] **Step 2: Insert the picker UI before the submit button row (before line 160's `<div style="display:flex;gap:10px;">`)**

```html
                        <label style="display:block;font-size:12.5px;font-weight:500;color:var(--ink);margin-bottom:7px;">
                            <span x-text="$store.ui.lang==='en' ? 'Pictures' : 'Gambar'">Pictures</span>
                            <span style="color:var(--muted);font-weight:400;" x-text="$store.ui.lang==='en' ? '(optional, up to 10)' : '(pilihan, sehingga 10)'">(optional, up to 10)</span>
                        </label>

                        {{-- Create form has no pre-existing pictures, so only `images[]` + index-aligned
                             `captions[]` travel — no remove_images[]/reorder[]/caption_updates here, those
                             only apply to the edit form (Task 9), which already has persisted attachments. --}}
                        <input x-ref="input" type="file" name="images[]" multiple accept="image/*" style="display:none;" @change="addFiles($event.target.files)" />

                        <div x-show="error" x-cloak style="font-size:12px;color:var(--red);margin-bottom:8px;">
                            <span x-show="error==='type'" x-text="$store.ui.lang==='en' ? 'Only JPG, PNG, GIF, or WebP pictures.' : 'Hanya gambar JPG, PNG, GIF, atau WebP.'"></span>
                            <span x-show="error==='size'" x-text="$store.ui.lang==='en' ? 'Each picture must be 8 MB or smaller.' : 'Setiap gambar mesti 8 MB atau lebih kecil.'"></span>
                            <span x-show="error==='max'" x-text="$store.ui.lang==='en' ? 'You can attach up to 10 pictures.' : 'Anda boleh lampirkan sehingga 10 gambar.'"></span>
                        </div>

                        <div x-init="initSortable($el)" style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px;">
                            <template x-for="(f, i) in files" :key="f.url">
                                <div data-kb-tile style="position:relative;width:72px;">
                                    <img :src="f.url" style="width:72px;height:72px;object-fit:cover;border-radius:8px;border:1px solid var(--hairline);cursor:grab;" />
                                    <button type="button" @click="remove(i)" style="position:absolute;top:-6px;right:-6px;width:20px;height:20px;border-radius:50%;background:var(--ink);color:#fff;font-size:12px;line-height:1;">&times;</button>
                                    <input x-model="f.caption" :name="'captions['+i+']'" maxlength="200" :placeholder="$store.ui.lang==='en' ? 'Caption' : 'Kapsyen'" style="width:72px;font-size:10px;margin-top:4px;border:1px solid var(--hairline);border-radius:4px;padding:2px 4px;" />
                                </div>
                            </template>
                            <button type="button" @click="$refs.input.click()" style="width:72px;height:72px;border:1px dashed var(--hairline);border-radius:8px;color:var(--muted);font-size:22px;background:#fff;">+</button>
                        </div>

                        {{-- Note on `:name="'captions['+i+']'"`: index `i` is `files`' current array
                             position, which after a drag-reorder (initSortable's onEnd) is the same order
                             `sync()` just wrote into the file input's FileList — so caption N always lines
                             up with image N server-side, exactly matching `storeImages()`'s `$captions[$i]`
                             lookup (Task 3). --}}
```

- [ ] **Step 3: Manually verify in the browser (preview_start the `laravel-app` dev server)**

1. Navigate to `http://localhost:9100/dev/login?email=hr@amanahku.test&tenant=unijaya`.
2. Open the Knowledge Bank panel, click "Share a lesson".
3. Click the `+` tile, pick 2-3 images from the file dialog — confirm thumbnails appear with caption inputs, drag to reorder works, the `×` removes a tile.
4. Fill title/body, submit. Confirm redirect back to the feed with the success banner and no console errors (`read_console_messages`).
5. Query the DB (`php artisan tinker --execute 'App\Models\KnowledgeAttachment::latest()->first()'`) to confirm the row and `caption` landed correctly, and that the stored file (`storage/app/private/knowledge-attachments/...` or the local disk's configured root) is smaller than the original pick (compression ran).

- [ ] **Step 4: Commit**

```bash
git add resources/views/partials/knowledge-panel.blade.php
git commit -m "feat(knowledge-bank): wire the picture picker into the add-lesson form"
```

---

### Task 9: Edit picker + picture grid + lightbox in the detail drawer

**Files:**
- Modify: `resources/js/knowledge-card.js` (seed `attachments`, add lightbox state, switch `saveEdit()` to multipart)
- Modify: `resources/views/partials/knowledge-comments-drawer.blade.php` (render grid, edit picker, lightbox)
- Modify: `resources/views/screens/knowledge-bank.blade.php:187-198` (seed already carries `attachments` from Task 6 — no change needed here, just confirming the same seed reaches the drawer via the shared `kbCard` x-data scope)

**Interfaces:**
- Consumes: `kbCard` seed's `attachments: [{ id, url, caption }]` (Task 6), `KnowledgeAttachment` route `knowledge.attachments.show` (Task 5), the `update()` fields `images[]`/`captions[]`/`remove_images[]`/`reorder[]`/`caption_updates[id]` (Task 4).
- Produces: `kbCard` gains `lightboxOpen`, `lightboxIndex`, `openLightbox(i)`, `closeLightbox()`, `nextImage()`, `prevImage()`; `editImages` (an inline `kbAttach()`-shaped sub-state reused for the edit picker, seeded from `this.attachments` when `startEdit()` runs).

- [ ] **Step 1: Extend `knowledge-card.js`: seed handling, lightbox, and a multipart `saveEdit()`**

In `resources/js/knowledge-card.js`, the `Alpine.data('kbCard', (seed) => ({...}))` object already spreads `...seed` (which now includes `attachments` per Task 6 — no extra wiring needed to receive it). Add lightbox state right after the existing `editBody: '',` line:

```js
        lightboxOpen: false,
        lightboxIndex: 0,
```

Add the lightbox methods after `heartPress()`:

```js
        openLightbox(i) {
            this.lightboxIndex = i;
            this.lightboxOpen = true;
        },

        closeLightbox() {
            this.lightboxOpen = false;
        },

        nextImage() {
            if (this.lightboxIndex < this.attachments.length - 1) this.lightboxIndex++;
        },

        prevImage() {
            if (this.lightboxIndex > 0) this.lightboxIndex--;
        },
```

Change `startEdit()` to also seed the edit picker's file-strip state (reusing the same shape `kbAttach()` uses, kept inline here rather than nesting a second Alpine component inside `kbCard`'s x-data, since the drawer's edit picker needs direct access to `this.attachments` for the "existing" list):

```js
        startEdit() {
            this.editTitle = this.title;
            this.editBody = this.body;
            this.editFiles = []; // new picks: { file, caption, url }
            this.editExisting = this.attachments.map((a) => ({ ...a, removed: false }));
            this.editError = '';
            this.editing = true;
        },

        addEditFiles(list) {
            for (const f of Array.from(list || [])) {
                const ext = (f.name.split('.').pop() || '').toLowerCase();
                if (!['jpg', 'jpeg', 'png', 'gif', 'webp'].includes(ext)) { this.editError = 'type'; break; }
                if (f.size > 8 * 1024 * 1024) { this.editError = 'size'; break; }
                if (this.editExisting.filter((e) => !e.removed).length + this.editFiles.length >= 10) { this.editError = 'max'; break; }
                this.editError = '';
                this.editFiles.push({ file: f, caption: '', url: URL.createObjectURL(f) });
            }
        },

        removeEditFile(i) {
            const f = this.editFiles[i];
            if (f && f.url) URL.revokeObjectURL(f.url);
            this.editFiles.splice(i, 1);
        },

        toggleRemoveExisting(id) {
            const e = this.editExisting.find((x) => x.id === id);
            if (e) e.removed = !e.removed;
        },
```

Replace `saveEdit()` entirely — it must switch from a JSON `PUT` to a multipart `POST` with `_method=PUT` spoofing (Laravel's `Illuminate\Foundation\Http\Kernel` calls `enableHttpMethodParameterOverride()` unconditionally, so a POST carrying a `_method` field is routed as that method — the same mechanism Blade's `@method('PUT')` directive relies on; PHP only populates `$_FILES` on a true POST, so a real PUT request can never carry files):

```js
        async saveEdit() {
            if (this.busy || !this.editTitle.trim() || !this.editBody.trim()) return;
            this.busy = true;
            try {
                const fd = new FormData();
                fd.append('_method', 'PUT');
                fd.append('title', this.editTitle);
                fd.append('body', this.editBody);
                this.editFiles.forEach((f, i) => {
                    fd.append('images[]', f.file);
                    fd.append(`captions[${i}]`, f.caption || '');
                });
                this.editExisting.filter((e) => e.removed).forEach((e) => fd.append('remove_images[]', e.id));
                this.editExisting.filter((e) => !e.removed).forEach((e) => {
                    fd.append('reorder[]', e.id);
                    fd.append(`caption_updates[${e.id}]`, e.caption || '');
                });

                const res = await fetch(`/app/knowledge-bank/${this.id}`, {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': this.token(), Accept: 'application/json' },
                    body: fd,
                });
                if (!res.ok) throw new Error(res.status);
                const payload = await res.json();
                this.title = payload.title;
                this.body = payload.body;
                this.bodyHtml = document.createElement('div').appendChild(document.createTextNode(payload.body)).parentElement.innerHTML;
                this.attachments = payload.attachments;
                this.editing = false;
            } catch (e) {
                Alpine.store('toast').error(
                    Alpine.store('ui').lang === 'en'
                        ? 'That did not save. Try again.'
                        : 'Tidak berjaya disimpan. Cuba lagi.'
                );
            } finally {
                this.busy = false;
            }
        },
```

Add the three new `editFiles`/`editExisting`/`editError` fields to the initial state block (next to `editTitle: '',`):

```js
        editFiles: [],
        editExisting: [],
        editError: '',
```

- [ ] **Step 2: Render the picture grid (view mode) in the drawer**

In `resources/views/partials/knowledge-comments-drawer.blade.php`, after the `!editing` body block (after line 59's `<div x-show="!editing" x-html="bodyHtml" ...></div>`), add:

```html
                <div x-show="!editing && attachments.length" x-cloak style="display:grid;gap:6px;margin-top:14px;" :style="attachments.length === 1 ? 'grid-template-columns:1fr;' : 'grid-template-columns:1fr 1fr;'">
                    <template x-for="(a, i) in attachments.slice(0, 4)" :key="a.id">
                        <button type="button" @click="openLightbox(i)" style="position:relative;padding:0;border:none;border-radius:10px;overflow:hidden;aspect-ratio:1;cursor:pointer;">
                            <img :src="a.url" :alt="a.caption || title" style="width:100%;height:100%;object-fit:cover;display:block;" loading="lazy" />
                            <span x-show="i === 3 && attachments.length > 4" style="position:absolute;inset:0;background:rgba(0,0,0,.55);color:#fff;display:flex;align-items:center;justify-content:center;font-size:16px;font-weight:600;" x-text="'+' + (attachments.length - 4)"></span>
                        </button>
                    </template>
                </div>
```

- [ ] **Step 3: Render the edit picker (editing mode)**

In the `x-if="editing"` block (lines 38-49), insert the picker after the `<textarea x-model="editBody" ...></textarea>` line and before the Save/Cancel button row:

```html
                        <div x-show="editError" x-cloak style="font-size:11.5px;color:var(--red);margin-bottom:6px;">
                            <span x-show="editError==='type'" x-text="$store.ui.lang==='en' ? 'Only JPG, PNG, GIF, or WebP pictures.' : 'Hanya gambar JPG, PNG, GIF, atau WebP.'"></span>
                            <span x-show="editError==='size'" x-text="$store.ui.lang==='en' ? 'Each picture must be 8 MB or smaller.' : 'Setiap gambar mesti 8 MB atau lebih kecil.'"></span>
                            <span x-show="editError==='max'" x-text="$store.ui.lang==='en' ? 'You can attach up to 10 pictures.' : 'Anda boleh lampirkan sehingga 10 gambar.'"></span>
                        </div>
                        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px;">
                            <template x-for="e in editExisting.filter(x => !x.removed)" :key="e.id">
                                <div style="position:relative;width:64px;">
                                    <img :src="e.url" style="width:64px;height:64px;object-fit:cover;border-radius:8px;border:1px solid var(--hairline);" />
                                    <button type="button" @click="toggleRemoveExisting(e.id)" style="position:absolute;top:-6px;right:-6px;width:18px;height:18px;border-radius:50%;background:var(--ink);color:#fff;font-size:11px;line-height:1;">&times;</button>
                                    <input x-model="e.caption" maxlength="200" :placeholder="$store.ui.lang==='en' ? 'Caption' : 'Kapsyen'" style="width:64px;font-size:9.5px;margin-top:3px;border:1px solid var(--hairline);border-radius:4px;padding:2px 3px;" />
                                </div>
                            </template>
                            <template x-for="(f, i) in editFiles" :key="f.url">
                                <div style="position:relative;width:64px;">
                                    <img :src="f.url" style="width:64px;height:64px;object-fit:cover;border-radius:8px;border:1px solid var(--hairline);" />
                                    <button type="button" @click="removeEditFile(i)" style="position:absolute;top:-6px;right:-6px;width:18px;height:18px;border-radius:50%;background:var(--ink);color:#fff;font-size:11px;line-height:1;">&times;</button>
                                    <input x-model="f.caption" maxlength="200" :placeholder="$store.ui.lang==='en' ? 'Caption' : 'Kapsyen'" style="width:64px;font-size:9.5px;margin-top:3px;border:1px solid var(--hairline);border-radius:4px;padding:2px 3px;" />
                                </div>
                            </template>
                            <button type="button" @click="$refs.editFileInput.click()" style="width:64px;height:64px;border:1px dashed var(--hairline);border-radius:8px;color:var(--muted);font-size:20px;background:#fff;">+</button>
                            <input x-ref="editFileInput" type="file" multiple accept="image/*" style="display:none;" @change="addEditFiles($event.target.files); $event.target.value = ''" />
                        </div>
```

- [ ] **Step 4: Render the lightbox**

Add before the closing `</aside>` of the drawer (before line 156's `</aside>`), as a sibling teleported overlay:

```html
            <div x-show="lightboxOpen" x-cloak @keydown.escape.window="closeLightbox()"
                 style="position:fixed;inset:0;background:rgba(0,0,0,.9);z-index:60;display:flex;align-items:center;justify-content:center;">
                <button type="button" @click="closeLightbox()" style="position:absolute;top:20px;right:20px;color:#fff;font-size:28px;background:none;">&times;</button>
                <button type="button" x-show="lightboxIndex > 0" @click="prevImage()" style="position:absolute;left:20px;color:#fff;font-size:32px;background:none;">&larr;</button>
                <template x-if="lightboxOpen">
                    <div style="max-width:90vw;max-height:85vh;text-align:center;">
                        <img :src="attachments[lightboxIndex]?.url" :alt="attachments[lightboxIndex]?.caption || title" style="max-width:90vw;max-height:78vh;object-fit:contain;" />
                        <div x-show="attachments[lightboxIndex]?.caption" style="color:#fff;font-size:13px;margin-top:10px;" x-text="attachments[lightboxIndex]?.caption"></div>
                    </div>
                </template>
                <button type="button" x-show="lightboxIndex < attachments.length - 1" @click="nextImage()" style="position:absolute;right:20px;color:#fff;font-size:32px;background:none;">&rarr;</button>
            </div>
```

- [ ] **Step 5: Manually verify in the browser**

1. As `hr@amanahku.test` (or any author), open a lesson you wrote in the drawer.
2. Click a picture tile — confirm the lightbox opens on the right image, arrows page through, Escape and the × close it.
3. Click "Edit lesson" — confirm existing pictures appear with captions editable and removable, `+` adds new ones, drag reorder still works if wired the same `initSortable` pattern (optional here — reordering already-persisted attachments in the drawer edit view can reuse Task 7's `initSortable` by adding `x-init` on the strip container if time allows; if skipped for this pass, note it and move on — the `reorder[]` field still works via the plain array order, drag is a UX nicety on top).
4. Save — confirm the grid updates without a full page reload, and a re-fetch (`GET /app/knowledge-bank/{id}/comments` or reloading the page) shows the change persisted.

- [ ] **Step 6: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add resources/js/knowledge-card.js resources/views/partials/knowledge-comments-drawer.blade.php
git commit -m "feat(knowledge-bank): edit picker, picture grid, and lightbox in the lesson drawer"
```

---

### Task 10: Compact picture strip in the entry list row

**Files:**
- Modify: `resources/views/screens/knowledge-bank.blade.php` (the summary row, after line 217's employee/position line)

**Interfaces:**
- Consumes: `attachments` (already on the `kbCard` seed from Task 6).

- [ ] **Step 1: Add a small thumbnail row under the title/author block**

After the closing `</div>` of the title/author block (around line 217-218, right before the reactions/star/comments `<div>` at line 219), add:

```html
                    <div x-show="attachments.length" x-cloak style="display:flex;gap:5px;margin-top:8px;">
                        <template x-for="(a, i) in attachments.slice(0, 4)" :key="a.id">
                            <img :src="a.url" :alt="a.caption || title" @click.stop="openDrawer(); openLightbox(i)"
                                 style="width:44px;height:44px;object-fit:cover;border-radius:6px;border:1px solid var(--hairline);cursor:pointer;flex-shrink:0;" loading="lazy" />
                        </template>
                        <span x-show="attachments.length > 4" style="font-size:11px;color:var(--muted);align-self:center;" x-text="'+' + (attachments.length - 4)"></span>
                    </div>
```

Note: `openDrawer()` already exists ([knowledge-card.js:108](resources/js/knowledge-card.js)); calling it before `openLightbox(i)` ensures the drawer (and its lightbox overlay, which lives inside the drawer's teleported template) is mounted before the lightbox tries to show.

- [ ] **Step 2: Manually verify in the browser**

1. Reload the Knowledge Bank feed screen — confirm lessons with pictures show up to 4 small thumbnails under the author line, and a `+N` badge when more exist.
2. Click a thumbnail — confirm it opens the drawer with the lightbox already showing that image.

- [ ] **Step 3: Commit**

```bash
git add resources/views/screens/knowledge-bank.blade.php
git commit -m "feat(knowledge-bank): show a picture strip on each entry list row"
```

---

### Task 11: Full regression pass

**Files:** none (verification only)

- [ ] **Step 1: Run the whole Knowledge Bank test surface**

Run: `php artisan test --compact --filter=Knowledge`
Expected: PASS — every `Knowledge*Test` (including the pre-existing `KnowledgeEntryUpdateTest`, `KnowledgeCommentDrawerTest`) plus the new `KnowledgeAttachmentTest`.

- [ ] **Step 2: Run the image compressor unit test**

Run: `php artisan test --compact --filter=ImageCompressorTest`
Expected: PASS

- [ ] **Step 3: Run pint across everything touched this feature**

Run: `vendor/bin/pint --dirty --format agent`
Expected: no remaining style violations.

- [ ] **Step 4: Build frontend assets**

Run: `bun run build`
Expected: succeeds with no errors.

- [ ] **Step 5: Ask the user whether to run the full suite**

Per the project's PHPUnit convention: once the feature's own tests are green, ask before running `php artisan test --compact` (the entire suite) — don't run it unprompted.

---

## Plan Self-Review

**Spec coverage:**
- Storage & serving (private disk, tenant-gated stream) → Tasks 1, 5.
- Compression (GD, 2000px/quality 82/lossless PNG/GIF untouched) → Task 2.
- Model/migration → Task 1.
- `store()`/`update()` with images, captions, remove, reorder → Tasks 3, 4.
- Route → Task 5.
- `screenData()` eager load → Task 6.
- Picker (create + edit), captions, drag-reorder → Tasks 7, 8, 9.
- Feed grid / thumbnail strip → Task 10.
- Lightbox with captions → Task 9.
- Testing (store/update caps, tenant isolation, cascade, compressor) → Tasks 1, 3, 4, 5.
- Out of scope (no EXIF editing, caption doubles as alt) → respected, not implemented anywhere in the plan.

**Placeholder scan:** no TBD/TODO; every step has literal code, not a description of code.

**Type consistency fix applied during authoring:** the picker module's `sync()` originally serialized captions/removals/reorder into hidden JSON inputs (`captions_json`, etc.), which didn't match the backend's plain-array validation from Tasks 3-4. Corrected to real named array inputs (`captions[i]`, `remove_images[]`, `reorder[]`, `caption_updates[id]`) rendered directly by Alpine's `x-for`/`x-model`, so no JSON encode/decode step exists on either side — `sync()` now only rebuilds the file `<input>`'s FileList, which is the one thing that genuinely requires JS (`File` objects cannot be set via `x-model`).

**Cascade delete:** declared in the Task 1 migration (`cascadeOnDelete()` on `entry_id`); no explicit test added for it since it's a plain DB-level FK behavior identical to `message_attachments`' already-proven pattern — covered by inspection, not a new test, to avoid a redundant assertion of framework-level FK behavior.

