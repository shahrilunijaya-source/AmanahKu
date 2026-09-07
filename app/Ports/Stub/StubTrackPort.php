<?php

namespace App\Ports\Stub;

use App\Models\Employee;
use App\Ports\Outbox;
use App\Ports\PortResult;
use App\Ports\TrackPort;

/** Records the intent in port_outbox and reaches no tracker. The only Track driver enabled during the run. */
final class StubTrackPort implements TrackPort
{
    public function __construct(private Outbox $outbox) {}

    public function pullProjects(): PortResult
    {
        return $this->outbox->call('track', 'pullProjects', null, [],
            fn ($row) => ["stub-track-{$row->id}", []]);
    }

    public function pushComment(string $trackRef, string $body, Employee $by): PortResult
    {
        return $this->outbox->call('track', 'pushComment', null,
            ['track_ref' => $trackRef, 'body' => $body, 'by_employee_id' => $by->id],
            fn ($row) => ["stub-track-{$row->id}", []],
            $by->tenant_id);
    }

    public function withdrawComment(string $trackRef, string $commentRef): PortResult
    {
        return $this->outbox->call('track', 'withdrawComment', null,
            ['track_ref' => $trackRef, 'comment_ref' => $commentRef],
            fn ($row) => ["stub-track-{$row->id}", []]);
    }
}
