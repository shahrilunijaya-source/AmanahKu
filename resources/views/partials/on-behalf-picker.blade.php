{{-- HR's "On behalf" picker at the top of an Apply tab. Renders nothing for anyone
     who is not HR ($onBehalfStaff is empty for them). A native <datalist> gives
     type-to-search for free; the typed name is matched back to an id on change, which
     goes into the hidden employee_id the form posts, and the screen region reloads with
     ?for=<id> through partial-nav (it intercepts any same-origin anchor click, so a
     throwaway anchor is the cheapest way in) — the balances, cap and approval chain
     shown below then belong to that person.

     Params: $onBehalfStaff (Collection<Employee>), $applyFor (?Employee), $screen (leave|claims). --}}
@if (($onBehalfStaff ?? collect())->isNotEmpty())
    @php $forOther = $applyFor && $applyFor->user_id !== auth()->id(); @endphp
    <div class="uj-card" style="padding:12px 16px;margin-bottom:14px;display:flex;gap:12px;align-items:center;flex-wrap:wrap;">
        <label for="on-behalf-{{ $screen }}" style="font-size:12px;font-weight:500;color:var(--ink);white-space:nowrap;">
            <span x-text="$store.ui.lang==='en' ? 'On behalf' : 'Bagi pihak'">On behalf</span>
        </label>
        <input id="on-behalf-{{ $screen }}" list="on-behalf-{{ $screen }}-list" autocomplete="off"
               value="{{ $forOther ? $applyFor->display_name : '' }}"
               placeholder="{{ $forOther ? '' : 'Yourself · type a name to file for someone else' }}"
               onchange="const o = [...document.getElementById('on-behalf-{{ $screen }}-list').options].find(o => o.value === this.value); if (!o) { return; } const a = document.createElement('a'); a.href = '{{ route('app.screen', $screen) }}?for=' + o.dataset.id; document.body.appendChild(a); a.click(); a.remove();"
               style="flex:1;min-width:220px;height:36px;padding:0 12px;border:1px solid var(--hairline);border-radius:8px;font-size:13px;outline:none;background:#fff;" />
        <datalist id="on-behalf-{{ $screen }}-list">
            @foreach ($onBehalfStaff as $e)
                <option value="{{ $e->display_name }}" data-id="{{ $e->id }}"></option>
            @endforeach
        </datalist>
        <input type="hidden" name="employee_id" form="apply-{{ $screen }}" value="{{ $applyFor?->id }}" />
        @if ($forOther)
            <a href="{{ route('app.screen', $screen) }}" style="font-size:12px;color:var(--muted);white-space:nowrap;">
                <span x-text="$store.ui.lang==='en' ? 'Back to yourself' : 'Kembali ke diri sendiri'">Back to yourself</span>
            </a>
            <span style="flex-basis:100%;font-size:12px;color:var(--muted);">
                <span x-text="$store.ui.lang==='en' ? 'Submitted in their name, routed through their manager. Recorded as filed by you.' : 'Dihantar atas nama mereka, melalui pengurus mereka. Direkod sebagai difailkan oleh anda.'"></span>
            </span>
        @endif
    </div>
@endif
