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
 * reachable only from the panel that opens beside the row. That is easy to undo by
 * accident: drop the panel and the nav quietly loses every link but the section
 * rows. The sidebar is desktop-only — below 900px it does not render and the bottom
 * dock takes over, see MobileDockTest.
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

    /** The sidebar's nav column. */
    private function desktopNav(): string
    {
        $html = $this->get('/app/dash')->assertOk()->getContent();

        $start = strpos($html, 'class="uj-sb-nav"');
        $this->assertNotFalse($start, 'The sidebar nav column (.uj-sb-nav) is gone.');

        $end = strpos($html, '</nav>', $start);

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

    public function test_listed_down_layout_ships_alongside_the_panel(): void
    {
        $this->signIn();

        $nav = $this->desktopNav();

        // Both bodies are in the HTML on every page; a CSS class picks one, so the
        // switch costs no request. Lose either and the switch flips to an empty column.
        $this->assertStringContainsString('uj-nav-sections', $nav, 'The section body is gone.');
        $this->assertStringContainsString('uj-nav-tree', $nav, 'The listed-down body is gone.');
    }

    public function test_listed_down_layout_reaches_every_screen_the_panel_does(): void
    {
        $this->signIn();

        $nav = $this->desktopNav();
        $tree = substr($nav, strpos($nav, 'class="uj-nav-tree"'));

        // Same screens as the panel test, drawn the other way. A group's children are
        // indented under it here rather than waiting behind a second hover.
        foreach (['attendance', 'timesheets', 'leave', 'claims', 'attendance-report', 'resignation'] as $screen) {
            $this->assertStringContainsString(route('app.screen', ['screen' => $screen]), $tree,
                sprintf('%s is not linked in the listed-down sidebar.', $screen));
        }

        // A group (Oversight, Offboarding) opens the way its section does — pointing at
        // it drops its screens open. Showing them outright makes the section tower over
        // the others for screens most people never open.
        $this->assertStringContainsString('openKid', $tree,
            'A group in the listed-down sidebar shows its screens outright instead of opening on hover.');
    }

    public function test_the_layout_switch_is_on_the_page(): void
    {
        $this->signIn();

        $html = $this->get('/app/dash')->assertOk()->getContent();

        $this->assertStringContainsString('toggleSbStyle()', $html,
            'The control that swaps the two sidebar layouts is gone.');
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
