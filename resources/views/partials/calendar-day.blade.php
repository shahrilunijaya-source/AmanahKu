@php
    /**
     * One day cell. Expects $cell = ['date','inMonth','isToday','leave','holiday','events','birthday'].
     * $maxItems caps visible chips before a "+N more" roll-up.
     */
    $maxItems = $maxItems ?? 3;
    $items = collect();
    foreach ($cell['holiday'] as $h) {
        $items->push(['kind' => 'holiday', 'label' => $h->name, 'color' => 'var(--amber)', 'tint' => '#fbf3e6']);
    }
    foreach ($cell['events'] as $e) {
        $items->push(['kind' => 'event', 'label' => $e->title, 'color' => '#3a6ea5', 'tint' => '#eaf1f9']);
    }
    foreach (($cell['birthday'] ?? collect()) as $b) {
        $items->push(['kind' => 'birthday', 'label' => '🎂 '.($b->name ?? 'Birthday'), 'color' => '#c026d3', 'tint' => '#faeffb']);
    }
    foreach ($cell['leave'] as $l) {
        $items->push(['kind' => 'leave', 'label' => $l->employee?->name ?? 'Employee', 'color' => 'var(--success)', 'tint' => '#e9f5ee']);
    }
    $visible = $items->take($maxItems);
    $overflow = $items->count() - $visible->count();
    $bg = $cell['isToday'] ? '#fffaf0' : ($cell['inMonth'] ? '#fff' : 'var(--surface-soft, #fafafa)');
    $dayColor = $cell['inMonth'] ? 'var(--ink)' : 'var(--muted-soft)';
@endphp
<div style="min-height:108px;padding:7px 8px;background:{{ $bg }};border:1px solid var(--hairline-soft);{{ $cell['isToday'] ? 'box-shadow:inset 0 0 0 2px var(--amber);' : '' }}border-radius:8px;display:flex;flex-direction:column;gap:4px;overflow:hidden;">
    <div style="display:flex;align-items:center;justify-content:space-between;">
        <span style="font-size:12px;font-weight:{{ $cell['isToday'] ? '700' : '500' }};color:{{ $cell['isToday'] ? 'var(--amber)' : $dayColor }};font-family:var(--font-mono);">{{ $cell['date']->format('j') }}</span>
        @if ($items->isEmpty())
            <span aria-hidden="true">&nbsp;</span>
        @endif
    </div>
    @foreach ($visible as $it)
        <div title="{{ $it['label'] }}" style="display:flex;align-items:center;gap:5px;font-size:11px;line-height:1.3;color:var(--ink);background:{{ $it['tint'] }};border-radius:5px;padding:2px 6px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
            <span style="width:6px;height:6px;border-radius:50%;background:{{ $it['color'] }};flex-shrink:0;"></span>
            <span style="overflow:hidden;text-overflow:ellipsis;">{{ $it['label'] }}</span>
        </div>
    @endforeach
    @if ($overflow > 0)
        <div style="font-size:10.5px;color:var(--muted);padding:0 6px;">+{{ $overflow }} more</div>
    @endif
</div>
