<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\KnowledgeEntry;
use App\Models\KnowledgeSegment;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
