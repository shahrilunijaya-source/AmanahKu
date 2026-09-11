<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Two notices hang off the header in their own band (.uj-head-pins) instead of
 * scrolling away with the head stack: the one-time password reveal, which is shown
 * once and costs a second reset if it is lost, and the overdue-timesheet alert.
 * Everything else in the stack scrolls, on purpose — a dismissible nudge that follows
 * you down every page is a nag.
 *
 * The band must stay a SIBLING of <main>, between it and the header. Inside <main> it
 * would be as narrow as the scroll area (stopping short at the scrollbar) instead of
 * spanning exactly what the header spans, and it would need sticky to stay put.
 */
class HeadPinsTest extends TestCase
{
    use RefreshDatabase;

    private function signIn(): User
    {
        $tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        $user = User::create(['name' => 'Ana', 'email' => 'ana@acme.test', 'password' => Hash::make('password')]);
        $user->tenants()->attach($tenant->id, ['role' => 'hr']);

        $this->actingAs($user)->withSession(['current_tenant' => $tenant->id]);

        return $user;
    }

    public function test_nothing_is_pinned_when_neither_notice_is_showing(): void
    {
        $this->signIn();

        $html = $this->get('/app/dash')->assertOk()->getContent();

        $this->assertStringNotContainsString('uj-head-pins', $html,
            'An empty pinned strip renders and eats space under the header.');
    }
}
