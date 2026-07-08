<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Process;

/**
 * Draft a "What's New" changelog entry from git history.
 *
 * Reads the conventional-commit subjects since the last published release,
 * maps them to the changelog's new|improved|fixed groups, strips the dev
 * `type(scope):` prefix, and prints a ready-to-paste PHP block. With --publish
 * it prepends the block to config/changelog.php (still titled "TODO" so a human
 * writes the staff-facing headline before it ships) and clears the config cache.
 *
 * By design this DRAFTS, it does not silently publish raw commit text: commit
 * subjects are internal shorthand, so the wording gets a human pass first. The
 * boundary between "already released" and "new" is the `commit` hash stamped on
 * the newest release; the first run (older entries have none) falls back to that
 * release's date, or an explicit --since.
 */
class ChangelogDraft extends Command
{
    protected $signature = 'changelog:draft
        {--since= : Git ref or date to collect commits after (overrides auto-detection)}
        {--all : Include chore/docs/refactor-style commits too, not just feat/fix/perf}
        {--publish : Prepend the drafted entry to config/changelog.php and clear config cache}';

    protected $description = "Draft a What's New changelog entry from commits since the last release.";

    /** Conventional-commit type → changelog group. Types absent here are dropped unless --all. */
    private const TYPE_GROUP = [
        'feat' => 'new',
        'fix' => 'fixed',
        'perf' => 'improved',
        'refactor' => 'improved',
    ];

    public function handle(): int
    {
        $releases = (array) config('changelog.releases', []);
        $latest = $releases[0] ?? null;

        $boundary = $this->resolveBoundary($latest);
        $subjects = $this->gitSubjects($boundary);

        if ($subjects === null) {
            $this->error('Could not read git history. Is this a git repository?');

            return self::FAILURE;
        }

        $groups = self::classify($subjects, (bool) $this->option('all'));

        if ($groups === []) {
            $this->info('No release-worthy commits since the last entry. Nothing to draft.');

            return self::SUCCESS;
        }

        $entry = [
            'version' => Carbon::now()->format('Y.m.d'),
            'date' => Carbon::now()->format('F j, Y'),
            'commit' => trim($this->gitHead() ?? ''),
            'notes' => $groups,
        ];

        $block = self::renderEntry($entry);

        if (! $this->option('publish')) {
            $this->line($block);
            $this->newLine();
            $this->info('Draft only. Review the wording, then paste above the newest release in config/changelog.php');
            $this->line('  — or re-run with --publish to insert it automatically.');

            return self::SUCCESS;
        }

        if (! $this->publish($block)) {
            return self::FAILURE;
        }

        $this->call('config:clear');
        $this->info('Draft published as version '.$entry['version'].'. Edit the TODO title in config/changelog.php, then restart PM2.');

        return self::SUCCESS;
    }

    // ── Pure, unit-testable helpers ─────────────────────────────────────────

    /**
     * Group cleaned commit subjects under new|improved|fixed. Empty groups are
     * omitted; duplicate lines within a group are collapsed. Non-conventional or
     * excluded-type commits are dropped unless $includeAll routes them to improved.
     *
     * @param  array<int, string>  $subjects
     * @return array<string, array<int, string>>
     */
    public static function classify(array $subjects, bool $includeAll = false): array
    {
        $out = ['new' => [], 'improved' => [], 'fixed' => []];

        foreach ($subjects as $subject) {
            $type = self::commitType($subject);
            $group = self::TYPE_GROUP[$type] ?? null;

            if ($group === null) {
                if (! $includeAll) {
                    continue;
                }
                $group = 'improved';
            }

            $line = self::cleanSubject($subject);
            if ($line !== '' && ! in_array($line, $out[$group], true)) {
                $out[$group][] = $line;
            }
        }

        return array_filter($out, fn ($lines) => $lines !== []);
    }

    /** The conventional-commit type of a subject (e.g. "feat"), or null if none. */
    public static function commitType(string $subject): ?string
    {
        if (preg_match('/^(\w+)(?:\([^)]*\))?!?:\s/', $subject, $m) === 1) {
            return strtolower($m[1]);
        }

        return null;
    }

