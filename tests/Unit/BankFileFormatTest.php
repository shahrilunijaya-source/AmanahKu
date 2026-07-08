<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Payroll\BankFile\BankFileRegistry;
use App\Services\Payroll\BankFile\GenericCsvFormat;
use PHPUnit\Framework\TestCase;

class BankFileFormatTest extends TestCase
{
    public function test_registry_exposes_unique_keyed_options(): void
    {
        $options = BankFileRegistry::options();

        $this->assertNotEmpty($options);
        $this->assertArrayHasKey('generic', $options);
        $this->assertSame(array_keys($options), array_unique(array_keys($options)));
    }

    public function test_find_resolves_a_known_key(): void
    {
        $this->assertSame('maybank2u', BankFileRegistry::find('maybank2u')->key());
        $this->assertSame('duitnow', BankFileRegistry::find('duitnow')->key());
    }

    public function test_unknown_or_empty_key_falls_back_to_generic(): void
    {
        $this->assertInstanceOf(GenericCsvFormat::class, BankFileRegistry::find('does-not-exist'));
        $this->assertInstanceOf(GenericCsvFormat::class, BankFileRegistry::find(null));
    }

    public function test_only_the_generic_format_is_marked_verified(): void
    {
        foreach (BankFileRegistry::all() as $format) {
            $expected = $format->key() === 'generic';
            $this->assertSame($expected, $format->verified(), $format->key().' verified flag');
        }
    }
}
