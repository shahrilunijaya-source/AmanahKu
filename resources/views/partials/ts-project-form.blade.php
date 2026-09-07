{{-- Shared add/edit form for a project. Expects $project (or null), $action, $categories,
     $employees (id, display_name — for the PM/PE pickers) and $editableFields (the caller's
     writable master fields; ignored entirely in add mode, where anyone who can reach this
     form may set every field — the lock only starts once a project exists).
     The submit label is derived from $project (add vs. edit) rather than passed in, so
     it renders bilingually the same way for every caller. --}}
@php
    $p = $project ?? null;
    $employees = $employees ?? collect();
    $editable = $editableFields ?? [];
    $selectedCategoryIds = array_map('strval', old('categories', $p ? $p->categories->pluck('id')->all() : []));
    $lbl = 'display:block;font-size:12px;font-weight:500;color:var(--ink);margin-bottom:5px;';
    $inp = 'width:100%;height:38px;padding:0 12px;border:1px solid var(--hairline);border-radius:8px;font-size:13px;outline:none;';
    // A field is locked once the project exists: outside the caller's editable set, or
    // one of the contract terms that only move through a Variation (S10).
    $variationFields = ['contract_value', 'contract_start', 'contract_end', 'client'];
    $locked = fn (string $field) => $p && ($field === 'project_code' || in_array($field, $variationFields, true) || ! in_array($field, $editable, true));
