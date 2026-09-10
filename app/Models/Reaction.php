<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * One reaction in the tenant's own set (CR-30). Reactions are just reactions: they
 * notify nobody and feed no award. HR adds (at most ten active) or retires one;
 * nothing renames one, so an old tally keeps reading the way it did when it was left.
 *
 * The set is per tenant, seeded with the eight Unijaya defaults the first time anyone
 * asks for it. The three reaction tables store the `key` in their `emoji` column.
 */
class Reaction extends Model
{
    use BelongsToTenant;

    public const MAX_ACTIVE = 10;

    /** @var list<array{key: string, label: string, icon: string}> */
    public const DEFAULTS = [
        ['key' => 'power', 'label' => 'Power', 'icon' => '⚡'],
        ['key' => 'legend', 'label' => 'Legend', 'icon' => '🏆'],
        ['key' => 'chefs_kiss', 'label' => "Chef's Kiss", 'icon' => '🤌'],
        ['key' => 'noted_with_fear', 'label' => 'Noted With Fear', 'icon' => '😨'],
        ['key' => 'send_help', 'label' => 'Send Help', 'icon' => '🆘'],
        ['key' => 'respect', 'label' => 'Respect', 'icon' => '🫡'],
        ['key' => 'how_did_you_do_this', 'label' => 'How Did You Do This?', 'icon' => '🤯'],
        ['key' => 'claim_bila', 'label' => 'Claim Bila?', 'icon' => '🧾'],
    ];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['retired_at' => 'datetime'];
    }

    /**
     * The whole set for the current tenant, retired ones included, seeding the defaults once.
     *
     * @return Collection<int, Reaction>
     */
    public static function set(): Collection
    {
        if (! self::query()->exists()) {
            foreach (self::DEFAULTS as $i => $row) {
                self::create($row + ['sort' => $i]);
            }
        }

        return self::query()->orderBy('sort')->orderBy('id')->get();
    }

    /**
     * Only what a picker offers.
     *
     * @return Collection<int, Reaction>
     */
    public static function active(): Collection
    {
        return self::set()->whereNull('retired_at')->values();
    }

    /** @return list<string> */
    public static function activeKeys(): array
    {
        return self::active()->pluck('key')->all();
    }

    /**
     * key => ['label' => ..., 'icon' => ...] for every reaction ever in the set, so a tally
     * on an old item still reads after the reaction is retired. An unknown key (an emoji
     * left before CR-30) reads as itself.
     *
     * @return array<string, array{label: string, icon: string, retired: bool}>
     */
    public static function labels(): array
    {
        return self::set()->mapWithKeys(fn (Reaction $r) => [$r->key => [
            'label' => $r->label, 'icon' => $r->icon, 'retired' => $r->retired_at !== null,
        ]])->all();
    }

    /** @return array{label: string, icon: string, retired: bool} */
    public static function describe(string $key): array
    {
        return self::labels()[$key] ?? ['label' => $key, 'icon' => $key, 'retired' => true];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('retired_at');
    }

    /** @return array{key: string, label: string, icon: string, retired: bool} */
    public function toPayload(): array
    {
        return ['key' => $this->key, 'label' => $this->label, 'icon' => $this->icon, 'retired' => $this->retired_at !== null];
    }
}
