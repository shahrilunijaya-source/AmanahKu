<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Brand & avatar colours
    |--------------------------------------------------------------------------
    |
    | Single source for the colour literals that were previously hardcoded in
    | several controllers (AK-CODE-04). Blade views keep their own inline
    | `?? '#3a6ea5'` fallbacks for defence-in-depth, and migration column
    | defaults stay static (a migration must be reproducible), but every place
    | that programmatically *chooses* a default now reads from here.
    |
    */

    // Default avatar background for a newly provisioned employee with no colour yet.
    'avatar_color' => '#3a6ea5',

    // Default brand / accent colour for a tenant that has not set its own.
    'brand_color' => '#d6232b',

    // Palette assigned when generating an avatar colour for a new account.
    'avatar_palette' => ['#3a6ea5', '#1f8a65', '#d6232b', '#c08532', '#7a5bb0', '#2b8a8a'],
];
