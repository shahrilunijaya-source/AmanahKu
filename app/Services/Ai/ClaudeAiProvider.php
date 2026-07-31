<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Live provider backed by Anthropic's Messages API. Active only when
 * AMANAHKU_AI_DRIVER=claude and ANTHROPIC_API_KEY is set. Any failure
 * (network, auth, rate limit) degrades gracefully to the canned fallback.
 */
class ClaudeAiProvider implements AiProvider
{
    public function __construct(
        private string $apiKey,
        private string $model,
        private AiProvider $fallback,
    ) {}

    public function reply(string $message, array $context): string
    {
        try {
            $response = Http::withHeaders([
                'x-api-key' => $this->apiKey,
                'anthropic-version' => '2023-06-01',
                'content-type' => 'application/json',
            ])->timeout(20)->post('https://api.anthropic.com/v1/messages', [
                'model' => $this->model,
                'max_tokens' => 600,
                'system' => $this->systemPrompt($context),
                'messages' => [
                    ['role' => 'user', 'content' => $message],
                ],
            ]);

            if ($response->failed()) {
                Log::warning('Amanahku AI: Claude request failed', ['status' => $response->status()]);

                return $this->fallback->reply($message, $context);
            }

            $text = $response->json('content.0.text');

            return is_string($text) && $text !== '' ? $text : $this->fallback->reply($message, $context);
        } catch (\Throwable $e) {
            Log::warning('Amanahku AI: Claude error — '.$e->getMessage());

            return $this->fallback->reply($message, $context);
        }
    }

    public function label(): string
    {
        return 'Live AI · Claude';
    }

    /** @param array<string,mixed> $context */
    private function systemPrompt(array $context): string
    {
        return implode(' ', [
            "You are Amanahku's workforce assistant for {$context['tenant']}, a Malaysian SME HR and work-tracking platform.",
            'Answer concisely and practically for a manager or HR lead.',
            'Use ONLY the workforce facts in the JSON below — never invent employee names, numbers, or records.',
            'If a question cannot be answered from these facts, say so and suggest where in the app to look.',
            'Workforce facts: '.json_encode($context),
        ]);
    }
}
