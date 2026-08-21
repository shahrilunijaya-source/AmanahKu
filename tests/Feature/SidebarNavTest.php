<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Support\Amanahku;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The desktop sidebar's shape: a column of SECTIONS, with the screens inside one
 * reachable only from the panel that opens beside the row. The old tree — every
 * section header and every screen rendered inline — still ships for the mobile
 * drawer, where there is no hover to open a panel with, so both blocks live in the
 * blade and CSS shows exactly one of them. That is easy to undo by accident: drop
 * the panel and the desktop nav quietly loses every link but the section rows.
 */
class SidebarNavTest extends TestCase
{
    use RefreshDatabase;

    private function signIn(): User
    {
        $tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        $user = User::create(['name' => 'Ana', 'email' => 'ana@acme.test', 'password' => Hash::make('password')]);
        $user->tenants()->attach($tenant->id, ['role' => 'hr']);

        $this->actingAs($user)->withSession(['current_tenant' => $tenant->id]);

        return $user;
    }

    /** The desktop nav block, sliced off before the mobile tree starts. */
    private function desktopNav(): string
    {
        $html = $this->get('/app/dash')->assertOk()->getContent();

        $start = strpos($html, 'class="uj-nav-secs"');
        $end = strpos($html, 'class="uj-nav-tree"');

        $this->assertNotFalse($start, 'The desktop section nav (.uj-nav-secs) is gone from the sidebar.');
        $this->assertNotFalse($end, 'The mobile tree (.uj-nav-tree) is gone — the drawer has no nav below 900px.');
        $this->assertLessThan($end, $start);

        return substr($html, $start, $end - $start);
    }

    public function test_desktop_nav_lists_sections_not_screens(): void
    {
        $this->signIn();

        $nav = $this->desktopNav();

        $this->assertStringContainsString('>My Work<', $nav, 'The My Work section row is missing.');
        // The screens themselves belong to the panel, never to the sidebar column.
        $this->assertStringNotContainsString('uj-nav-kids', $nav,
            'A nested child list came back to the desktop sidebar. Children live in the section panel now.');
    }

    public function test_section_panel_carries_that_sections_screens(): void
    {
        $this->signIn();

        $nav = $this->desktopNav();

        $this->assertStringContainsString('uj-fly-grid', $nav, 'The section panel is gone — the desktop nav has no links left.');

        // My Work's own screens, plus two that hang off a group (Oversight's
        // reports, Offboarding's clearance) — a group keeps one cell and opens its
        // screens in a sub-panel, so they are still linked from the desktop nav.
        foreach (['attendance', 'timesheets', 'leave', 'claims', 'attendance-report', 'resignation'] as $screen) {
            $this->assertStringContainsString(route('app.screen', ['screen' => $screen]), $nav,
                sprintf('%s is not linked anywhere in the desktop nav.', $screen));
        }

        $this->assertStringContainsString('uj-fly-sub', $nav,
            "A group's sub-panel is gone. Oversight and Offboarding hold one cell each and open their screens beside it.");
    }

    public function test_every_nav_section_has_an_icon(): void
    {
        $sections = collect(Amanahku::nav())->pluck('section')->unique();

        foreach ($sections as $section) {
            $this->assertNotSame('M12 12h.01', Amanahku::sectionIcon($section), sprintf(
                'Section "%s" has no icon and falls back to a dot. Add one in Amanahku::sectionIcon().',
                $section
            ));
        }
    }
}
