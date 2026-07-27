<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\AttachmentName;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

class AttachmentNameTest extends TestCase
{
    public function test_builds_the_expected_readable_name(): void
    {
        $name = AttachmentName::build('Nur Ain', 'leave', 12, 'abc123.pdf', Carbon::parse('2026-07-27'));

        $this->assertSame('nur-ain-leave-12-2026-07-27.pdf', $name);
    }

    public function test_apostrophes_are_stripped_by_slugging(): void
    {
        $name = AttachmentName::build("Nur'ain", 'leave', 12, 'abc123.pdf', Carbon::parse('2026-07-27'));

        $this->assertStringNotContainsString("'", $name);
        $this->assertSame('nurain-leave-12-2026-07-27.pdf', $name);
    }

    public function test_missing_employee_name_falls_back_to_staff(): void
    {
        $name = AttachmentName::build(null, 'claim', 5, 'abc123.pdf', Carbon::parse('2026-07-19'));

        $this->assertSame('staff-claim-5-2026-07-19.pdf', $name);
    }

    public function test_empty_employee_name_falls_back_to_staff(): void
    {
        $name = AttachmentName::build('', 'claim', 5, 'abc123.pdf', Carbon::parse('2026-07-19'));

        $this->assertSame('staff-claim-5-2026-07-19.pdf', $name);
    }

    public function test_stored_path_with_no_extension_omits_the_trailing_dot(): void
    {
        $name = AttachmentName::build('Azman', 'claim', 5, 'abc123', Carbon::parse('2026-07-19'));

        $this->assertSame('azman-claim-5-2026-07-19', $name);
    }

    public function test_null_date_omits_the_segment_without_a_double_dash(): void
    {
        $name = AttachmentName::build('Azman', 'claim', 5, 'abc123.pdf', null);

        $this->assertSame('azman-claim-5.pdf', $name);
    }

    public function test_extension_comes_from_the_stored_path_not_the_original_name(): void
    {
        // The stored path's extension is what disk storage actually gave the file;
        // the original client filename must never leak in here.
        $name = AttachmentName::build('Azman', 'leave', 12, 'E18Z8MdpRI11y3IWc6TP8FQnEl31UTgS0hu3sErF.pdf', Carbon::parse('2026-07-27'));

        $this->assertSame('azman-leave-12-2026-07-27.pdf', $name);
    }
}
