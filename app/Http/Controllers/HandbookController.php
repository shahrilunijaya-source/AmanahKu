<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\HandbookSection;
use App\Models\PolicyAcknowledgement;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class HandbookController extends Controller
{
    /** Current employee acknowledges a policy section at its current version. */
    public function acknowledge(Request $request, HandbookSection $section): RedirectResponse
    {
        $employee = $request->attributes->get('employee');
        abort_unless($employee, 403, 'No employee profile in this workspace.');

        // Route-model binding runs in SubstituteBindings (base web group) BEFORE the
        // tenant middleware sets CurrentTenant, so the BelongsToTenant scope is inert at
        // bind time and any tenant's section id resolves. Assert ownership explicitly —
        // otherwise this is a cross-tenant IDOR (leaks the section title/version + writes
        // a cross-tenant acknowledgement row). Mirrors every sibling write controller.
        abort_unless($section->tenant_id === app(CurrentTenant::class)->id(), 403);

        PolicyAcknowledgement::updateOrCreate(
            ['employee_id' => $employee->id, 'handbook_section_id' => $section->id],
            ['version' => $section->version, 'acknowledged_at' => now()],
        );

        AuditLog::record('Acknowledged policy', $section->title.' v'.$section->version);

        return back()->with('ok', 'Acknowledged: '.$section->title.'.');
    }
}
