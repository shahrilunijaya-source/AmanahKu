@php
    $money = fn ($v) => 'RM '.number_format((float) $v, 2);
@endphp
<div x-data="{ editItem: null }">
    <div class="uj-card" style="max-width:900px;">
        <div class="uj-card-head" style="padding:16px 22px;">
            <h3 class="uj-card-title" x-text="$store.ui.lang==='en' ? 'Payroll items' : 'Katalog item gaji'">Payroll items</h3>
        </div>
        <div style="padding:14px 22px;border-bottom:1px solid var(--hairline-soft);">
            @include('partials.hint', [
                'en' => 'Every amount on a payslip comes from one of these named items. The EPF / SOCSO+EIS / taxable flags decide what an item does to statutory contributions — a company can genuinely treat an allowance differently, so flags are editable on any item. System items (seeded defaults) cannot be deleted, but their flags and names can still be changed.',
                'ms' => 'Setiap jumlah pada payslip datang daripada salah satu item bernama ini. Penanda EPF / SOCSO+EIS / boleh cukai menentukan kesan item itu terhadap caruman berkanun — sesebuah syarikat mungkin benar-benar melayan sesuatu elaun secara berbeza, jadi penanda boleh disunting pada mana-mana item. Item sistem (lalai yang disediakan) tidak boleh dipadam, tetapi nama dan penandanya masih boleh diubah.',
            ])
        </div>
        @forelse ($payrollItems as $item)
            <div style="border-bottom:1px solid var(--hairline-soft);">
                <div style="display:flex;align-items:center;gap:12px;padding:12px 22px;">
                    <div style="flex:1;min-width:0;">
                        <div style="font-size:13px;color:var(--ink);font-weight:500;">
                            {{ $item->name }}
                            @if ($item->is_system)<span class="uj-pill" style="background:var(--canvas);color:var(--muted);font-size:9.5px;margin-left:6px;" x-text="$store.ui.lang==='en' ? 'System' : 'Sistem'">System</span>@endif
                            @if (! $item->active)<span class="uj-pill" style="background:var(--red-tint);color:var(--muted);font-size:9.5px;margin-left:6px;" x-text="$store.ui.lang==='en' ? 'Inactive' : 'Tidak aktif'">Inactive</span>@endif
                        </div>
                        <div style="font-size:11px;color:var(--muted);text-transform:capitalize;">{{ $item->type }} · {{ $item->code }}</div>
                    </div>
                    <div style="display:flex;gap:6px;flex-wrap:wrap;">
                        <span class="uj-pill" style="background:{{ $item->epf_liable ? 'var(--red-tint)' : 'var(--canvas)' }};color:{{ $item->epf_liable ? 'var(--success)' : 'var(--muted)' }};font-size:10.5px;">EPF {{ $item->epf_liable ? '✓' : '—' }}</span>
                        <span class="uj-pill" style="background:{{ $item->perkeso_liable ? 'var(--red-tint)' : 'var(--canvas)' }};color:{{ $item->perkeso_liable ? 'var(--success)' : 'var(--muted)' }};font-size:10.5px;">SOCSO/EIS {{ $item->perkeso_liable ? '✓' : '—' }}</span>
                        <span class="uj-pill" style="background:{{ $item->pcb_taxable ? 'var(--red-tint)' : 'var(--canvas)' }};color:{{ $item->pcb_taxable ? 'var(--success)' : 'var(--muted)' }};font-size:10.5px;" x-text="($store.ui.lang==='en' ? 'PCB ' : 'PCB ') + ('{{ $item->pcb_taxable ? '✓' : '—' }}')">PCB {{ $item->pcb_taxable ? '✓' : '—' }}</span>
                    </div>
                    <button @click="editItem === {{ $item->id }} ? editItem = null : editItem = {{ $item->id }}" class="uj-btn-ghost" style="height:32px;padding:0 12px;font-size:12px;" x-text="$store.ui.lang==='en' ? 'Edit' : 'Sunting'">Edit</button>
                </div>
                <div x-show="editItem === {{ $item->id }}" x-cloak style="padding:4px 22px 18px 22px;">
                    <form method="post" action="{{ route('payroll.items.update', $item) }}" style="background:var(--canvas);border:1px solid var(--hairline);border-radius:10px;padding:16px;">
                        @csrf
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">
                            <div><label style="display:block;font-size:11.5px;color:var(--muted);margin-bottom:4px;" x-text="$store.ui.lang==='en' ? 'Name (EN)' : 'Nama (EN)'">Name (EN)</label><input name="name" value="{{ $item->name }}" required style="width:100%;height:36px;padding:0 10px;border:1px solid var(--hairline);border-radius:7px;font-size:13px;outline:none;" /></div>
                            <div><label style="display:block;font-size:11.5px;color:var(--muted);margin-bottom:4px;">Nama (BM)</label><input name="name_ms" value="{{ $item->name_ms }}" style="width:100%;height:36px;padding:0 10px;border:1px solid var(--hairline);border-radius:7px;font-size:13px;outline:none;" /></div>
                        </div>
                        <div style="display:flex;flex-wrap:wrap;gap:14px;margin-bottom:12px;">
                            <label style="display:flex;align-items:center;gap:7px;font-size:12px;color:var(--ink);cursor:pointer;"><input type="checkbox" name="epf_liable" value="1" @checked($item->epf_liable) style="width:15px;height:15px;" /><span>EPF liable</span></label>
                            <label style="display:flex;align-items:center;gap:7px;font-size:12px;color:var(--ink);cursor:pointer;"><input type="checkbox" name="perkeso_liable" value="1" @checked($item->perkeso_liable) style="width:15px;height:15px;" /><span>SOCSO/EIS liable</span></label>
                            <label style="display:flex;align-items:center;gap:7px;font-size:12px;color:var(--ink);cursor:pointer;"><input type="checkbox" name="prorate_on_incomplete_month" value="1" @checked($item->prorate_on_incomplete_month) style="width:15px;height:15px;" /><span x-text="$store.ui.lang==='en' ? 'Prorate on incomplete month' : 'Prorata bulan tidak penuh'">Prorate on incomplete month</span></label>
                            <label style="display:flex;align-items:center;gap:7px;font-size:12px;color:var(--ink);cursor:pointer;"><input type="checkbox" name="hrdf_liable" value="1" @checked($item->hrdf_liable) style="width:15px;height:15px;" /><span x-text="$store.ui.lang==='en' ? 'HRD Corp levy liable' : 'Dikenakan levi HRD Corp'">HRD Corp levy liable</span></label>
                            <label style="display:flex;align-items:center;gap:7px;font-size:12px;color:var(--ink);cursor:pointer;"><input type="checkbox" name="pcb_taxable" value="1" @checked($item->pcb_taxable) style="width:15px;height:15px;" /><span x-text="$store.ui.lang==='en' ? 'Taxable (PCB)' : 'Boleh cukai (PCB)'">Taxable (PCB)</span></label>
                            <label style="display:flex;align-items:center;gap:7px;font-size:12px;color:var(--ink);cursor:pointer;"><input type="checkbox" name="active" value="1" @checked($item->active) style="width:15px;height:15px;" /><span x-text="$store.ui.lang==='en' ? 'Active' : 'Aktif'">Active</span></label>
                        </div>
                        <div style="display:flex;gap:8px;align-items:center;">
                            <button type="submit" class="uj-btn-primary" style="height:36px;padding:0 16px;font-size:12.5px;" x-text="$store.ui.lang==='en' ? 'Save' : 'Simpan'">Save</button>
                            <button type="button" @click="editItem = null" class="uj-btn-ghost" style="height:36px;padding:0 14px;font-size:12.5px;" x-text="$store.ui.lang==='en' ? 'Cancel' : 'Batal'">Cancel</button>
                            @unless ($item->is_system)
                                <span style="flex:1;"></span>
                                <button type="submit" formaction="{{ route('payroll.items.delete', $item) }}" onclick="return confirm(window.Alpine && Alpine.store('ui').lang==='ms' ? 'Padam item ini?' : 'Delete this item?');" class="uj-btn-ghost" style="height:36px;padding:0 14px;font-size:12.5px;color:var(--error);" x-text="$store.ui.lang==='en' ? 'Delete' : 'Padam'">Delete</button>
                            @endunless
                        </div>
                    </form>
                </div>
            </div>
        @empty
            <div style="padding:28px 20px;text-align:center;color:var(--muted);">
                <div style="font-size:13px;"><span x-text="$store.ui.lang==='en' ? 'No payroll items yet — they seed automatically the first time this tenant is set up.' : 'Belum ada item payroll — ia disediakan secara automatik apabila tenant ini disediakan.'"></span></div>
            </div>
        @endforelse
    </div>
</div>
