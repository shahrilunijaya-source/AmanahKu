<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Models\CompanyCategory;
use App\Models\CompanyInvite;
use App\Support\ApiCaller;
use App\Support\AuditContext;
use App\Support\Permissions;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

/**
 * Single-step write: a director or HR mints a self-serve company signup link,
 * the same row SuperAdmin\CompanyInviteController::store() creates. No
 * preview/confirm step on purpose — the link is one-use, expires in 7 days
 * and changes nothing in the caller's own tenant, so there is nothing for the
 * user to approve beyond asking for it. Requires invites:write.
 *
 * Deliberately NOT #[IsReadOnly]: it writes.
 */
#[Name('create_company_invite')]
#[Description('Generate a self-serve signup link that lets someone register a brand-new company on AmanahKu. One call, no confirm step: returns the link straight away. The link works once and expires in 7 days. Only a director or HR may call it, with a personal AI key carrying invites:write.')]
class CreateCompanyInviteTool extends Tool
{
    public function handle(Request $request): Response
    {
        $httpRequest = request();

        if (! ApiCaller::can($httpRequest, 'invites:write')) {
            return Response::error('This token lacks the invites:write scope.');
        }

        // The invite records who created it, and a machine key has no person behind it.
        if ($httpRequest->attributes->get('apiClient') !== null) {
            return Response::error('Only a person\'s AI key can generate a company invite link.');
        }

        $role = Permissions::effectiveRole($httpRequest->attributes->get('tenantRole', 'employee'));
        if (! in_array($role, ['management', 'hr'], true)) {
            return Response::error('Only a director or HR can generate a company invite link.');
        }

        $data = $request->validate([
            'stage' => ['required', 'integer'],
            'note' => ['nullable', 'string', 'max:160'],
        ]);

        $category = CompanyCategory::where('level', $data['stage'])->first();
        if ($category === null) {
            $known = CompanyCategory::orderBy('level')->pluck('name', 'level')->map(fn ($n, $l) => "$l = $n")->implode(', ');

            return Response::error("Unknown stage {$data['stage']}. Known stages: {$known}.");
        }

        AuditContext::source('mcp');
        try {
            $invite = CompanyInvite::create([
                'token' => Str::random(40),
                'note' => $data['note'] ?? null,
                'company_category_id' => $category->id,
                'expires_at' => now()->addDays(7),
                'created_by_user_id' => $httpRequest->user()->getAuthIdentifier(),
            ]);
        } finally {
            AuditContext::reset();
        }

        return Response::json([
            'url' => $invite->url(),
            'expires_at' => $invite->expires_at->toIso8601String(),
            'note' => $invite->note,
            'company_category' => $category->name,
            'instructions' => 'Share this link with the person. It works once and expires in 7 days.',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'stage' => $schema->integer()->description('Feature package the new company starts on: 1 = Basic HR, 2 = HR Operations.')->required(),
            'note' => $schema->string()->description('Who this link is for (e.g. the company name). Shown to super-admins, max 160 chars.'),
        ];
    }
}
