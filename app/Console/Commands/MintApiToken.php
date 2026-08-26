<?php

namespace App\Console\Commands;

use App\Models\ApiClient;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Mints a tenant-bound API token for a user and prints the plaintext exactly once.
 *
 * Usage: php artisan api:token user@example.com acme-corp
 * Usage (limited scope): php artisan api:token user@example.com acme-corp --ability=timesheets:read --ability=board:read
 *
 * The plaintext is shown only here — only its sha256 hash is stored — so it cannot
 * be recovered later. The token is bound to the given tenant; calls made with it are
 * scoped to that tenant and inherit the user's role within it. Minting is refused if
 * the user is not a member of the tenant.
 *
 * Without --ability the token keeps the historical ['*'] behaviour (every scope, the
 * same as every other person-token). Passing one or more --ability values mints a
 * token restricted to exactly that list instead — for handing someone a key that
 * should only reach the MCP server's read-only tools, for instance.
 */
class MintApiToken extends Command
{
    protected $signature = 'api:token {user_email} {tenant_slug} {--name=api : A label for the token} {--ability=* : Restrict the token to these scopes (ApiClient::SCOPES keys). Omit for the default [*].}';

    protected $description = 'Mint a tenant-scoped API token for a user (plaintext printed once), optionally restricted to specific --ability scopes.';

    public function handle(): int
    {
        $email = (string) $this->argument('user_email');
        $slug = (string) $this->argument('tenant_slug');

        /** @var list<string> $abilities */
        $abilities = (array) $this->option('ability');

        if ($abilities !== []) {
            $unknown = array_diff($abilities, array_keys(ApiClient::SCOPES));
            if ($unknown !== []) {
                $this->error('Unknown ability: '.implode(', ', $unknown).'. Valid abilities: '.implode(', ', array_keys(ApiClient::SCOPES)).'.');

                return self::FAILURE;
            }
        }

        $user = User::where('email', $email)->first();
        if (! $user) {
            $this->error("No user found with email {$email}.");

            return self::FAILURE;
        }

        $tenant = Tenant::where('slug', $slug)->first();
        if (! $tenant) {
            $this->error("No tenant found with slug {$slug}.");

            return self::FAILURE;
        }

        try {
            $token = $user->mintApiToken($tenant, (string) $this->option('name'), $abilities !== [] ? $abilities : ['*']);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("API token minted for {$user->email} on tenant {$tenant->slug}.");
        $this->newLine();
        $this->line('  '.$token->plainTextToken);
        $this->newLine();
        $this->warn('Store it now — it cannot be shown again.');

        return self::SUCCESS;
    }
}
