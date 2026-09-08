{{--
    CR-14b: one award's result — shared between the dashboard carousel (one per
    data-slide) and the Awards screen's "This month's winners" / "Past winners" rows
    (one per data-award). A tie shares this one block, every winner listed. Both callers
    pass a group object from App\Support\AwardBoard::slidesForMonth().

    $group  object{award_key, winners: Collection<AwardResult>, copy, primaryResultId,
                    label, reason, source, reactionCount, comments}
    $attr   'slide' or 'award' — which data-* marker this block carries
--}}
@php $copy = $group->copy; @endphp
<div data-{{ $attr }}="{{ $group->award_key }}" class="uj-award-result" style="padding:14px 0;border-bottom:1px solid var(--hairline);">
    <div style="font-size:11px;font-weight:600;color:var(--accent, #3a6ea5);text-transform:uppercase;letter-spacing:.03em;"
         x-text="$store.ui.lang==='en' ? @js($copy['en']['name']) : @js($copy['ms']['name'])">{{ $copy['en']['name'] }}</div>
    <div style="font-size:12.5px;color:var(--muted);margin-top:2px;"
         x-text="$store.ui.lang==='en' ? @js($copy['en']['sub']) : @js($copy['ms']['sub'])">{{ $copy['en']['sub'] }}</div>
    <div style="display:flex;flex-direction:column;gap:4px;margin-top:8px;">
        @foreach ($group->winners as $winner)
            <div data-winner="{{ $winner->employee_id }}" style="display:flex;align-items:center;gap:8px;">
                <span style="width:30px;height:30px;border-radius:50%;background:{{ $winner->employee?->avatar_color ?? '#3a6ea5' }};color:#fff;font-size:12px;font-weight:600;display:flex;align-items:center;justify-content:center;flex-shrink:0;">{{ $winner->employee?->initials ?? '?' }}</span>
                <span style="font-size:13.5px;font-weight:600;color:var(--ink);">{{ $winner->employee?->display_name ?? $winner->employee?->name }}</span>
            </div>
        @endforeach
    </div>
    <p style="font-size:12px;color:var(--body);margin:6px 0 0;">{{ $group->label }}</p>
    @if ($group->source === 'adjusted')
        <p style="font-size:12px;color:var(--amber, #a06a00);margin:4px 0 0;">{{ "Result adjusted \u{2013} {$group->reason}" }}</p>
    @elseif ($group->reason)
        <p style="font-size:12px;color:var(--body);margin:4px 0 0;font-style:italic;">&ldquo;{{ $group->reason }}&rdquo;</p>
    @endif
    @include('partials.awards.engagement', [
        'resultId' => $group->primaryResultId,
        'reactionCount' => $group->reactionCount,
        'comments' => $group->comments,
        'activeKeys' => \App\Models\Reaction::activeKeys(),
    ])
</div>
