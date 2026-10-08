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
        $html = $this->settingsPage('/app/settings');

        preg_match_all('/\sx-text="([^"]*)"/', $html, $m);
        $this->assertNotEmpty($m[1]);

        $broken = array_values(array_filter($m[1], function (string $attr): bool {
            $js = html_entity_decode($attr, ENT_QUOTES | ENT_HTML5);

            return preg_match_all("/(?<!\\\\)'/", $js) % 2 === 1;
        }));

        $this->assertSame([], $broken, 'x-text with an unbalanced quote: '.implode(' | ', $broken));
    }

    /**
     * The full page gets a section index whose every link lands on a card, and the
     * statutory link carries the "payroll blocked" dot while the numbers are blank.
     */
    public function test_full_page_has_a_section_index_that_points_at_real_cards(): void
    {
        $html = $this->settingsPage('/app/settings');

        $this->assertStringContainsString('class="uj-cs-idx"', $html);
        preg_match_all('/<nav class="uj-cs-idx".*?<\/nav>/s', $html, $nav);
        preg_match_all('/href="#([a-z-]+)"/', $nav[0][0], $anchors);

        $this->assertContains('profile', $anchors[1]);
        $this->assertContains('statutory', $anchors[1]);
        $this->assertContains('employment-types', $anchors[1]);
        foreach ($anchors[1] as $anchor) {
            $this->assertStringContainsString('id="'.$anchor.'"', $html, "Index link #{$anchor} has no card");
        }
        $this->assertStringContainsString('uj-cs-dot', $nav[0][0]);
    }

    public function test_single_section_view_has_no_section_index(): void
    {
        $html = $this->settingsPage('/app/settings?section=branches');

        $this->assertStringNotContainsString('class="uj-cs-idx"', $html);
        $this->assertStringContainsString('id="branches"', $html);
        $this->assertStringNotContainsString('id="profile"', $html);
    }

    private function settingsPage(string $url): string
    {
        $tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        $hr = User::create(['name' => 'HR', 'email' => 'hr@example.com', 'password' => Hash::make('password')]);
        $hr->tenants()->attach($tenant->id, ['role' => 'hr']);
        Employee::create(['tenant_id' => $tenant->id, 'user_id' => $hr->id, 'name' => 'HR', 'status' => 'active', 'workload' => 'green']);

        return $this->actingAs($hr)->withSession(['current_tenant' => $tenant->id])
            ->get($url)->assertOk()->getContent();
    }
}
