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

    /*
     * Palette assigned when generating an avatar colour for a new account.
     *
     * Every entry carries white initials, so every entry must clear WCAG AA for
     * small text (4.5:1) against #fff. The green, amber and teal used to sit at
     * 4.30, 3.16 and 4.11, so each was scaled toward black on the same RGB ratio
     * (hue untouched) until it cleared, landing at ~4.74. Do not swap one of
     * these back for a UI token such as `--success` (#1f8a65): those tokens are
     * tuned for fills and icons, not for text on top of them.
     */
    'avatar_palette' => ['#3a6ea5', '#1d825f', '#d6232b', '#996a28', '#7a5bb0', '#287f7f'],
];
