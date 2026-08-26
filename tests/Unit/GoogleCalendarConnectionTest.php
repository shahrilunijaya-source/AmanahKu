<?php

namespace Tests\Unit;

use App\Models\GoogleCalendarConnection;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class GoogleCalendarConnectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_tokens_are_encrypted_at_rest_and_readable_through_the_model(): void
    {
        $user = User::create(['name' => 'Demo', 'email' => 'demo@example.com', 'password' => Hash::make('password')]);

        $connection = GoogleCalendarConnection::create([
            'user_id' => $user->id,
            'access_token' => 'plain-access-token',
            'refresh_token' => 'plain-refresh-token',
            'expires_at' => now()->addHour(),
        ]);

        $raw = DB::table('google_calendar_connections')->where('id', $connection->id)->first();
        $this->assertStringNotContainsString('plain-access-token', $raw->access_token);
        $this->assertStringNotContainsString('plain-refresh-token', $raw->refresh_token);

        $fresh = GoogleCalendarConnection::find($connection->id);
        $this->assertSame('plain-access-token', $fresh->access_token);
        $this->assertSame('plain-refresh-token', $fresh->refresh_token);
        $this->assertTrue($fresh->user->is($user));
    }

    public function test_one_connection_per_user(): void
    {
        $user = User::create(['name' => 'Demo', 'email' => 'demo@example.com', 'password' => Hash::make('password')]);
        GoogleCalendarConnection::create([
            'user_id' => $user->id, 'access_token' => 'a', 'refresh_token' => 'b', 'expires_at' => now(),
        ]);

        $this->expectException(QueryException::class);
        GoogleCalendarConnection::create([
            'user_id' => $user->id, 'access_token' => 'c', 'refresh_token' => 'd', 'expires_at' => now(),
        ]);
    }
}
