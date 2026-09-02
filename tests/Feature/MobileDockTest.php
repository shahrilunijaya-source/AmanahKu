<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The phone's bottom dock: four nav tabs plus More, which opens a full-screen grid
 * of every other screen. The tabs are the first four entries of the nav this user
 * actually sees, so a role or module change must never leave a tab pointing at a
 * screen that is hidden for them — and the hamburger is gone, so that grid is the
 * only way into the rest of the nav below 900px.
 */
class MobileDockTest extends TestCase
{
    use RefreshDatabase;

    private function signIn(string $role = 'hr'): User
    {
        $tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        $user = User::create(['name' => 'Ana', 'email' => 'ana@acme.test', 'password' => Hash::make('password')]);
        $user->tenants()->attach($tenant->id, ['role' => $role]);

        $this->actingAs($user)->withSession(['current_tenant' => $tenant->id]);

        return $user;
    }

    /** The dock block, sliced out of the page. */
    private function dock(string $path = '/app/dash'): string
    {
        $html = $this->get($path)->assertOk()->getContent();

        $start = strpos($html, 'class="uj-dock"');
        $this->assertNotFalse($start, 'The mobile bottom dock (.uj-dock) is gone from the layout.');

        $end = strpos($html, '</nav>', $start);

        return substr($html, $start, $end - $start);
    }

    public function test_dock_carries_five_tabs_ending_in_more(): void
    {
        $this->signIn();

        $dock = $this->dock();

        $this->assertSame(5, substr_count($dock, 'uj-dock-tab'), 'The dock should hold four nav tabs plus More.');
        $this->assertStringContainsString('>More<', $dock);
        $this->assertStringContainsString('more = ! more', $dock, 'More no longer opens the grid.');
    }

    public function test_more_grid_holds_every_screen_the_tabs_leave_out(): void
    {
        $this->signIn();

        $html = $this->get('/app/dash')->assertOk()->getContent();
        $start = strpos($html, 'class="uj-dockmore"');
        $this->assertNotFalse($start, 'The More grid is gone.');
        $grid = substr($html, $start, strpos($html, 'class="uj-dock"', $start) - $start);

        // A screen well past the four tabs, and the section heading above it.
        $this->assertStringContainsString('/app/claims', $grid);
        $this->assertStringContainsString('>Learning<', $grid);
        // The grid opens over the page, not by sliding the desktop sidebar in.
        $this->assertStringNotContainsString('uj-sidebar', $grid);
    }

    public function test_dock_tabs_track_the_nav_this_user_sees(): void
    {
        $this->signIn('employee');

        $dock = $this->dock();

        // A plain employee gets no manager screens anywhere, dock included. The org
        // chart and the time-off calendar are the exception and stay reachable, see
        // SidebarNavTest.
        $this->assertStringNotContainsString('/app/directory', $dock);
        $this->assertStringContainsString('/app/dash', $dock);
    }

    public function test_current_screen_is_marked_on_its_tab(): void
    {
        $this->signIn();

        $this->assertStringContainsString('aria-current="page"', $this->dock('/app/attendance'));
    }

    public function test_hamburger_is_not_shown_below_the_dock_breakpoint(): void
    {
        // The button stays in the header markup — above 900px it is what expands the
        // collapsed rail. What must not come back is the rule that showed it on a
        // phone, where the dock's More is the way in now.
        $css = file_get_contents(resource_path('css/app.css'));
        $mobile = substr($css, strpos($css, '@media (max-width: 900px) {'));
        $mobile = substr($mobile, 0, strpos($mobile, "\n}"));

        $this->assertStringNotContainsString('.uj-nav-toggle', $mobile,
            'The header hamburger is showing on phones again; the dock is the way in.');
    }
}
