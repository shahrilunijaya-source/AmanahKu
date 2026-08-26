<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\View\View as ViewContract;

/**
 * The in-app guide at /docs/mcp: how to connect Claude Code (on a staff member's own
 * computer) to AmanahKu, written for someone who has never opened a terminal.
 *
 * Authenticated (unlike /docs/api, which is deliberately public because it documents
 * request/response shapes, not data). This page is different: it walks a specific
 * person through generating their own AI access key and explains what that key can
 * see, which is their own account's data. There is nothing on the page that is safe
 * to hand to a stranger, so it sits behind login like every other staff-facing screen.
 */
class McpDocsController extends Controller
{
    public function show(): ViewContract
    {
        return view('docs.mcp');
    }
}
