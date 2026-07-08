<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Commands\ChangelogDraft;
use Tests\TestCase;

/**
 * The git-reading side is thin; the value is in classification + copy cleanup,
 * which are pure and tested here without touching a repository.
 */
class ChangelogDraftTest extends TestCase
{
    private const SUBJECTS = [
        'feat(offboarding): acknowledging a resignation auto-opens a clearance case',
        'fix: KPI card lingered on the profile after disabling Performance',
        'perf(features): memoise tenant feature lookups',
        'refactor(nav): simplify the module gate',
        'chore: bump dependencies',
        'docs: update the README',
        'feat: overtime is its own toggle', // distinct feat
    ];

    public function test_classify_maps_types_to_groups_and_drops_noise(): void
    {
        $groups = ChangelogDraft::classify(self::SUBJECTS);

        $this->assertSame(
            ['Acknowledging a resignation auto-opens a clearance case.', 'Overtime is its own toggle.'],
            $groups['new'],
        );
        $this->assertSame(['KPI card lingered on the profile after disabling Performance.'], $groups['fixed']);
        $this->assertSame(
            ['Memoise tenant feature lookups.', 'Simplify the module gate.'],
            $groups['improved'],
        );
        // chore + docs are excluded by default; the three real groups stay in order.
        $this->assertSame(['new', 'improved', 'fixed'], array_keys($groups));
    }

    public function test_all_flag_routes_unknown_types_to_improved(): void
    {
        $groups = ChangelogDraft::classify(['chore: tidy config'], includeAll: true);

        $this->assertSame(['Tidy config.'], $groups['improved']);
    }

    public function test_empty_groups_are_omitted(): void
    {
        $groups = ChangelogDraft::classify(['fix: only a fix here']);

        $this->assertSame(['fixed'], array_keys($groups));
    }

    public function test_clean_subject_strips_prefix_issue_ref_and_punctuates(): void
    {
        $this->assertSame('Add the thing.', ChangelogDraft::cleanSubject('feat(scope): add the thing'));
        $this->assertSame('A breaking change.', ChangelogDraft::cleanSubject('feat!: a breaking change'));
        $this->assertSame('Fix the bug.', ChangelogDraft::cleanSubject('fix: fix the bug (#123)'));
        $this->assertSame('Already punctuated!', ChangelogDraft::cleanSubject('feat: already punctuated!'));
        $this->assertSame('No prefix line.', ChangelogDraft::cleanSubject('no prefix line'));
    }

    public function test_commit_type_parses_conventional_prefix(): void
    {
        $this->assertSame('feat', ChangelogDraft::commitType('feat(x): y'));
        $this->assertSame('fix', ChangelogDraft::commitType('fix!: y'));
        $this->assertNull(ChangelogDraft::commitType('just a message'));
    }

    public function test_render_entry_produces_pasteable_php_block(): void
    {
        $block = ChangelogDraft::renderEntry([
            'version' => '2026.07.08',
            'date' => 'July 8, 2026',
            'commit' => 'abc1234',
            'notes' => ['new' => ["Staff's new toy."], 'fixed' => ['A fix.']],
        ]);

        $this->assertStringContainsString("'version' => '2026.07.08',", $block);
        $this->assertStringContainsString("'commit' => 'abc1234',", $block);
        $this->assertStringContainsString("'title' => 'TODO: write a short staff-facing headline',", $block);
        $this->assertStringContainsString("'new' => [", $block);
        $this->assertStringContainsString("'fixed' => [", $block);
        // Apostrophe is escaped so the block is valid PHP.
        $this->assertStringContainsString("'Staff\\'s new toy.',", $block);
        // Omitted group never appears.
        $this->assertStringNotContainsString("'improved' => [", $block);
    }
}
