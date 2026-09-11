<?php

/*
 * Ports (docs/build/contracts/ports.md). Every outside service is reached through an
 * interface in App\Ports; the driver per port picks the adapter. `stub` is the only
 * driver enabled during the build run: it records the call in port_outbox and sends
 * nothing. Real adapters are bound after the run, one per port, by name here.
 */
return [
    'driver' => [
        'calendar' => env('PORT_CALENDAR_DRIVER', 'stub'),
        'track' => env('PORT_TRACK_DRIVER', 'stub'),
        'mail' => env('PORT_MAIL_DRIVER', 'stub'),
    ],
];
