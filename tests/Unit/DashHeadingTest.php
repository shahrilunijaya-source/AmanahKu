<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Employee;
use App\Support\Amanahku;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The employee dashboard heading must reflect the logged-in viewer and the real
 * clock, not the frozen "Good morning, Aisyah · Tuesday, 23 June 2026" demo copy
 * ported from the design reference.
 */
class DashHeadingTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function headingAt(string $datetime, ?string $name): array
    {
        Carbon::setTestNow(Carbon::parse($datetime, 'Asia/Kuala_Lumpur'));
        $employee = $name === null ? null : new Employee(['name' => $name]);

        return Amanahku::dashHeading('employee', [], $employee);
    }

    public function test_greeting_uses_the_viewers_first_name_not_a_hardcoded_one(): void
    {
        $heading = $this->headingAt('2026-07-07 09:00', 'Nabil Syafiq Bin Azlan');

        // First token only — never the full Malay name, never "Aisyah".
        $this->assertStringContainsString('Nabil', $heading['title']);
        $this->assertStringNotContainsString('Syafiq', $heading['title']);
        $this->assertStringNotContainsString('Aisyah', $heading['title']);
    }

    public function test_first_name_is_the_first_whitespace_token(): void
    {
        $this->assertSame('Good morning, Aisyah', $this->headingAt('2026-07-07 09:00', 'Aisyah Rahman')['title']);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function greetingWindows(): array
    {
        return [
            'morning' => ['2026-07-07 09:00', 'Good morning, Aminah', 'Selamat pagi, Aminah'],
            'noon' => ['2026-07-07 13:00', 'Good afternoon, Aminah', 'Selamat tengah hari, Aminah'],
            'afternoon 4pm (the bug)' => ['2026-07-07 16:00', 'Good afternoon, Aminah', 'Selamat petang, Aminah'],
            'evening' => ['2026-07-07 20:00', 'Good evening, Aminah', 'Selamat malam, Aminah'],
            'midnight (night shift)' => ['2026-07-07 00:30', 'Good evening, Aminah', 'Selamat malam, Aminah'],
            'pre-dawn boundary 04:59' => ['2026-07-07 04:59', 'Good evening, Aminah', 'Selamat malam, Aminah'],
            'dawn boundary 05:00' => ['2026-07-07 05:00', 'Good morning, Aminah', 'Selamat pagi, Aminah'],
            'boundary 11:59' => ['2026-07-07 11:59', 'Good morning, Aminah', 'Selamat pagi, Aminah'],
            'boundary 12:00' => ['2026-07-07 12:00', 'Good afternoon, Aminah', 'Selamat tengah hari, Aminah'],
        ];
    }

    #[DataProvider('greetingWindows')]
    public function test_greeting_tracks_time_of_day_in_both_languages(string $at, string $en, string $ms): void
    {
        $heading = $this->headingAt($at, 'Aminah');

        $this->assertSame($en, $heading['title']);
        $this->assertSame($ms, $heading['title_ms']);
    }

    public function test_subtitle_shows_todays_real_date_in_both_languages(): void
    {
        $heading = $this->headingAt('2026-07-07 16:00', 'Aminah');

        $this->assertSame("Tuesday, 7 July 2026 · here's what needs your attention today.", $heading['sub']);
        $this->assertSame('Selasa, 7 Julai 2026 · ini perkara yang perlu perhatian anda hari ini.', $heading['sub_ms']);
        // The frozen mockup date must never reappear.
        $this->assertStringNotContainsString('23 June 2026', $heading['sub']);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function malayDateEdges(): array
    {
        return [
            'sunday january' => ['2026-01-04 08:00', 'Ahad, 4 Januari 2026', 'Sunday, 4 January 2026'],
            'friday december' => ['2026-12-25 08:00', 'Jumaat, 25 Disember 2026', 'Friday, 25 December 2026'],
        ];
    }

    #[DataProvider('malayDateEdges')]
    public function test_malay_day_and_month_names_map_correctly(string $at, string $expectedMs, string $expectedEn): void
    {
        $heading = $this->headingAt($at, 'Aminah');

        $this->assertStringStartsWith($expectedMs, $heading['sub_ms']);
        $this->assertStringStartsWith($expectedEn, $heading['sub']);
    }

    public function test_missing_name_yields_a_clean_greeting_with_no_trailing_comma(): void
    {
        $heading = $this->headingAt('2026-07-07 09:00', null);

        $this->assertSame('Good morning', $heading['title']);
        $this->assertSame('Selamat pagi', $heading['title_ms']);
    }

    public function test_blank_name_does_not_leave_a_dangling_comma(): void
    {
        $heading = $this->headingAt('2026-07-07 09:00', '   ');

        $this->assertSame('Good morning', $heading['title']);
        $this->assertStringNotContainsString(',', $heading['title']);
    }
}
