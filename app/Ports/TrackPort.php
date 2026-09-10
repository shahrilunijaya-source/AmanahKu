<?php

namespace App\Ports;

use App\Models\Employee;

/** Frozen by docs/build/contracts/ports.md. Track is the client-side tracker; nothing exists for it yet beyond the stub. */
interface TrackPort
{
    /** `payload` is a list<TrackProject>. */
    public function pullProjects(): PortResult;

    /** `externalId` is Track's id for the comment. */
    public function pushComment(string $trackRef, string $body, Employee $by): PortResult;

    public function withdrawComment(string $trackRef, string $commentRef): PortResult;
}
