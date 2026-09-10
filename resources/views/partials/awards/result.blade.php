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
                @if ($winner->employee?->position)
                    <span style="font-size:12px;color:var(--muted);">{{ $winner->employee->position }}</span>
                @endif
            </div>
        @endforeach
    </div>
    <p style="font-size:12px;color:var(--body);margin:6px 0 0;">{{ $group->label }}</p>
    @if ($group->source === 'adjusted')
        <p style="font-size:12px;color:var(--amber, #a06a00);margin:4px 0 0;">{{ "Result adjusted \u{2013} {$group->reason}" }}</p>
    @elseif ($group->reason)
        <p style="font-size:12px;color:var(--body);margin:4px 0 0;font-style:italic;">&ldquo;{{ $group->reason }}&rdquo;</p>
    @endif
    @if (($canAdjust ?? false) && $attr === 'award')
        {{-- QA S18 F3: Global Clause item 3, the Director's override, from the page itself. --}}
        <details style="margin-top:8px;font-size:12.5px;">
            <summary style="cursor:pointer;color:var(--muted);">Adjust result</summary>
            <form method="post" action="{{ url('/app/awards/'.$group->primaryResultId.'/adjust') }}" style="display:flex;flex-direction:column;gap:8px;max-width:420px;margin-top:8px;">
                @csrf
                @include('partials.person-select', ['name' => 'employee_id', 'people' => $colleagues ?? [], 'required' => true, 'style' => 'height:36px;width:100%;border:1px solid var(--hairline);border-radius:8px;padding:0 10px;font-size:13px;'])
                <input name="reason" required maxlength="2000" placeholder="Reason (required, shown on the page)" style="height:36px;width:100%;border:1px solid var(--hairline);border-radius:8px;padding:0 10px;font-size:13px;" />
                <button type="submit" class="uj-btn-ghost" style="height:34px;font-size:12.5px;">Adjust</button>
            </form>
        </details>
    @endif
    @include('partials.awards.engagement', [
        'resultId' => $group->primaryResultId,
        'reactionCount' => $group->reactionCount,
        'comments' => $group->comments,
        'activeKeys' => \App\Models\Reaction::activeKeys(),
    ])
</div>
