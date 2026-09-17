<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * partials.people-pulse (the "On leave / Birthdays" dashboard card) is not included
 * by any Blade view today: the birthday + wish feature moved to the CR-32 dashboard
 * bands mechanism. Rendered directly here so the messages-screen gate on its Wish
 * button stays covered even without a route to hit.
 */
class PeoplePulsePartialTest extends TestCase
{
    use RefreshDatabase;

    private function birthdayEmployee(): Employee
    {
        $tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        $user = User::create(['name' => 'Bday', 'email' => 'bday'.uniqid().'@example.com', 'password' => Hash::make('password')]);
        $user->tenants()->attach($tenant->id, ['role' => 'employee']);

        return Employee::create([
            'tenant_id' => $tenant->id, 'user_id' => $user->id,
            'name' => 'Bday Person', 'status' => 'active', 'workload' => 'green',
            'date_of_birth' => now()->subYears(30),
        ]);
    }

    public function test_wish_button_shows_when_messages_screen_allowed(): void
    {
        $html = view('partials.people-pulse', [
            'onLeave' => collect(), 'birthdays' => collect([$this->birthdayEmployee()]), 'messagesAllowed' => true,
        ])->render();

        $this->assertStringContainsString('>Wish<', $html);
    }

    public function test_wish_button_hidden_when_messages_screen_not_allowed(): void
    {
        $html = view('partials.people-pulse', [
            'onLeave' => collect(), 'birthdays' => collect([$this->birthdayEmployee()]), 'messagesAllowed' => false,
        ])->render();

        $this->assertStringNotContainsString('>Wish<', $html);
    }
}
