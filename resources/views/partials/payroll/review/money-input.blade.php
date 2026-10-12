{{-- One editable money cell on the payslip review. Blank = use the calculated figure (shown as the placeholder); a typed value is HR's override. $key is the Alpine state key on the payslip's `f`; $name is the form field (null = a mirror of a field named elsewhere on the same panel). --}}
<span class="inline-flex items-center justify-end gap-1.5">
    {{-- Read-only figure until Overwrite switches the panel to edit mode. --}}
    <span x-show="mode !== 'edit'" class="amt inline-flex items-center justify-end gap-1.5 whitespace-nowrap" style="font-family:var(--font-mono);font-size:13px;color:var(--ink);min-width:7rem;padding-right:0.5rem;" :style="{ color: f.{{ $key }} !== '' ? 'var(--info)' : '' }">{{-- The hand-typed marker sits inside the fixed-width amount box, so it never pushes the MYR column sideways. --}}<span x-show="f.{{ $key }} !== ''" x-cloak class="inline-flex" role="img" :aria-label="$store.ui.lang==='en' ? 'Typed by hand' : 'Ditaip sendiri'" :title="$store.ui.lang==='en' ? 'Typed by hand. Clear it to go back to the calculated figure.' : 'Ditaip sendiri. Kosongkan untuk kembali kepada angka yang dikira.'">@include('partials.payroll.review.icon', ['name' => 'pencil', 'size' => 12])</span>{{ number_format((float) $effective, 2) }}</span>
    <input type="number" x-show="mode === 'edit'" x-cloak :disabled="mode !== 'edit'" step="0.01" min="0" inputmode="decimal"
        @if ($name) name="{{ $name }}" @endif
        x-model="f.{{ $key }}"
        placeholder="{{ number_format((float) $effective, 2, '.', '') }}"
        :aria-label="$store.ui.lang==='en' ? @js($en) : @js($ms)"
        :class="f.{{ $key }} !== '' ? 'border-[var(--info)] bg-[#f3f8fd] font-semibold' : 'border-[var(--hairline)]'"
        class="h-8 w-28 rounded-[7px] border px-2 text-right font-mono text-[13px] text-[var(--ink)] placeholder:text-[var(--ink)] focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-[var(--info)] disabled:bg-[var(--canvas)]">
    @if ($editable && ($resettable ?? true))
        <button type="button" x-show="mode === 'edit' && f.{{ $key }} !== ''" x-cloak @click="f.{{ $key }} = ''" class="grid h-6 w-6 place-items-center rounded-full text-[var(--muted)] hover:text-[var(--error)] focus-visible:outline-2 focus-visible:outline-[var(--info)]" :aria-label="$store.ui.lang==='en' ? 'Reset to calculated' : 'Set semula kepada dikira'" :title="$store.ui.lang==='en' ? 'Reset to calculated' : 'Set semula kepada dikira'">&times;</button>
    @endif
</span>
