<?php

namespace Tests\Feature;

use App\Models\Employee;
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
}
