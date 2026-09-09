{{--
    CR-27: the Mystery Award slide/section. Never an AwardResult (it must never reach the
    Hall of Fame or the rule-9/10 resolver), so this does not reuse partials.awards.result
    — $group is the plain object App\Support\AwardBoard::mysterySlide() builds. Shared
    between the dashboard band (attr=slide) and the Awards screen (attr=award), same as
    the regular result partial. Only ever included once published_at is set, so nothing
    here needs its own "before publish" guard.

    $group  object{award_key:'mystery', employee, employee_id, category, explanation,
                    byDirector, monthLabel}
    $attr   'slide' or 'award'
--}}
<div data-{{ $attr }}="mystery" class="uj-ma-slide uj-ma-reveal">
    <span class="uj-ma-k"><span class="uj-ma-env" aria-hidden="true">&#9993;&#65039;</span>
        <span x-text="$store.ui.lang==='en' ? @js('MYSTERY AWARD · '.strtoupper($group->monthLabel)) : @js('ANUGERAH MISTERI · '.strtoupper($group->monthLabel))">MYSTERY AWARD &middot; {{ strtoupper($group->monthLabel) }}</span>
    </span>
    <div class="uj-ma-cat">{{ $group->category }}</div>
    <p class="uj-ma-sub" x-text="$store.ui.lang==='en' ? 'Nobody knew this category existed until 8:00 this morning.' : 'Tiada siapa tahu kategori ini wujud sehingga 8:00 pagi ini.'">Nobody knew this category existed until 8:00 this morning.</p>
    <div class="uj-ma-who" data-winner="{{ $group->employee_id }}">
        <span class="uj-db-avatar" style="background:{{ $group->employee?->avatar_color ?? '#3a6ea5' }};">{{ $group->employee?->initials ?? '?' }}</span>
        <span class="n">{{ $group->employee?->display_name ?? $group->employee?->name }}</span>
        @if ($group->employee?->position)
            <span class="p">{{ $group->employee->position }}</span>
        @endif
    </div>
    <p class="uj-ma-why">&ldquo;{{ $group->explanation }}&rdquo;</p>
    <p class="uj-ma-by" x-text="$store.ui.lang==='en' ? @js(($group->byDirector ? 'Picked by the Director' : 'Picked by this month\'s mystery committee').' · not a KPI, no streak, no Hall of Fame') : 'Bukan KPI, tiada rentetan, tiada Dewan Kemasyhuran'">{{ $group->byDirector ? 'Picked by the Director' : "Picked by this month's mystery committee" }} &middot; not a KPI, no streak, no Hall of Fame</p>
</div>
