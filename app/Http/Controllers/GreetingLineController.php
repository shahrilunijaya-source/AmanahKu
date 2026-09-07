<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\GreetingLine;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * CR-33 dashboard greeting bank: HR curates approved lines on the Company
 * Settings screen; any employee can suggest one for HR to approve. Route-model
 * binding is not tenant-scoped, so every write re-checks tenant_id itself
 * before touching a bound GreetingLine (see AdminController's assertTenant).
 */
class GreetingLineController extends Controller
{
    private const ADMIN_ROLES = ['management', 'hr'];

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeTenantRole($request, self::ADMIN_ROLES);

        $data = $this->validated($request);
        $tenant = app(CurrentTenant::class);

        GreetingLine::create([
            'tenant_id' => $tenant->id(),
            'bucket' => GreetingLine::TRIGGERS[$data['trigger']]['bucket'],
            'trigger' => $data['trigger'],
            'text_en' => $data['text_en'],
            'text_ms' => $data['text_ms'],
            'approved_at' => now(),
        ]);

        AuditLog::record('Added greeting line', $data['text_en']);

        return back()->with('ok', 'Greeting line added.');
    }

    /** Also handles approving a pending suggestion (approve=1). */
    public function update(Request $request, GreetingLine $greetingLine): RedirectResponse
    {
        $this->authorizeTenantRole($request, self::ADMIN_ROLES);
        $this->assertTenant($greetingLine->tenant_id);

        if ($request->boolean('approve')) {
            $greetingLine->update(['approved_at' => now()]);
            AuditLog::record('Approved greeting line', $greetingLine->text_en);

            return back()->with('ok', 'Greeting line approved.');
        }

        $data = $this->validated($request);

        $greetingLine->update([
            'bucket' => GreetingLine::TRIGGERS[$data['trigger']]['bucket'],
            'trigger' => $data['trigger'],
            'text_en' => $data['text_en'],
            'text_ms' => $data['text_ms'],
        ]);

        AuditLog::record('Updated greeting line', $greetingLine->text_en);

        return back()->with('ok', 'Greeting line updated.');
    }

    public function delete(Request $request, GreetingLine $greetingLine): RedirectResponse
    {
        $this->authorizeTenantRole($request, self::ADMIN_ROLES);
        $this->assertTenant($greetingLine->tenant_id);

        $text = $greetingLine->text_en;
        $greetingLine->delete();

        AuditLog::record('Deleted greeting line', $text);

        return back()->with('ok', 'Greeting line deleted.');
    }

    /** Open to any signed-in employee — creates a pending row for HR to approve. */
    public function suggest(Request $request): RedirectResponse
    {
        $employee = $request->attributes->get('employee');
        abort_unless($employee, 403, 'No employee profile in this workspace.');

        $data = $this->validated($request);

        GreetingLine::create([
            'tenant_id' => $employee->tenant_id,
            'bucket' => GreetingLine::TRIGGERS[$data['trigger']]['bucket'],
            'trigger' => $data['trigger'],
            'text_en' => $data['text_en'],
            'text_ms' => $data['text_ms'],
            'suggested_by' => $employee->id,
            'approved_at' => null,
        ]);

        return back()->with('ok', 'Thanks, HR will look at it.');
    }

    /**
     * @return array{trigger: string, text_en: string, text_ms: string}
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'trigger' => ['required', 'string', Rule::in(array_keys(GreetingLine::TRIGGERS))],
            'text_en' => ['required', 'string', 'max:200'],
            'text_ms' => ['required', 'string', 'max:200'],
        ]);
    }

    /** Block any write against a row owned by a different tenant. */
    private function assertTenant(int $tenantId): void
    {
        abort_unless($tenantId === app(CurrentTenant::class)->id(), 403);
    }
}
