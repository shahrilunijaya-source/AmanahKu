<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Two notices hang off the header in their own band (.uj-head-pins) instead of
 * scrolling away with the head stack: the one-time password reveal, which is shown
 * once and costs a second reset if it is lost, and the overdue-timesheet alert.
 * Everything else in the stack scrolls, on purpose — a dismissible nudge that follows
 * you down every page is a nag.
 *
 * The band must stay a SIBLING of <main>, between it and the header. Inside <main> it
 * would be as narrow as the scroll area (stopping short at the scrollbar) instead of
 * spanning exactly what the header spans, and it would need sticky to stay put.
 */
class HeadPinsTest extends TestCase
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

    private function pageWithReveal(): string
    {
        $this->signIn();

        return $this->withSession(['reset_password' => [
            'name' => 'Bakar', 'email' => 'bakar@acme.test', 'password' => 'Temp-9x7Q', 'mail' => 'sent',
        ]])->get('/app/dash')->assertOk()->getContent();
    }

    public function test_password_reveal_is_pinned_and_sits_outside_the_head_stack(): void
    {
        $html = $this->pageWithReveal();

        $pins = strpos($html, 'class="uj-head-pins"');
        $stack = strpos($html, 'class="uj-head-stack');
        $reveal = strpos($html, 'One-time password for');

        $this->assertNotFalse($pins, 'The pinned wrapper is gone; the notices scroll away again.');
        $this->assertNotFalse($reveal);
        $this->assertLessThan($stack, $pins, 'The pins must come before the head stack.');
        $this->assertGreaterThan($pins, $reveal);
        $this->assertLessThan($stack, $reveal, 'The password reveal fell back into the scrolling head stack.');
    }

    public function test_band_sits_between_the_header_and_main(): void
    {
        $html = $this->pageWithReveal();

        $header = strpos($html, 'class="uj-hd-fade"');
        $band = strpos($html, 'class="uj-head-pins"');
        $main = strpos($html, '<main class="uj-main');

        $this->assertNotFalse($band);
        $this->assertLessThan($band, $header, 'The band jumped above the header.');
        $this->assertLessThan($main, $band, 'The band fell back inside <main>, where it is scroll-width, not header-width.');

        // <main> must know the band took the header clearance, or it repeats it.
        $this->assertStringContainsString('uj-main--pinned', $html);
    }

    public function test_notices_render_as_edge_to_edge_square_bars(): void
    {
        $html = $this->pageWithReveal();

        $this->assertStringContainsString('<div class="uj-head-pins">', $html);
        // The notice showing here carries the bar class; without it it keeps its card
        // shape. (Only the reveal shows for this fixture — the overdue alert needs an
        // employee record and a late week.)
        $this->assertStringContainsString('uj-pin-bar', $html,
            'The pinned notice lost .uj-pin-bar and went back to being a rounded card.');

        // Both notices in the layout must carry it, not just the one this page renders.
        $blade = file_get_contents(resource_path('views/layouts/app.blade.php'));
        $this->assertSame(2, substr_count($blade, 'uj-pin-bar') - substr_count($blade, '.uj-pin-bar'),
            'A pinned notice in the layout is missing .uj-pin-bar.');

        $css = file_get_contents(resource_path('css/app.css'));
        preg_match('/\.uj-head-pins \{(.*?)\}/s', $css, $band);
        preg_match('/\.uj-head-pins \.uj-pin-bar \{(.*?)\}/s', $css, $bar);

        $this->assertNotEmpty($band, 'The band lost its rule.');
        $this->assertNotEmpty($bar, 'The bar lost its rule.');
        $this->assertStringNotContainsString('sticky', $band[1],
            'The band went back to sticky. Outside <main> it holds position on its own.');
        $this->assertStringContainsString('margin-top: 56px', $band[1],
            'The band no longer clears the 56px header, which is absolute and takes no flow space.');
        $this->assertStringContainsString('border-radius: 0', $bar[1], 'The bars rounded off again.');
    }

    public function test_nothing_is_pinned_when_neither_notice_is_showing(): void
    {
        $this->signIn();

        $html = $this->get('/app/dash')->assertOk()->getContent();

        $this->assertStringNotContainsString('uj-head-pins', $html,
            'An empty pinned strip renders and eats space under the header.');
    }
}
