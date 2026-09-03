<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Profile cover photo (employees.cover_path): who may set or remove it, the tenant
 * guard, and what the profile screen renders. Harness copied from ProfileVisibilityTest.
 * Spec: docs/superpowers/specs/2026-09-03-custom-backgrounds-design.html
 */
class ProfileCoverTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
    }

    /** An employee with a login in the given role. Does not sign anyone in. */
    private function person(string $role, ?Tenant $tenant = null): Employee
    {
        $this->seq++;
        $tenant ??= $this->tenant;
        $user = User::create(['name' => $role.$this->seq, 'email' => "{$role}{$this->seq}@example.com", 'password' => Hash::make('password')]);
        $user->tenants()->attach($tenant->id, ['role' => $role]);

        return Employee::create([
            'tenant_id' => $tenant->id, 'user_id' => $user->id,
            'name' => ucfirst($role).' '.$this->seq, 'status' => 'active', 'workload' => 'green',
        ]);
    }

    private function signIn(Employee $e): self
    {
        $this->actingAs($e->user)->withSession(['current_tenant' => $e->tenant_id]);

        return $this;
    }

    public function test_owner_uploads_a_cover_and_it_replaces_the_previous_file(): void
    {
        $me = $this->person('employee');
        Storage::disk('public')->put("covers/{$me->id}/old.jpg", 'x');
        $me->update(['cover_path' => "covers/{$me->id}/old.jpg"]);

        $this->signIn($me)->post(route('employees.cover.update', $me), ['photo' => UploadedFile::fake()->image('c.jpg', 1600, 600)])
            ->assertRedirect();

        $path = $me->fresh()->cover_path;
        $this->assertStringStartsWith("covers/{$me->id}/", $path);
        Storage::disk('public')->assertExists($path);
        Storage::disk('public')->assertMissing("covers/{$me->id}/old.jpg");
    }

    public function test_a_colleague_cannot_upload_someone_elses_cover(): void
    {
        $me = $this->person('employee');
        $them = $this->person('employee');

        $this->signIn($me)->post(route('employees.cover.update', $them), ['photo' => UploadedFile::fake()->image('c.jpg')])
            ->assertForbidden();
        $this->assertNull($them->fresh()->cover_path);
    }

    public function test_hr_cannot_upload_for_someone_else_but_can_remove(): void
    {
        $hr = $this->person('hr');
        $them = $this->person('employee');
        Storage::disk('public')->put("covers/{$them->id}/a.jpg", 'x');
        $them->update(['cover_path' => "covers/{$them->id}/a.jpg"]);

        $this->signIn($hr)->post(route('employees.cover.update', $them), ['photo' => UploadedFile::fake()->image('c.jpg')])->assertForbidden();

        $this->signIn($hr)->post(route('employees.cover.destroy', $them))->assertRedirect();
        $this->assertNull($them->fresh()->cover_path);
        Storage::disk('public')->assertMissing("covers/{$them->id}/a.jpg");
    }

    public function test_owner_removes_own_cover(): void
    {
        $me = $this->person('employee');
        Storage::disk('public')->put("covers/{$me->id}/a.jpg", 'x');
        $me->update(['cover_path' => "covers/{$me->id}/a.jpg"]);

        $this->signIn($me)->post(route('employees.cover.destroy', $me))->assertRedirect();

        $this->assertNull($me->fresh()->cover_path);
        Storage::disk('public')->assertMissing("covers/{$me->id}/a.jpg");
    }

    public function test_a_colleague_cannot_remove_someone_elses_cover(): void
    {
        $me = $this->person('employee');
        $them = $this->person('employee');
        $them->update(['cover_path' => "covers/{$them->id}/a.jpg"]);

        $this->signIn($me)->post(route('employees.cover.destroy', $them))->assertForbidden();
        $this->assertSame("covers/{$them->id}/a.jpg", $them->fresh()->cover_path);
    }

    public function test_another_tenants_employee_is_a_404_even_for_hr(): void
    {
        $hr = $this->person('hr');
        $otherTenant = Tenant::create(['slug' => 'beta', 'name' => 'Beta', 'initials' => 'BT']);
        $stranger = $this->person('employee', $otherTenant);

        $this->signIn($hr)->post(route('employees.cover.update', $stranger), ['photo' => UploadedFile::fake()->image('c.jpg')])->assertNotFound();
        $this->signIn($hr)->post(route('employees.cover.destroy', $stranger))->assertNotFound();
    }

    public function test_bad_uploads_are_rejected(): void
    {
        $me = $this->person('employee');

        $this->signIn($me)->post(route('employees.cover.update', $me), ['photo' => UploadedFile::fake()->create('big.jpg', 6000, 'image/jpeg')])->assertSessionHasErrors('photo');
        $this->signIn($me)->post(route('employees.cover.update', $me), ['photo' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')])->assertSessionHasErrors('photo');
        $this->signIn($me)->post(route('employees.cover.update', $me), [])->assertSessionHasErrors('photo');
    }

    public function test_cover_renders_for_the_owner_a_colleague_and_on_the_slim_card(): void
    {
        $me = $this->person('employee');
        $me->update(['cover_path' => "covers/{$me->id}/a.jpg"]);
        $url = Storage::disk('public')->url("covers/{$me->id}/a.jpg");

        $own = $this->signIn($me)->get(route('app.screen', ['screen' => 'profile', 'emp' => $me->id]))->assertOk()->getContent();
        $this->assertStringContainsString('class="uj-cover"', $own);
        $this->assertStringContainsString($url, $own);
        $this->assertStringContainsString('Change cover', $own);
        $this->assertStringContainsString(route('employees.cover.destroy', $me), $own);

        $hr = $this->person('hr');
        $full = $this->signIn($hr)->get(route('app.screen', ['screen' => 'profile', 'emp' => $me->id]))->getContent();
        $this->assertStringContainsString($url, $full);
        $this->assertStringNotContainsString('Change cover', $full);
        $this->assertStringContainsString('Remove cover', $full);

        $peer = $this->person('employee');
        $slim = $this->signIn($peer)->get(route('app.screen', ['screen' => 'profile', 'emp' => $me->id]))->getContent();
        $this->assertStringContainsString($url, $slim);
        $this->assertStringNotContainsString('Change cover', $slim);
        $this->assertStringNotContainsString('Remove cover', $slim);
    }

    public function test_no_cover_shows_the_invitation_to_the_owner_only(): void
    {
        $me = $this->person('employee');

        $own = $this->signIn($me)->get(route('app.screen', ['screen' => 'profile', 'emp' => $me->id]))->getContent();
        $this->assertStringContainsString('Add a cover photo', $own);
        $this->assertStringNotContainsString('class="uj-cover"', $own);

        $hr = $this->person('hr');
        $other = $this->signIn($hr)->get(route('app.screen', ['screen' => 'profile', 'emp' => $me->id]))->getContent();
        $this->assertStringNotContainsString('Add a cover photo', $other);
        $this->assertStringNotContainsString('Remove cover', $other);
    }
}
