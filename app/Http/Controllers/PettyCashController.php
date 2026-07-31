<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\PettyCashFloat;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PettyCashController extends Controller
{
    private const PRIVILEGED_ROLES = ['management', 'hr'];

    /**
     * Build the petty-cash screen data. Tenant scope is automatic via BelongsToTenant.
     *
     * Floats carry their derived running balance and a recent-transaction tail.
     * Privileged roles (management/HR, who also act as custodians) manage floats
     * and record disbursements/replenishments; everyone else gets a read-only view.
     *
     * @return array<string, mixed>
     */
    public function screenData(Request $request, ?Employee $employee): array
    {
        $privileged = in_array($request->attributes->get('tenantRole', 'employee'), self::PRIVILEGED_ROLES, true);

        // NOTE: a `->take(10)` inside the eager-load closure applies a single global
        // LIMIT across ALL floats' txns (Laravel constrained-eager-load gotcha), so
        // floats past the first would show wrong/empty transactions. Load them ordered,
        // then trim to the 10 most recent PER float in PHP.
        $floats = PettyCashFloat::with([
            'branch',
            'custodian',
            'txns' => fn ($q) => $q->latest('txn_date')->latest('id'),
        ])->latest()->get();

        $floats->each(fn (PettyCashFloat $f) => $f->setRelation('txns', $f->txns->take(10)));

        return [
            'floats' => $floats,
            'branches' => $privileged ? Branch::orderBy('name')->get() : new Collection,
            'privileged' => $privileged,
        ];
    }

    public function storeFloat(Request $request): RedirectResponse
    {
        $this->authorizePrivileged($request);
        $tenantId = app(CurrentTenant::class)->id();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'branch_id' => ['required', 'integer', Rule::exists('branches', 'id')->where('tenant_id', $tenantId)],
            'opening_balance' => ['required', 'numeric', 'min:0', 'max:1000000'],
        ]);

        $float = PettyCashFloat::create([
            'tenant_id' => $tenantId,
            'branch_id' => $data['branch_id'],
            'name' => $data['name'],
            'opening_balance' => $data['opening_balance'],
            'balance' => $data['opening_balance'],
            'custodian_employee_id' => $request->attributes->get('employee')?->id,
            'is_active' => true,
        ]);

        AuditLog::record('Opened petty cash float', $float->name.' · RM '.number_format((float) $float->opening_balance, 2));

        return back()->with('ok', 'Float "'.$float->name.'" opened with RM '.number_format((float) $float->opening_balance, 2).'.');
    }

    public function disburse(Request $request, PettyCashFloat $float): RedirectResponse
    {
        $this->authorizeFloat($request, $float);

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01', 'max:1000000'],
            'payee' => ['required', 'string', 'max:120'],
            'purpose' => ['required', 'string', 'max:255'],
            'txn_date' => ['nullable', 'date'],
        ]);

        // Cash on hand cannot go negative. The balance check and the decrement must
        // happen under a row lock in ONE transaction — otherwise two concurrent
        // disbursements can both pass an out-of-transaction guard and overdraw the
        // float (TOCTOU). Re-read the locked row, check, then write.
        DB::transaction(function () use ($request, $float, $data) {
            $locked = PettyCashFloat::whereKey($float->id)->lockForUpdate()->firstOrFail();

            if ((float) $data['amount'] > (float) $locked->balance) {
                throw ValidationException::withMessages([
                    'amount' => 'Amount exceeds the float balance (RM '.number_format((float) $locked->balance, 2).').',
                ]);
            }

            $locked->txns()->create([
                'tenant_id' => $locked->tenant_id,
                'type' => 'disbursement',
                'amount' => $data['amount'],
                'payee' => $data['payee'],
                'purpose' => $data['purpose'],
                'recorded_by_id' => $request->attributes->get('employee')?->id,
                'txn_date' => $data['txn_date'] ?? now()->toDateString(),
            ]);
            // Stored balance is the source of truth the over-balance guard reads —
            // decrement it directly so cash-on-hand stays exact.
            $locked->decrement('balance', (float) $data['amount']);
        });

        AuditLog::record('Petty cash disbursement', $float->name.' · RM '.number_format((float) $data['amount'], 2).' → '.$data['payee']);

        return back()->with('ok', 'Disbursed RM '.number_format((float) $data['amount'], 2).' from "'.$float->name.'".');
    }

    public function replenish(Request $request, PettyCashFloat $float): RedirectResponse
    {
        $this->authorizeFloat($request, $float);

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01', 'max:1000000'],
            'note' => ['nullable', 'string', 'max:255'],
            'txn_date' => ['nullable', 'date'],
        ]);

        DB::transaction(function () use ($request, $float, $data) {
            $float->txns()->create([
                'tenant_id' => $float->tenant_id,
                'type' => 'replenishment',
                'amount' => $data['amount'],
                'note' => $data['note'] ?? null,
                'recorded_by_id' => $request->attributes->get('employee')?->id,
                'txn_date' => $data['txn_date'] ?? now()->toDateString(),
            ]);
            $float->increment('balance', (float) $data['amount']);
        });

        AuditLog::record('Petty cash replenishment', $float->name.' · RM '.number_format((float) $data['amount'], 2));

        return back()->with('ok', 'Topped up "'.$float->name.'" by RM '.number_format((float) $data['amount'], 2).'.');
    }

    /**
     * Delete a float and, via the petty_cash_txns FK cascade, every transaction
     * recorded against it. Privileged + tenant-scoped only. This is what frees a
     * branch to be deleted (the branch-delete guard blocks while a float references
     * it — see AdminController::destroyBranch).
     */
    public function destroyFloat(Request $request, PettyCashFloat $float): RedirectResponse
    {
        $this->authorizeFloat($request, $float);

        $name = $float->name;
        $float->delete();

        AuditLog::record('Deleted petty cash float', $name);

        return back()->with('ok', 'Float "'.$name.'" and its transactions were deleted.');
    }

    private function authorizePrivileged(Request $request): void
    {
        abort_unless(in_array($request->attributes->get('tenantRole', 'employee'), self::PRIVILEGED_ROLES, true), 403);
    }

    private function authorizeFloat(Request $request, PettyCashFloat $float): void
    {
        $this->authorizePrivileged($request);
        abort_unless($float->tenant_id === app(CurrentTenant::class)->id(), 403);
    }
}
