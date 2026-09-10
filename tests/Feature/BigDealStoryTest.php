<?php

namespace Tests\Feature;

use App\Models\BigDeal;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BigDealStoryTest extends TestCase
{
    #[Test]
    public function test_first_line_is_the_one_liner_when_more_lines_follow(): void
    {
        $deal = new BigDeal(['story' => "Breathe again.\nThree weeks of fixes.\n\nOne patient client."]);

        $this->assertSame(['Breathe again.', ['Three weeks of fixes.', 'One patient client.']], $deal->storyParts());
    }

    #[Test]
    public function test_single_paragraph_story_fills_the_what_it_took_box(): void
    {
        $deal = new BigDeal(['story' => 'Three weeks of late nights, zero showstoppers left.']);

        $this->assertSame(['', ['Three weeks of late nights, zero showstoppers left.']], $deal->storyParts());
    }

    #[Test]
    public function test_empty_story_yields_nothing(): void
    {
        $this->assertSame(['', []], (new BigDeal(['story' => null]))->storyParts());
    }
}
