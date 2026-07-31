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

        return $reply.' (Connect an Anthropic API key to enable conversational AI answers.)';
    }

    public function label(): string
    {
        return 'Rule-based · live data';
    }
}
