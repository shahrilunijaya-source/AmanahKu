{{-- Shared add/edit form for a leave type. Expects $type (or null), $allLeaveTypes
     (for the "deducts from" picker), $action, $submitLabel. --}}
@php
    $lt = $type ?? null;
    $lbl = 'display:block;font-size:12px;font-weight:500;color:var(--ink);margin-bottom:5px;';
    $inp = 'width:100%;height:38px;padding:0 12px;border:1px solid var(--hairline);border-radius:8px;font-size:13px;outline:none;';
@endphp
<form method="post" action="{{ $action }}" style="display:flex;flex-direction:column;gap:12px;">
    @csrf
    <div style="display:flex;gap:12px;flex-wrap:wrap;">
        <div style="flex:2;min-width:180px;">
            <label style="{{ $lbl }}"><span x-text="$store.ui.lang==='en' ? 'Name *' : 'Nama *'">Name *</span></label>
            <input name="name" required maxlength="80" value="{{ old('name', $lt->name ?? '') }}" placeholder="Annual" style="{{ $inp }}" />
        </div>
        <div style="width:120px;">
            <label style="{{ $lbl }}"><span x-text="$store.ui.lang==='en' ? 'Days / year' : 'Hari / tahun'">Days / year</span></label>
            <input type="number" step="0.5" min="0" max="9999" name="entitlement" value="{{ old('entitlement', $lt->entitlement ?? 0) + 0 }}" style="{{ $inp }}font-family:var(--font-mono);" />
        </div>
        <div style="width:120px;">
            <label style="{{ $lbl }}"><span x-text="$store.ui.lang==='en' ? 'Notice days' : 'Hari notis'">Notice days</span></label>
            <input type="number" min="0" max="365" name="min_notice_days" value="{{ old('min_notice_days', $lt->min_notice_days ?? 0) }}" style="{{ $inp }}font-family:var(--font-mono);" />
        </div>
    </div>
    <div style="display:flex;gap:12px;flex-wrap:wrap;">
        <div style="width:150px;">
            <label style="{{ $lbl }}"><span x-text="$store.ui.lang==='en' ? 'Monthly accrual' : 'Terakru bulanan'">Monthly accrual</span></label>
            <input type="number" step="0.01" min="0" max="31" name="monthly_accrual_days" value="{{ old('monthly_accrual_days', $lt->monthly_accrual_days ?? 0) + 0 }}" style="{{ $inp }}font-family:var(--font-mono);" />
        </div>
        <div style="width:150px;">
            <label style="{{ $lbl }}"><span x-text="$store.ui.lang==='en' ? 'Max carry forward' : 'Maks bawa hadapan'">Max carry forward</span></label>
            <input type="number" step="0.5" min="0" max="9999" name="max_carry_forward" value="{{ old('max_carry_forward', $lt->max_carry_forward ?? '') }}" placeholder="—" style="{{ $inp }}font-family:var(--font-mono);" />
        </div>
        <div style="flex:1;min-width:180px;">
            <label style="{{ $lbl }}"><span x-text="$store.ui.lang==='en' ? 'Deducts from (optional)' : 'Ditolak dari (pilihan)'">Deducts from (optional)</span></label>
            <select name="deducts_from_leave_type_id" style="{{ $inp }}background:#fff;">
                <option value=""><span>— none —</span></option>
                @foreach ($allLeaveTypes as $o)
                    @if (! $lt || $o->id !== $lt->id)
                        <option value="{{ $o->id }}" @selected(old('deducts_from_leave_type_id', $lt->deducts_from_leave_type_id ?? null) == $o->id)>{{ $o->name }}</option>
                    @endif
                @endforeach
            </select>
        </div>
    </div>
    <div style="display:flex;gap:20px;flex-wrap:wrap;">
        <label style="display:flex;gap:8px;align-items:center;font-size:13px;color:var(--body);cursor:pointer;">
            <input type="checkbox" name="requires_attachment" value="1" @checked(old('requires_attachment', $lt->requires_attachment ?? false)) />
            <span x-text="$store.ui.lang==='en' ? 'Requires attachment (MC / proof)' : 'Perlu lampiran (MC / bukti)'">Requires attachment</span>
        </label>
        <label style="display:flex;gap:8px;align-items:center;font-size:13px;color:var(--body);cursor:pointer;">
            <input type="checkbox" name="is_unplanned" value="1" @checked(old('is_unplanned', $lt->is_unplanned ?? false)) />
            <span x-text="$store.ui.lang==='en' ? 'Unplanned (emergency-style)' : 'Tidak dirancang (kecemasan)'">Unplanned</span>
        </label>
    </div>
    <div><button type="submit" class="uj-btn-primary" style="height:38px;padding:0 16px;font-size:13px;">{{ $submitLabel }}</button></div>
</form>
