<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Attendance\LedgerTotals;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

class AttendanceLedgerTotalsTest extends TestCase
{
    /** @return Collection<int, array<string, mixed>> */
    private function rows(): Collection
    {
        return collect([
            $this->row(1, 'ontime', hours: 8.5),
            $this->row(1, 'late', hours: 8.0),
            $this->row(1, 'miss', hours: null),
            $this->row(2, 'absent', hours: null),
            $this->row(2, 'leave', hours: null, leaveType: 'Annual leave'),
            $this->row(2, 'leave', hours: null, leaveType: 'Medical leave'),
            $this->row(2, 'half', hours: 3.0, flags: ['short']),
        ]);
    }

    /**
     * @param  list<string>  $flags
     * @return array<string, mixed>
     */
    private function row(int $emp, string $status, ?float $hours, array $flags = [], ?string $leaveType = null): array
    {
        return ['employeeId' => $emp, 'status' => $status, 'hours' => $hours,
            'flags' => $flags, 'leaveType' => $leaveType];
    }

    public function test_totals_count_each_status_and_sum_the_hours(): void
    {
        $t = LedgerTotals::of($this->rows());

        // present = anything that is neither absent nor leave: on time, late, miss, half.
        $this->assertSame(4, $t['present']);
        $this->assertSame(1, $t['absent']);
        $this->assertSame(1, $t['late']);
        $this->assertSame(2, $t['leave']);
        $this->assertSame(2, $t['staff'], 'distinct people, not rows');
        $this->assertSame(19.5, $t['hours']);
    }

    public function test_leave_is_broken_down_by_type(): void
    {
        $this->assertSame(
            ['Annual leave' => 1, 'Medical leave' => 1],
            LedgerTotals::of($this->rows())['leaveByType']
        );
    }

    public function test_lens_counts_describe_the_scope(): void
    {
        $this->assertSame(
            ['all' => 7, 'miss' => 1, 'absent' => 1, 'short' => 1, 'late' => 1],
            LedgerTotals::counts($this->rows())
        );
    }

    public function test_a_lens_narrows_the_rows(): void
    {
        $rows = $this->rows();

        $this->assertCount(1, LedgerTotals::applyLens($rows, 'miss'));
        $this->assertCount(1, LedgerTotals::applyLens($rows, 'late'));
        $this->assertCount(1, LedgerTotals::applyLens($rows, 'short'));
        $this->assertCount(7, LedgerTotals::applyLens($rows, null));
        $this->assertCount(7, LedgerTotals::applyLens($rows, 'nonsense'), 'unknown lens shows everything');
    }

    public function test_the_totals_do_not_move_when_a_lens_is_applied(): void
    {
        $rows = $this->rows();
        $before = LedgerTotals::of($rows);

        // The guarantee that matters: totals over scope != totals over the lensed subset.
        $lensed = LedgerTotals::of(LedgerTotals::applyLens($rows, 'miss'));
        $this->assertNotSame(
            $before['present'],
            $lensed['present'],
            'if these ever match, the caller is totalling the wrong set'
        );
    }
}
