<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\User;
use Tests\TestCase;

/**
 * Every avatar background carries white initials on top of it. Initials name a
 * person, so they are functional small text and must clear WCAG AA (4.5:1). The
 * palette is easy to extend by hand with a colour that looks fine but fails, so
 * the ratio is asserted here rather than trusted.
 */
class AvatarPaletteContrastTest extends TestCase
{
    private const MINIMUM_RATIO = 4.5;

    public function test_every_avatar_colour_carries_white_text(): void
    {
        /** @var list<string> $palette */
        $palette = config('amanahku.avatar_palette');
        $palette[] = config('amanahku.avatar_color');

        foreach ($palette as $hex) {
            $this->assertGreaterThanOrEqual(
                self::MINIMUM_RATIO,
                $this->contrastWithWhite($hex),
                "{$hex} fails WCAG AA for white small text.",
            );
        }
    }

    public function test_generated_avatar_colour_comes_from_the_palette(): void
    {
        $user = new User(['email' => 'someone@amanahku.test']);

        $this->assertContains($user->avatarColor(), config('amanahku.avatar_palette'));
    }

    /** WCAG 2.x contrast ratio of #ffffff over the given background hex. */
    private function contrastWithWhite(string $hex): float
    {
        $channels = array_map(
            static function (int $offset) use ($hex): float {
                $value = hexdec(substr($hex, $offset, 2)) / 255;

                return $value <= 0.03928 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
            },
            [1, 3, 5],
        );

        $luminance = 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];

        return 1.05 / ($luminance + 0.05);
    }
}
