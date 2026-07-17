{{-- Shared add/edit form for a project sub-pillar. Expects $sub (or null), $action, $submitLabel, $compact. --}}
@php $s = $sub ?? null; @endphp
<form method="post" action="{{ $action }}" @isset($ajaxTarget) data-ajax data-target="{{ $ajaxTarget }}" @endisset style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap;">
    @csrf
    <div style="flex:1;min-width:160px;">
        @unless ($compact ?? false)
            <label style="display:block;font-size:12px;font-weight:500;color:var(--ink);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'Sub-pillar name' : 'Nama sub-tiang'">Sub-pillar name</span></label>
        @endunless
        <input name="name" required value="{{ old('name', $s->name ?? '') }}" :placeholder="$store.ui.lang==='en' ? 'e.g. Frontend' : 'cth. Frontend'" style="width:100%;height:34px;padding:0 11px;border:1px solid var(--hairline);border-radius:7px;font-size:12.5px;outline:none;" />
    </div>
    <div style="width:72px;">
        @unless ($compact ?? false)
            <label style="display:block;font-size:12px;font-weight:500;color:var(--ink);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'Order' : 'Susunan'">Order</span></label>
        @endunless
        <input type="number" name="sort" min="0" max="9999" value="{{ old('sort', $s->sort ?? 0) }}" style="width:100%;height:34px;padding:0 8px;border:1px solid var(--hairline);border-radius:7px;font-size:12.5px;font-family:var(--font-mono);outline:none;" />
    </div>
    @if ($s)
        <label style="display:flex;gap:6px;align-items:center;font-size:12.5px;color:var(--body);cursor:pointer;height:34px;">
            <input type="hidden" name="is_active" value="0" />
            <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $s->is_active ?? true)) />
            <span x-text="$store.ui.lang==='en' ? 'Active' : 'Aktif'">Active</span>
        </label>
    @endif
    <button type="submit" class="uj-btn-ghost" style="height:34px;padding:0 12px;font-size:12.5px;">{{ $submitLabel }}</button>
</form>
