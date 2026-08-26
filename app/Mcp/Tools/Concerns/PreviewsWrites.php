<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Concerns;

use App\Mcp\PendingWrite;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Shared plumbing for every write tool (the preview half and ConfirmWriteTool's
 * apply half): running validation/authorization that may throw the same way the
 * controllers it was extracted from throw (abort_unless -> HttpException,
 * ValidationException::withMessages()), and turning that into an MCP error
 * response instead of an uncaught exception; and stashing an already-validated
 * payload as a confirm token instead of writing anything.
 *
 * Every preview tool using this trait is annotated #[IsReadOnly] — stash() only
 * ever touches the cache, never the tenant's data.
 */
trait PreviewsWrites
{
    /**
     * Run $fn, catching the two exception shapes a controller-derived authorization
     * or validation check throws, and turning either into a plain array the caller
     * can check for an 'error' key. On success, $fn's own return value (expected to
     * be an array) passes straight through.
     */
    private function guarded(callable $fn): array
    {
        try {
            return $fn();
        } catch (ValidationException $e) {
            return ['error' => collect($e->errors())->flatten()->implode(' ')];
        } catch (HttpException $e) {
            return ['error' => $e->getMessage() !== '' ? $e->getMessage() : 'Not authorized.'];
        }
    }

    /**
     * Stash a validated, authorized write and hand back a token instead of
     * applying it. Nothing is written here — the model must show $summary and
     * $changes to the user and get explicit approval before calling confirm_write.
     */
    private function preview(HttpRequest $httpRequest, array $payload, string $summary, array $changes): Response
    {
        $userId = (int) $httpRequest->user()?->getAuthIdentifier();
        $tenantId = (int) app(CurrentTenant::class)->id();

        $token = app(PendingWrite::class)->stash($payload, $this->name(), $userId, $tenantId);

        return Response::json([
            'summary' => $summary,
            'changes' => $changes,
            'confirm_token' => $token,
            'instructions' => 'Nothing has been written yet. Show this summary and the changes to the user and wait for their explicit approval before calling confirm_write with this confirm_token.',
        ]);
    }

    /** '(via AI key claude-code)' — appended to every audit log entry a write tool produces. */
    private function keySuffix(HttpRequest $httpRequest): string
    {
        $tokenName = $httpRequest->user()?->currentAccessToken()->name ?? 'unknown key';

        return ' (via AI key '.$tokenName.')';
    }
}
