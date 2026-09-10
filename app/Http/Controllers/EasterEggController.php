<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\EasterEgg;
use App\Support\EasterEggBank;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * CR-31 dashboard/board easter-egg bank: HR curates approved lines on the
 * Company Settings screen, same shape as CR-33's GreetingLineController.
 * Route-model binding is not tenant-scoped, so every write re-checks
 * tenant_id itself before touching a bound EasterEgg.
 */
class EasterEggController extends Controller
{
    private const ADMIN_ROLES = ['management', 'hr'];

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeTenantRole($request, self::ADMIN_ROLES);

        $data = $this->validated($request);
        $tenant = app(CurrentTenant::class);

        EasterEgg::create([
            'tenant_id' => $tenant->id(),
            'kind' => $data['kind'],
            'text_en' => $data['text_en'],
            'text_ms' => $data['text_ms'],
            'approved_at' => now(),
        ]);

        AuditLog::record('Added easter egg', $data['text_en']);

        return back()->with('ok', 'Easter egg added.');
    }

    /** Also handles approving a line (approve=1). */
    public function update(Request $request, EasterEgg $easterEgg): RedirectResponse
    {
        $this->authorizeTenantRole($request, self::ADMIN_ROLES);
        $this->assertTenant($easterEgg->tenant_id);

        if ($request->boolean('approve')) {
            $easterEgg->update(['approved_at' => now()]);
            AuditLog::record('Approved easter egg', $easterEgg->text_en);

            return back()->with('ok', 'Easter egg approved.');
        }

        $data = $this->validated($request);

        $easterEgg->update([
            'kind' => $data['kind'],
            'text_en' => $data['text_en'],
            'text_ms' => $data['text_ms'],
        ]);

        AuditLog::record('Updated easter egg', $easterEgg->text_en);

        return back()->with('ok', 'Easter egg updated.');
    }

    public function delete(Request $request, EasterEgg $easterEgg): RedirectResponse
    {
        $this->authorizeTenantRole($request, self::ADMIN_ROLES);
        $this->assertTenant($easterEgg->tenant_id);

        $text = $easterEgg->text_en;
        $easterEgg->delete();

        AuditLog::record('Deleted easter egg', $text);

        return back()->with('ok', 'Easter egg deleted.');
    }

    /**
     * @return array{kind: string, text_en: string, text_ms: string}
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'kind' => ['required', 'string', Rule::in(EasterEggBank::KINDS)],
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
