<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CompanyInvite;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The invite row itself: one token, one company, seven days.
 */
class CompanyInviteModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_factory_makes_a_pending_usable_invite_with_a_40_char_token(): void
    {
        $invite = CompanyInvite::factory()->create();

        $this->assertSame(40, strlen($invite->token));
        $this->assertTrue($invite->isUsable());
        $this->assertSame('pending', $invite->status());
        $this->assertSame(3, $invite->category->level);
        $this->assertNotNull($invite->creator);
    }

    public function test_expired_invite_is_not_usable(): void
    {
        $invite = CompanyInvite::factory()->expired()->create();

        $this->assertFalse($invite->isUsable());
        $this->assertSame('expired', $invite->status());
    }

    public function test_used_invite_is_not_usable_and_points_at_the_company(): void
    {
        $tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        $invite = CompanyInvite::factory()->usedBy($tenant)->create();

        $this->assertFalse($invite->isUsable());
        $this->assertSame('used', $invite->status());
        $this->assertTrue($invite->usedByTenant->is($tenant));
    }

    public function test_for_token_scope_finds_exactly_that_invite(): void
    {
        $a = CompanyInvite::factory()->create();
        CompanyInvite::factory()->create();

        $this->assertTrue(CompanyInvite::forToken($a->token)->first()->is($a));
        $this->assertNull(CompanyInvite::forToken('nope')->first());
    }
}