    /** Strip the `type(scope): ` prefix + trailing issue ref, sentence-case, end with a period. */
    public static function cleanSubject(string $subject): string
    {
        $s = preg_replace('/^\w+(?:\([^)]*\))?!?:\s*/', '', trim($subject)) ?? $subject;
        $s = preg_replace('/\s*\(#\d+\)\s*$/', '', $s) ?? $s;
        $s = trim($s);

        if ($s === '') {
            return '';
        }

        $s = mb_strtoupper(mb_substr($s, 0, 1)).mb_substr($s, 1);

        if (! in_array(mb_substr($s, -1), ['.', '!', '?'], true)) {
            $s .= '.';
        }

        return $s;
    }

    /**
     * Render one release entry as a PHP array block matching config/changelog.php
     * (8-space base indent, single-quoted, apostrophes escaped). Title is a TODO
     * placeholder so a human writes the headline before it ships.
     *
     * @param  array{version:string,date:string,commit:string,notes:array<string,array<int,string>>}  $entry
     */
    public static function renderEntry(array $entry): string
    {
        $q = static fn (string $v): string => "'".str_replace("'", "\\'", $v)."'";
        $pad = str_repeat(' ', 8);

        $lines = [];
        $lines[] = $pad.'[';
        $lines[] = $pad."    'version' => ".$q($entry['version']).',';
        $lines[] = $pad."    'date' => ".$q($entry['date']).',';
        $lines[] = $pad."    'commit' => ".$q($entry['commit']).',';
        $lines[] = $pad."    'title' => 'TODO: write a short staff-facing headline',";
        $lines[] = $pad."    'notes' => [";

        foreach (['new', 'improved', 'fixed'] as $group) {
            if (empty($entry['notes'][$group])) {
                continue;
            }
            $lines[] = $pad."        '{$group}' => [";
            foreach ($entry['notes'][$group] as $note) {
                $lines[] = $pad.'            '.$q($note).',';
            }
            $lines[] = $pad.'        ],';
        }

        $lines[] = $pad.'    ],';
        $lines[] = $pad.'],';

        return implode("\n", $lines);
    }

    // ── Side-effecting internals ────────────────────────────────────────────

    /** Git ref/date to collect commits after: --since, else last release's commit, else its date. */
    private function resolveBoundary(?array $latest): ?string
    {
        if ($since = $this->option('since')) {
            return (string) $since;
        }

        if (! empty($latest['commit'])) {
            return (string) $latest['commit'];
        }

        return $latest['date'] ?? null; // e.g. "June 27, 2026" — git accepts this as --since
    }

    /**
     * Commit subjects since $boundary. A ref that contains ".."/HEAD or resolves as a
     * revision is used as a range; anything else is treated as a --since date.
     *
     * @return array<int, string>|null  null on git failure
     */
    private function gitSubjects(?string $boundary): ?array
    {
        $args = ['git', 'log', '--no-merges', '--pretty=format:%s'];

        if ($boundary !== null && $boundary !== '') {
            if ($this->looksLikeRef($boundary)) {
                $args[] = $boundary.'..HEAD';
            } else {
                $args[] = '--since='.$boundary;
            }
        }

        $result = Process::path(base_path())->run($args);

        if (! $result->successful()) {
            return null;
        }

        return array_values(array_filter(
            array_map('trim', explode("\n", $result->output())),
            fn ($l) => $l !== '',
        ));
    }

    /** True when $boundary is a resolvable git revision (so it can be used as a range base). */
    private function looksLikeRef(string $boundary): bool
    {
        return Process::path(base_path())
            ->run(['git', 'rev-parse', '--verify', '--quiet', $boundary.'^{commit}'])
            ->successful();
    }

    private function gitHead(): ?string
    {
        $result = Process::path(base_path())->run(['git', 'rev-parse', '--short', 'HEAD']);

        return $result->successful() ? $result->output() : null;
    }

    /** Insert the block directly after the `'releases' => [` marker (newest-first). */
    private function publish(string $block): bool
    {
        $path = config_path('changelog.php');
        $contents = @file_get_contents($path);

        if ($contents === false) {
            $this->error('Could not read '.$path);

            return false;
        }

        $marker = "'releases' => [\n";
        $pos = strpos($contents, $marker);

        if ($pos === false) {
            $this->error("Could not find the 'releases' array in config/changelog.php — insert the entry manually.");

            return false;
        }

        $at = $pos + strlen($marker);
        $updated = substr($contents, 0, $at)."\n".$block."\n".substr($contents, $at);

        if (@file_put_contents($path, $updated) === false) {
            $this->error('Could not write '.$path);

            return false;
        }

        return true;
    }
}
