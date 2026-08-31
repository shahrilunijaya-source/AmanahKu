<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Amanahku;
use PHPUnit\Framework\TestCase;

class AmanahkuLinkifyTest extends TestCase
{
    public function test_raw_html_is_escaped_not_executed(): void
    {
        $html = Amanahku::linkify('<script>alert(1)</script>');

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function test_javascript_scheme_links_are_neutralized(): void
    {
        $html = Amanahku::linkify('[click me](javascript:alert(1))');

        $this->assertStringNotContainsString('javascript:', $html);
    }

    public function test_bare_url_autolinks(): void
    {
        $html = Amanahku::linkify('See https://example.com/path for details.');

        $this->assertStringContainsString('<a href="https://example.com/path"', $html);
    }

    public function test_single_newline_becomes_a_line_break(): void
    {
        $html = Amanahku::linkify("line one\nline two");

        $this->assertStringContainsString('line one<br', $html);
        $this->assertStringContainsString('line two', $html);
        // Still one paragraph, not two.
        $this->assertSame(1, substr_count($html, '<p>'));
    }

    public function test_blank_line_starts_a_new_paragraph(): void
    {
        $html = Amanahku::linkify("first paragraph\n\nsecond paragraph");

        $this->assertSame(2, substr_count($html, '<p>'));
    }

    public function test_markdown_formatting_renders(): void
    {
        $html = Amanahku::linkify("# Heading\n\n**bold** and _italic_\n\n- one\n- two");

        $this->assertStringContainsString('<h1>Heading</h1>', $html);
        $this->assertStringContainsString('<strong>bold</strong>', $html);
        $this->assertStringContainsString('<em>italic</em>', $html);
        $this->assertStringContainsString('<li>one</li>', $html);
        $this->assertStringContainsString('<li>two</li>', $html);
    }

    public function test_existing_bullet_character_posts_still_render_as_line_breaks(): void
    {
        // Real Knowledge Bank post body (id 14, "Using adaptive approaches in our
        // project") written before markdown support existed: "•" bullets, single
        // \r\n between lines, blank line between sections. Must keep reading the
        // same way it did under the old escape+nl2br renderer.
        $body = "In Agile;\r\n\r\nWe value:\r\n"
            ."\u{2022} Individuals and interaction over process and tools\r\n"
            ."\u{2022} Working software over comprehensive documentation";

        $html = Amanahku::linkify($body);

        $this->assertStringContainsString('Individuals and interaction over process and tools<br', $html);
        $this->assertStringContainsString('We value:<br', $html);
        // "In Agile;" and "We value:" are separated by a blank line -> new paragraph.
        $this->assertSame(2, substr_count($html, '<p>'));
    }
}
