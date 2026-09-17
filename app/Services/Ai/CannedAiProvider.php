<?php

namespace App\Services\Ai;

/**
 * Default provider — no external calls. Summarises the live workforce facts in a
 * deterministic, useful reply. Active whenever no Anthropic key is configured.
 */
class CannedAiProvider implements AiProvider
{
    public function reply(string $message, array $context): string
    {
        $hint = ' (Connect an Anthropic API key to enable conversational AI answers.)';

        // Company-wide facts are only in $context for viewers workforceContext()
        // allows to see them (Permissions::canSeeAll). Everyone else gets a reply
        // built from just their own tasks, never a missing-key error.
        if (! isset($context['headcount'])) {
            $you = $context['you'] ?? null;
            $reply = $you
                ? "You have {$you['openTasks']} open task(s) in {$context['tenant']}."
                : "I don't have any figures to share for {$context['tenant']}.";

            return $reply.' Company figures are only shown to managers and HR.'.$hint;
        }

        $overloaded = $context['overloaded'] ?? [];

        $parts = [];
        $parts[] = "I can see {$context['headcount']} employees in {$context['tenant']}";
        $parts[] = $overloaded
            ? count($overloaded).' overloaded ('.implode(', ', $overloaded).')'
            : 'no one currently overloaded';
        $parts[] = "{$context['pendingLeave']} leave and {$context['pendingClaims']} claim approval(s) pending";

        $reply = ucfirst(implode('; ', $parts)).'.';

        if ($you = ($context['you'] ?? null)) {
            $reply .= " You have {$you['openTasks']} open task(s).";
        }

        return $reply.$hint;
    }

    public function label(): string
    {
        return 'Rule-based · live data';
    }
}
