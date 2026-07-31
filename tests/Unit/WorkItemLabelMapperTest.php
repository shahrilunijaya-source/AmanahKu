<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\WorkItemLabelMapper;
use PHPUnit\Framework\TestCase;

class WorkItemLabelMapperTest extends TestCase
{
    public function test_urgent_alone_raises_priority_to_high_and_is_removed(): void
    {
        $result = WorkItemLabelMapper::map(['urgent'], 'low');

        $this->assertSame([], $result['labels']);
        $this->assertSame('high', $result['priority']);
    }

    public function test_urgent_on_a_card_already_at_high_leaves_priority_untouched(): void
    {
        $result = WorkItemLabelMapper::map(['urgent'], 'high');

        $this->assertSame([], $result['labels']);
        $this->assertSame('high', $result['priority']);
    }

    public function test_waiting_on_a_card_that_already_has_blocked_does_not_duplicate(): void
    {
        $result = WorkItemLabelMapper::map(['blocked', 'waiting'], 'medium');

        $this->assertSame(['blocked'], $result['labels']);
        $this->assertSame('medium', $result['priority']);
    }

    public function test_review_alone_is_removed_with_no_replacement(): void
    {
        $result = WorkItemLabelMapper::map(['review'], 'medium');

        $this->assertSame([], $result['labels']);
        $this->assertSame('medium', $result['priority']);
    }

    public function test_a_card_carrying_all_three_merges_and_removes_correctly(): void
    {
        $result = WorkItemLabelMapper::map(['urgent', 'waiting', 'review'], null);

        $this->assertSame(['blocked'], $result['labels']);
        $this->assertSame('high', $result['priority']);
    }

    public function test_a_card_with_no_labels_does_not_crash(): void
    {
        $result = WorkItemLabelMapper::map([], 'low');

        $this->assertSame([], $result['labels']);
        $this->assertSame('low', $result['priority']);
    }

    public function test_null_labels_are_treated_the_same_as_an_empty_array(): void
    {
        $result = WorkItemLabelMapper::map(null, 'high');

        $this->assertSame([], $result['labels']);
        $this->assertSame('high', $result['priority']);
    }

    public function test_surviving_label_order_is_preserved_not_resorted(): void
    {
        $result = WorkItemLabelMapper::map(['client', 'waiting', 'internal'], 'low');

        $this->assertSame(['client', 'blocked', 'internal'], $result['labels']);
    }

    public function test_an_already_high_priority_card_with_no_urgent_label_is_untouched(): void
    {
        $result = WorkItemLabelMapper::map(['client'], 'high');

        $this->assertSame(['client'], $result['labels']);
        $this->assertSame('high', $result['priority']);
    }
}
