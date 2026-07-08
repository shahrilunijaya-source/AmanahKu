{{-- Shared add/edit form for a timesheet category. Expects $category (or null), $action, $submitLabel. --}}
@php $c = $category ?? null; @endphp
<form method="post" action="{{ $action }}" style="display:flex;flex-direction:column;gap:12px;">
    @csrf
    <div style="display:flex;gap:12px;flex-wrap:wrap;">
        <div style="flex:1;min-width:160px;">
            <label style="display:block;font-size:12px;font-weight:500;color:var(--ink);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'Name (English)' : 'Nama (Inggeris)'">Name (English)</span></label>
            <input name="name" required value="{{ old('name', $c->name ?? '') }}" style="width:100%;height:38px;padding:0 12px;border:1px solid var(--hairline);border-radius:8px;font-size:13px;outline:none;" />
        </div>
        <div style="flex:1;min-width:160px;">
            <label style="display:block;font-size:12px;font-weight:500;color:var(--ink);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'Name (Bahasa)' : 'Nama (Bahasa)'">Name (Bahasa)</span></label>
            <input name="name_ms" value="{{ old('name_ms', $c->name_ms ?? '') }}" style="width:100%;height:38px;padding:0 12px;border:1px solid var(--hairline);border-radius:8px;font-size:13px;outline:none;" />
        </div>
        <div style="width:84px;">
            <label style="display:block;font-size:12px;font-weight:500;color:var(--ink);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'Order' : 'Susunan'">Order</span></label>
            <input type="number" name="sort" min="0" max="9999" value="{{ old('sort', $c->sort ?? 0) }}" style="width:100%;height:38px;padding:0 10px;border:1px solid var(--hairline);border-radius:8px;font-size:13px;font-family:var(--font-mono);outline:none;" />
        </div>
    </div>
    <label style="display:flex;gap:8px;align-items:center;font-size:13px;color:var(--body);cursor:pointer;">
        <input type="checkbox" name="requires_project" value="1" @checked(old('requires_project', $c->requires_project ?? false)) />
        <span x-text="$store.ui.lang==='en' ? 'Requires a project (like Development or Maintenance)' : 'Memerlukan projek (seperti Pembangunan atau Penyelenggaraan)'">Requires a project</span>
    </label>
    @if ($c)
        <label style="display:flex;gap:8px;align-items:center;font-size:13px;color:var(--body);cursor:pointer;">
            <input type="hidden" name="is_active" value="0" />
            <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $c->is_active ?? true)) />
            <span x-text="$store.ui.lang==='en' ? 'Active (shown to staff)' : 'Aktif (dipaparkan kepada staf)'">Active</span>
        </label>
    @endif
    <div><button type="submit" class="uj-btn-primary" style="height:38px;padding:0 16px;font-size:13px;">{{ $submitLabel }}</button></div>
</form>
