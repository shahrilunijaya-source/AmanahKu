<?php

namespace App\Models;

use App\Support\Permissions;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Sanctum\NewAccessToken;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'])]
class User extends Authenticatable implements MustVerifyEmail, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'password_change_required' => 'boolean',
            'is_super_admin' => 'boolean',
        ];
    }

    /** Super-admins provision tenants and seed each tenant's first HR admin. */
    public function isSuperAdmin(): bool
    {
        return (bool) $this->is_super_admin;
    }

    /** Up-to-2-letter monogram from the name, for the initials avatar (no external image). */
    public function initials(): string
    {
        $words = preg_split('/\s+/', trim((string) $this->name)) ?: [];
        $letters = array_map(fn ($w) => mb_substr($w, 0, 1), array_filter($words));

        return mb_strtoupper(implode('', array_slice($letters, 0, 2))) ?: '?';
    }

    /** Deterministic avatar colour from the email, drawn from the app palette. */
    public function avatarColor(): string
    {
        $palette = config('amanahku.avatar_palette');

        return $palette[crc32((string) $this->email) % count($palette)];
    }

    /** Tenants this user can access, with their role + data scope per tenant. */
    public function tenants(): BelongsToMany
    {
        return $this->belongsToMany(Tenant::class)
            ->withPivot('role', 'data_scope')
            ->withTimestamps();
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    /** Role string (employee|manager|management|hr) for a given tenant. */
    public function roleIn(Tenant $tenant): string
    {
        return $this->tenants->firstWhere('id', $tenant->id)?->pivot->role ?? 'employee';
    }

    /** Data access scope (own|team|department|branch|company) for a given tenant. */
    public function dataScopeIn(Tenant $tenant): string
    {
        return $this->tenants->firstWhere('id', $tenant->id)?->pivot->data_scope ?? 'company';
    }

    /**
     * Base permissions from this user's role in a tenant (before per-user overrides).
     *
     * @return array<int, string>
     */
    public function permissionsIn(Tenant $tenant): array
    {
        return Permissions::forRole($this->roleIn($tenant));
    }

    /**
     * Effective permissions in a tenant: role permissions, then per-user overrides
     * (grant adds, deny removes). This is the resolved "role + user override" of the
     * access formula. Data scope and feature entitlement are applied separately.
     *
     * @return array<int, string>
     */
    public function resolvedPermissionsIn(Tenant $tenant): array
    {
        $perms = array_fill_keys($this->permissionsIn($tenant), true);

        foreach (UserPermission::query()
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $this->id)
            ->get() as $override) {
            if ($override->granted) {
                $perms[$override->permission] = true;
            } else {
                unset($perms[$override->permission]);
            }
        }

        return array_keys($perms);
    }

    /** Does this user have a permission in a tenant, after role + overrides? */
    public function canInTenant(Tenant $tenant, string $permission): bool
    {
        return in_array($permission, $this->resolvedPermissionsIn($tenant), true);
    }

    /** The employee record representing this user in the given tenant. */
    public function employeeFor(Tenant $tenant): ?Employee
    {
        return Employee::withoutGlobalScope('tenant')
            ->where('user_id', $this->id)
            ->where('tenant_id', $tenant->id)
            ->first();
    }

    /**
     * Mint an API token bound to a tenant the user actually belongs to.
     *
     * Sanctum stores only the sha256 hash of the token; the plaintext is returned
     * once via NewAccessToken::plainTextToken and never persisted. The token row
     * carries tenant_id so the API bearer middleware can scope the whole request to
     * that one tenant. Refuses to mint for a tenant the user is not a member of, so
     * a token can never grant access the user does not already have.
     */
    public function mintApiToken(Tenant $tenant, string $name, array $abilities = ['*']): NewAccessToken
    {
        if (! $this->tenants->contains('id', $tenant->id)) {
            throw new \InvalidArgumentException("User {$this->email} is not a member of tenant {$tenant->slug}.");
        }

        $token = $this->createToken($name, $abilities);
        $token->accessToken->forceFill(['tenant_id' => $tenant->id])->save();

        return $token;
    }
}
