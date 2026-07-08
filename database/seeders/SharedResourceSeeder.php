<?php

namespace Database\Seeders;

use App\Models\SharedResource;
use App\Models\Tenant;
use Illuminate\Database\Seeder;

class SharedResourceSeeder extends Seeder
{
    /**
     * Seed the company's named shared accounts/tools for the first tenant as
     * placeholders — names + categories filled, credentials left blank for HR to
     * complete in-app. Safe to re-run: skips if the first tenant already has rows.
     * tenant_id is set explicitly because the global scope is inactive in seeders.
     */
    public function run(): void
    {
        $tenant = Tenant::query()->orderBy('id')->first();
        if (! $tenant) {
            return;
        }

        $tid = $tenant->id;

        if (SharedResource::where('tenant_id', $tid)->exists()) {
            return;
        }

        // [name, category, sort_order] — url/username/password/notes filled by HR.
        $plan = [
            ['Unijaya Gmail', 'email', 1],
            ['Canva', 'design', 2],
            ['Blue Dot', 'other', 3],
            ['Company WhatsApp', 'comms', 4],
            ['Inhouse System', 'system', 5],
        ];

        foreach ($plan as [$name, $category, $sort]) {
            SharedResource::create([
                'tenant_id' => $tid,
                'name' => $name,
                'category' => $category,
                'sort_order' => $sort,
            ]);
        }
    }
}
