<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ApiClient;
use App\Support\ApiReference;
use Illuminate\View\View as ViewContract;

/**
 * The public API reference at /docs/api.
 *
 * Unauthenticated by design. Everything it renders comes from ApiReference, so the
 * page and the block its copy button produces are generated from one array and cannot
 * disagree with each other or with routes/api.php.
 */
class ApiDocsController extends Controller
{
    public function show(): ViewContract
    {
        return view('docs.api', [
            'endpoints' => ApiReference::ENDPOINTS,
            'scopes' => ApiClient::SCOPES,
            'brief' => ApiReference::agentBrief(),
            'baseUrl' => rtrim((string) config('app.url'), '/').ApiReference::BASE_PATH,
        ]);
    }
}
