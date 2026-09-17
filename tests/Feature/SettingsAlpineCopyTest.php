<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Bilingual labels are JS expressions ('en' ? '...' : '...') inside x-text. An
 * apostrophe in the copy ends the string early and Alpine throws, so the label
 * never switches language. &#39; does not help: the browser decodes it back to
 * a plain quote before Alpine reads the attribute.
 */
class SettingsAlpineCopyTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_x_text_on_company_settings_has_balanced_quotes(): void
    {
        $tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        $hr = User::create(['name' => 'HR', 'email' => 'hr@example.com', 'password' => Hash::make('password')]);
        $hr->tenants()->attach($tenant->id, ['role' => 'hr']);
        Employee::create(['tenant_id' => $tenant->id, 'user_id' => $hr->id, 'name' => 'HR', 'status' => 'active', 'workload' => 'green']);

        $html = $this->actingAs($hr)->withSession(['current_tenant' => $tenant->id])
            ->get('/app/settings')->assertOk()->getContent();

        preg_match_all('/\sx-text="([^"]*)"/', $html, $m);
        $this->assertNotEmpty($m[1]);

        $broken = array_values(array_filter($m[1], function (string $attr): bool {
            $js = html_entity_decode($attr, ENT_QUOTES | ENT_HTML5);

            return preg_match_all("/(?<!\\\\)'/", $js) % 2 === 1;
        }));

        $this->assertSame([], $broken, 'x-text with an unbalanced quote: '.implode(' | ', $broken));
    }
}
