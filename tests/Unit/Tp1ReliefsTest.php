<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Tp1Reliefs;
use PHPUnit\Framework\TestCase;

class Tp1ReliefsTest extends TestCase
{
    public function test_a_claim_over_the_cap_is_trimmed(): void
    {
        $this->assertSame(2500.0, Tp1Reliefs::allowed('lifestyle', 4000, 0));
        $this->assertSame(350.0, Tp1Reliefs::allowed('socso', 500, 0));
    }

    public function test_a_claim_after_the_cap_is_used_gives_nothing(): void
    {
        $this->assertSame(0.0, Tp1Reliefs::allowed('lifestyle', 800, 2500));
        $this->assertSame(200.0, Tp1Reliefs::allowed('lifestyle', 800, 2300));
    }

    public function test_every_entry_carries_a_label_in_both_languages_and_a_positive_cap(): void
    {
        $this->assertNotSame([], Tp1Reliefs::LIST);
        foreach (Tp1Reliefs::LIST as $code => $entry) {
            $this->assertNotSame('', $entry['label'], $code);
            $this->assertNotSame('', $entry['label_ms'], $code);
            $this->assertGreaterThan(0, $entry['cap'], $code);
        }
    }
}