@endphp
<form method="post" action="{{ $action }}" @isset($ajaxTarget) data-ajax data-target="{{ $ajaxTarget }}" @endisset style="display:flex;flex-direction:column;gap:16px;">
    @csrf

    <div>
        <div style="font-size:11px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:.04em;margin-bottom:8px;"><span x-text="$store.ui.lang==='en' ? 'Details' : 'Butiran'">Details</span></div>
        <div style="display:flex;gap:12px;flex-wrap:wrap;">
            <div style="width:150px;">
                <label style="{{ $lbl }}"><span x-text="$store.ui.lang==='en' ? 'Project code' : 'Kod projek'">Project code</span></label>
                <input name="project_code" value="{{ old('project_code', $p->project_code ?? '') }}" placeholder="KPT-RMS-2026-01" @disabled($locked('project_code')) style="{{ $inp }}font-family:var(--font-mono);{{ $locked('project_code') ? 'background:var(--canvas);color:var(--muted);' : '' }}" />
                @if ($locked('project_code'))
                    <p style="font-size:11px;color:var(--muted);margin:4px 0 0;"><span x-text="$store.ui.lang==='en' ? 'Locked once created.' : 'Terkunci sebaik dicipta.'">Locked once created.</span></p>
                @endif
            </div>
            <div style="width:100px;">
                <label style="{{ $lbl }}"><span x-text="$store.ui.lang==='en' ? 'Code' : 'Kod'">Code</span></label>
                <input name="code" value="{{ old('code', $p->code ?? '') }}" placeholder="KPT" style="{{ $inp }}" />
            </div>
            <div style="flex:1;min-width:200px;">
                <label style="{{ $lbl }}"><span x-text="$store.ui.lang==='en' ? 'Project name' : 'Nama projek'">Project name</span></label>
                <input name="name" required value="{{ old('name', $p->name ?? '') }}" placeholder="KPT: RMS" style="{{ $inp }}" />
            </div>
            <div style="width:84px;">
                <label style="{{ $lbl }}"><span x-text="$store.ui.lang==='en' ? 'Order' : 'Susunan'">Order</span></label>
                <input type="number" name="sort" min="0" max="9999" value="{{ old('sort', $p->sort ?? 0) }}" style="{{ $inp }}font-family:var(--font-mono);" />
            </div>
        </div>
        <div style="display:flex;gap:12px;flex-wrap:wrap;margin-top:12px;">
            <div style="flex:1;min-width:180px;">
                <label style="{{ $lbl }}"><span x-text="$store.ui.lang==='en' ? 'Client' : 'Pelanggan'">Client</span></label>
                <input name="client" value="{{ old('client', $p->client ?? '') }}" @disabled($locked('client')) style="{{ $inp }}{{ $locked('client') ? 'background:var(--canvas);color:var(--muted);' : '' }}" />
                @if ($locked('client'))
                    <p style="font-size:11px;color:var(--muted);margin:4px 0 0;"><span x-text="$store.ui.lang==='en' ? 'Changes go through a Variation.' : 'Perubahan melalui Variasi.'">Changes go through a Variation.</span></p>
                @endif
            </div>
            <div style="width:140px;">
                <label style="{{ $lbl }}"><span x-text="$store.ui.lang==='en' ? 'Status' : 'Status'">Status</span></label>
                <select name="status" @disabled($locked('status')) style="{{ $inp }}background:#fff;{{ $locked('status') ? 'background:var(--canvas);color:var(--muted);' : '' }}">
                    @foreach (['planning' => 'Planning', 'active' => 'Active', 'closed' => 'Closed'] as $val => $label)
                        <option value="{{ $val }}" @selected(old('status', $p->status ?? 'active') === $val)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div style="flex:1;min-width:160px;">
                <label style="{{ $lbl }}"><span x-text="$store.ui.lang==='en' ? 'Procurement method' : 'Kaedah perolehan'">Procurement method</span></label>
                <input name="procurement_method" value="{{ old('procurement_method', $p->procurement_method ?? '') }}" placeholder="Open tender" @disabled($locked('procurement_method')) style="{{ $inp }}{{ $locked('procurement_method') ? 'background:var(--canvas);color:var(--muted);' : '' }}" />
            </div>
            <div style="flex:1;min-width:160px;">
                <label style="{{ $lbl }}"><span x-text="$store.ui.lang==='en' ? 'Contractor' : 'Kontraktor'">Contractor</span></label>
                <input name="contractor" value="{{ old('contractor', $p->contractor ?? '') }}" @disabled($locked('contractor')) style="{{ $inp }}{{ $locked('contractor') ? 'background:var(--canvas);color:var(--muted);' : '' }}" />
            </div>
            <div style="flex:2;min-width:220px;">
                <label style="{{ $lbl }}"><span x-text="$store.ui.lang==='en' ? 'Google Drive link' : 'Pautan Google Drive'">Google Drive link</span></label>
                <input type="url" name="drive_link" value="{{ old('drive_link', $p->drive_link ?? '') }}" placeholder="https://drive.google.com/..." @disabled($locked('drive_link')) style="{{ $inp }}{{ $locked('drive_link') ? 'background:var(--canvas);color:var(--muted);' : '' }}" />
            </div>
        </div>
    </div>

    <div>
        <div style="font-size:11px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:.04em;margin-bottom:8px;"><span x-text="$store.ui.lang==='en' ? 'Contract' : 'Kontrak'">Contract</span></div>
        <div style="display:flex;gap:12px;flex-wrap:wrap;">
            <div style="width:160px;">
                <label style="{{ $lbl }}"><span x-text="$store.ui.lang==='en' ? 'Contract value (MYR)' : 'Nilai kontrak (MYR)'">Contract value (MYR)</span></label>
                <input type="number" step="0.01" min="0" name="contract_value" value="{{ old('contract_value', $p->contract_value ?? '') }}" @disabled($locked('contract_value')) style="{{ $inp }}font-family:var(--font-mono);{{ $locked('contract_value') ? 'background:var(--canvas);color:var(--muted);' : '' }}" />
            </div>
            <div style="width:150px;">
                <label style="{{ $lbl }}"><span x-text="$store.ui.lang==='en' ? 'Contract start' : 'Mula kontrak'">Contract start</span></label>
                <input type="date" name="contract_start" value="{{ old('contract_start', optional($p->contract_start ?? null)->format('Y-m-d')) }}" @disabled($locked('contract_start')) style="{{ $inp }}{{ $locked('contract_start') ? 'background:var(--canvas);color:var(--muted);' : '' }}" />
            </div>
            <div style="width:150px;">
                <label style="{{ $lbl }}"><span x-text="$store.ui.lang==='en' ? 'Contract end' : 'Tamat kontrak'">Contract end</span></label>
                <input type="date" name="contract_end" value="{{ old('contract_end', optional($p->contract_end ?? null)->format('Y-m-d')) }}" @disabled($locked('contract_end')) style="{{ $inp }}{{ $locked('contract_end') ? 'background:var(--canvas);color:var(--muted);' : '' }}" />
            </div>
            @if ($p && ($locked('contract_value') || $locked('contract_start') || $locked('contract_end')))
                <div style="flex-basis:100%;font-size:11px;color:var(--muted);">
                    <span x-text="$store.ui.lang==='en' ? 'Contract value and dates go through a Variation, not here (coming in a later update).' : 'Nilai dan tarikh kontrak melalui Variasi, bukan di sini (akan datang).'">Contract value and dates go through a Variation, not here (coming in a later update).</span>
                </div>
            @endif
            <div style="width:150px;">
                <label style="{{ $lbl }}"><span x-text="$store.ui.lang==='en' ? 'Bond value (MYR)' : 'Nilai bon (MYR)'">Bond value (MYR)</span></label>
                <input type="number" step="0.01" min="0" name="bond_value" value="{{ old('bond_value', $p->bond_value ?? '') }}" @disabled($locked('bond_value')) style="{{ $inp }}font-family:var(--font-mono);{{ $locked('bond_value') ? 'background:var(--canvas);color:var(--muted);' : '' }}" />
            </div>
            <div style="width:150px;">
                <label style="{{ $lbl }}"><span x-text="$store.ui.lang==='en' ? 'Bond submitted' : 'Bon dihantar'">Bond submitted</span></label>
                <input type="date" name="bond_submitted_at" value="{{ old('bond_submitted_at', optional($p->bond_submitted_at ?? null)->format('Y-m-d')) }}" @disabled($locked('bond_submitted_at')) style="{{ $inp }}{{ $locked('bond_submitted_at') ? 'background:var(--canvas);color:var(--muted);' : '' }}" />
            </div>
            <div style="width:150px;">
                <label style="{{ $lbl }}"><span x-text="$store.ui.lang==='en' ? 'LOA date' : 'Tarikh LOA'">LOA date</span></label>
                <input type="date" name="loa_date" value="{{ old('loa_date', optional($p->loa_date ?? null)->format('Y-m-d')) }}" @disabled($locked('loa_date')) style="{{ $inp }}{{ $locked('loa_date') ? 'background:var(--canvas);color:var(--muted);' : '' }}" />
            </div>
            <div style="width:150px;">
                <label style="{{ $lbl }}"><span x-text="$store.ui.lang==='en' ? 'LOA reference' : 'Rujukan LOA'">LOA reference</span></label>
                <input name="loa_ref" value="{{ old('loa_ref', $p->loa_ref ?? '') }}" @disabled($locked('loa_ref')) style="{{ $inp }}{{ $locked('loa_ref') ? 'background:var(--canvas);color:var(--muted);' : '' }}" />
            </div>
            <div style="width:150px;">
                <label style="{{ $lbl }}"><span x-text="$store.ui.lang==='en' ? 'Agreement date' : 'Tarikh perjanjian'">Agreement date</span></label>
                <input type="date" name="agreement_date" value="{{ old('agreement_date', optional($p->agreement_date ?? null)->format('Y-m-d')) }}" @disabled($locked('agreement_date')) style="{{ $inp }}{{ $locked('agreement_date') ? 'background:var(--canvas);color:var(--muted);' : '' }}" />
            </div>
            <div style="width:150px;">
                <label style="{{ $lbl }}"><span x-text="$store.ui.lang==='en' ? 'Agreement reference' : 'Rujukan perjanjian'">Agreement reference</span></label>
                <input name="agreement_ref" value="{{ old('agreement_ref', $p->agreement_ref ?? '') }}" @disabled($locked('agreement_ref')) style="{{ $inp }}{{ $locked('agreement_ref') ? 'background:var(--canvas);color:var(--muted);' : '' }}" />
            </div>
        </div>
    </div>

    <div>
        <div style="font-size:11px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:.04em;margin-bottom:8px;"><span x-text="$store.ui.lang==='en' ? 'People' : 'Orang'">People</span></div>
        <div style="display:flex;gap:12px;flex-wrap:wrap;">
            <div style="flex:1;min-width:180px;">
                <label style="{{ $lbl }}"><span x-text="$store.ui.lang==='en' ? 'Assigned PM' : 'PM ditugaskan'">Assigned PM</span></label>
                <select name="pm_id" @disabled($locked('pm_id')) style="{{ $inp }}background:#fff;{{ $locked('pm_id') ? 'background:var(--canvas);color:var(--muted);' : '' }}">
                    <option value="">— none —</option>
                    @foreach ($employees as $e)
                        <option value="{{ $e['id'] }}" @selected((int) old('pm_id', $p->pm_id ?? 0) === $e['id'])>{{ $e['display_name'] }}</option>
                    @endforeach
                </select>
            </div>
            <div style="flex:1;min-width:180px;">
                <label style="{{ $lbl }}"><span x-text="$store.ui.lang==='en' ? 'Assigned PE' : 'PE ditugaskan'">Assigned PE</span></label>
                <select name="pe_id" @disabled($locked('pe_id')) style="{{ $inp }}background:#fff;{{ $locked('pe_id') ? 'background:var(--canvas);color:var(--muted);' : '' }}">
                    <option value="">— none —</option>
                    @foreach ($employees as $e)
                        <option value="{{ $e['id'] }}" @selected((int) old('pe_id', $p->pe_id ?? 0) === $e['id'])>{{ $e['display_name'] }}</option>
                    @endforeach
                </select>
            </div>
        </div>
    </div>

    @if ($categories->isNotEmpty())
        <div>
            <label style="{{ $lbl }}"><span x-text="$store.ui.lang==='en' ? 'Categories' : 'Kategori'">Categories</span></label>
            <div class="uj-cat-chips" role="group" aria-label="Categories" x-data="{ selected: @js($selectedCategoryIds) }">
                @foreach ($categories as $cat)
                    <label class="uj-cat-chip" :class="{ 'is-on': selected.includes('{{ $cat->id }}') }">
                        <input type="checkbox" name="categories[]" value="{{ $cat->id }}" class="uj-sr-only" x-model="selected" />
                        <svg class="uj-cat-chip-tick" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
                        {{ $cat->name }}
                    </label>
                @endforeach
            </div>
        </div>
    @endif

    @if ($p)
        <div>
            <label style="{{ $lbl }}"><span x-text="$store.ui.lang==='en' ? 'Reason (optional)' : 'Sebab (pilihan)'">Reason (optional)</span></label>
            <input name="reason" maxlength="500" placeholder="Why this change" style="{{ $inp }}" />
        </div>
        <label style="display:flex;gap:8px;align-items:center;font-size:13px;color:var(--body);cursor:pointer;">
            <input type="hidden" name="is_active" value="0" />
            <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $p->is_active ?? true)) />
            <span x-text="$store.ui.lang==='en' ? 'Active (shown to staff)' : 'Aktif (dipaparkan kepada staf)'">Active</span>
        </label>
    @endif
    <div>
        <button type="submit" class="uj-btn-primary" style="height:38px;padding:0 16px;font-size:13px;">
            @if ($p)
                <span x-text="$store.ui.lang==='en' ? 'Save changes' : 'Simpan perubahan'">Save changes</span>
            @else
                <span x-text="$store.ui.lang==='en' ? 'Add project' : 'Tambah projek'">Add project</span>
            @endif
        </button>
    </div>
</form>
