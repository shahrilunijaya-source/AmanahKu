<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Csv;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CsvTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function formulaTriggers(): array
    {
        return [
            'equals' => ['=1+1', "'=1+1"],
            'plus' => ['+1', "'+1"],
            'minus' => ['-1', "'-1"],
            'at' => ['@SUM(A1)', "'@SUM(A1)"],
            'tab' => ["\tcmd", "'\tcmd"],
            'carriage return' => ["\rcmd", "'\rcmd"],
            'DDE payload' => ["=cmd|'/c calc'!A1", "'=cmd|'/c calc'!A1"],
        ];
    }

    #[DataProvider('formulaTriggers')]
    public function test_it_prefixes_cells_that_start_with_a_formula_trigger(string $input, string $expected): void
    {
        $this->assertSame($expected, Csv::safe($input));
    }

    public function test_it_leaves_ordinary_values_untouched(): void
    {
        $this->assertSame('Aisyah Rahman', Csv::safe('Aisyah Rahman'));
        $this->assertSame('880101-10-1234', Csv::safe('880101-10-1234')); // digits, no leading trigger
        $this->assertSame('92', Csv::safe(92));
        $this->assertSame('', Csv::safe(null));
        $this->assertSame('', Csv::safe(''));
    }

    public function test_safe_row_neutralises_every_cell(): void
    {
        $this->assertSame(["'=evil", 'ok', "'+2"], Csv::safeRow(['=evil', 'ok', '+2']));
    }
}
