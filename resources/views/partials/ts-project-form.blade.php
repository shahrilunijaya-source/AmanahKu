{{-- Shared add/edit form for a project. Expects $project (or null), $action, $submitLabel. --}}
@php $p = $project ?? null; @endphp
<form method="post" action="{{ $action }}" @isset($ajaxTarget) data-ajax data-target="{{ $ajaxTarget }}" @endisset style="display:flex;flex-direction:column;gap:12px;">
    @csrf
    <div style="display:flex;gap:12px;flex-wrap:wrap;">
        <div style="width:120px;">
            <label style="display:block;font-size:12px;font-weight:500;color:var(--ink);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'Code' : 'Kod'">Code</span></label>
            <input name="code" value="{{ old('code', $p->code ?? '') }}" placeholder="KPT" style="width:100%;height:38px;padding:0 12px;border:1px solid var(--hairline);border-radius:8px;font-size:13px;outline:none;" />
        </div>
        <div style="flex:1;min-width:200px;">
            <label style="display:block;font-size:12px;font-weight:500;color:var(--ink);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'Project name' : 'Nama projek'">Project name</span></label>
            <input name="name" required value="{{ old('name', $p->name ?? '') }}" placeholder="KPT: RMS" style="width:100%;height:38px;padding:0 12px;border:1px solid var(--hairline);border-radius:8px;font-size:13px;outline:none;" />
        </div>
        <div style="width:84px;">
            <label style="display:block;font-size:12px;font-weight:500;color:var(--ink);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'Order' : 'Susunan'">Order</span></label>
            <input type="number" name="sort" min="0" max="9999" value="{{ old('sort', $p->sort ?? 0) }}" style="width:100%;height:38px;padding:0 10px;border:1px solid var(--hairline);border-radius:8px;font-size:13px;font-family:var(--font-mono);outline:none;" />
        </div>
    </div>
    @if ($p)
        <label style="display:flex;gap:8px;align-items:center;font-size:13px;color:var(--body);cursor:pointer;">
            <input type="hidden" name="is_active" value="0" />
            <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $p->is_active ?? true)) />
            <span x-text="$store.ui.lang==='en' ? 'Active (shown to staff)' : 'Aktif (dipaparkan kepada staf)'">Active</span>
        </label>
    @endif
    <div><button type="submit" class="uj-btn-primary" style="height:38px;padding:0 16px;font-size:13px;">{{ $submitLabel }}</button></div>
</form>
