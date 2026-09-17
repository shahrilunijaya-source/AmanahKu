{{-- Tab strip shared by the payroll screens. $tabs: id => [EN, MS]. Needs Alpine `tab` in scope. --}}
<div style="display:flex;gap:4px;margin-bottom:16px;border-bottom:1px solid var(--hairline);flex-wrap:wrap;">
    @foreach ($tabs as $id => [$en, $ms])
        <button type="button" @click="tab = @js($id)" :style="tab === @js($id) ? { color:'var(--red)', borderBottom:'2px solid var(--red)' } : { color:'var(--muted)', borderBottom:'2px solid transparent' }" style="background:none;padding:9px 14px;font-size:13px;font-weight:500;cursor:pointer;margin-bottom:-1px;" x-text="$store.ui.lang==='en' ? @js($en) : @js($ms)">{{ $en }}</button>
    @endforeach
</div>
