<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Mints a tenant-bound API token for a user and prints the plaintext exactly once.
 *
 * Usage: php artisan api:token user@example.com acme-corp
 *
 * The plaintext is shown only here — only its sha256 hash is stored — so it cannot
 * be recovered later. The token is bound to the given tenant; calls made with it are
 * scoped to that tenant and inherit the user's role within it. Minting is refused if
 * the user is not a member of the tenant.
 */
class MintApiToken extends Command
{
    protected $signature = 'api:token {user_email} {tenant_slug} {--name=api : A label for the token}';

    protected $description = 'Mint a tenant-scoped API token for a user (plaintext printed once).';

    public function handle(): int
    {
        $email = (string) $this->argument('user_email');
        $slug = (string) $this->argument('tenant_slug');

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
            $token = $user->mintApiToken($tenant, (string) $this->option('name'));
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
