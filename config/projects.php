<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Project Master
|--------------------------------------------------------------------------
|
| project_code is the unique, immutable integration key CR-06 needs Track to
| link against (e.g. KPT-RMS-2026-01). Finance has not confirmed the exact
| format yet (docs/specs/CR-06.md notes: "Confirm project-code format with
| Finance"), so the pattern below is a placeholder — env-overridable so it
| can be tightened later without another migration.
|
*/

return [
    'code_pattern' => env('PROJECT_CODE_PATTERN', '/^[A-Z0-9]+(-[A-Z0-9]+)*$/'),
];
