{{-- HR's "filing for" picker at the top of an Apply tab. Renders nothing for anyone
     who is not HR ($onBehalfStaff is empty for them). Changing the person reloads the
     screen region with ?for=<id> through partial-nav (it intercepts any same-origin
     anchor click, so a throwaway anchor is the cheapest way in) — the balances, cap
     and approval chain shown below then belong to that person. The chosen id is also
     posted as employee_id so the request is created FOR them.

     Params: $onBehalfStaff (Collection<Employee>), $applyFor (?Employee), $screen (leave|claims). --}}
@if (($onBehalfStaff ?? collect())->isNotEmpty())
    <div class="uj-card" style="padding:12px 16px;margin-bottom:14px;display:flex;gap:12px;align-items:center;flex-wrap:wrap;">
        <label for="on-behalf-{{ $screen }}" style="font-size:12px;font-weight:500;color:var(--ink);white-space:nowrap;">
            <span x-text="$store.ui.lang==='en' ? 'Filing for' : 'Memohon untuk'">Filing for</span>
        </label>
        <select id="on-behalf-{{ $screen }}" name="employee_id" form="apply-{{ $screen }}"
                onchange="const a = document.createElement('a'); a.href = '{{ route('app.screen', $screen) }}?for=' + this.value; document.body.appendChild(a); a.click(); a.remove();"
                style="flex:1;min-width:220px;height:36px;padding:0 12px;border:1px solid var(--hairline);border-radius:8px;font-size:13px;outline:none;background:#fff;">
            @foreach ($onBehalfStaff as $e)
                <option value="{{ $e->id }}" @selected(($applyFor?->id) === $e->id)>{{ $e->display_name }}{{ $e->user_id === auth()->id() ? ' (you)' : '' }}</option>
            @endforeach
        </select>
        @if ($applyFor && $applyFor->user_id !== auth()->id())
            <span style="font-size:12px;color:var(--muted);">
                <span x-text="$store.ui.lang==='en' ? 'Submitted in their name, routed through their manager. Recorded as filed by you.' : 'Dihantar atas nama mereka, melalui pengurus mereka. Direkod sebagai difailkan oleh anda.'"></span>
            </span>
        @endif
    </div>
@endif
