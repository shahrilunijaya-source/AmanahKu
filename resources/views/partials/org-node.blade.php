@php
    use App\Support\Amanahku;
    /** @var array{emp: \App\Models\Employee, children: array, count: int} $node */
    $e = $node['emp'];
    $children = $node['children'];
    $count = $node['count'];
    $hasChildren = ! empty($children);
    // Drag editing renders a [data-children] drop zone under every person (even leaves),
    // so anyone can become a manager. Read-only viewers get the lean tree they had before.
    $canEdit = $canEdit ?? false;
@endphp

<div data-node data-emp="{{ $e->id }}" x-data="{ open: true }" style="position:relative;">
    {{-- Node card --}}
    <div data-row style="display:flex;align-items:center;gap:11px;">
        @if ($hasChildren)
            <button type="button" @click="open = ! open" :aria-expanded="open ? 'true' : 'false'" aria-label="Toggle reports" style="width:22px;height:22px;flex-shrink:0;border:1px solid var(--hairline);border-radius:6px;background:#fff;color:var(--muted);font-size:11px;line-height:1;cursor:pointer;display:flex;align-items:center;justify-content:center;">
                <span x-text="open ? '−' : '+'">−</span>
            </button>
        @else
            <span style="width:22px;flex-shrink:0;" aria-hidden="true"></span>
        @endif

        <a href="{{ route('app.screen', ['screen' => 'profile', 'emp' => $e->id]) }}" class="uj-card-clickable" style="display:flex;align-items:center;gap:12px;text-decoration:none;border:1px solid var(--hairline);border-radius:11px;padding:11px 15px;background:#fff;flex:1;min-width:0;">
            {{-- Only render a self-hosted photo (leading "/"). External URLs are blocked by
                 the CSP img-src 'self' policy, so anything off-origin falls back to initials
                 like every other screen. --}}
            @if ($e->photo && str_starts_with($e->photo, '/'))
                <img src="{{ $e->photo }}" alt="" width="38" height="38" style="width:38px;height:38px;border-radius:50%;object-fit:cover;flex-shrink:0;">
            @else
                <div style="width:38px;height:38px;border-radius:50%;background:{{ $e->avatar_color }};color:#fff;display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:600;flex-shrink:0;">{{ $e->initials }}</div>
            @endif
            <div style="min-width:0;flex:1;">
                <div style="font-size:14px;font-weight:600;color:var(--ink);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">{{ $e->name }}</div>
                <div style="font-size:12px;color:var(--muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">{{ collect([$e->position, $e->department?->name])->filter()->implode(' · ') }}</div>
                @if ($e->additionalManagers->isNotEmpty())
                    <div style="font-size:11px;color:var(--muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-top:1px;">
                        <span style="opacity:.7;" x-text="$store.ui.lang==='en' ? 'also reports to: ' : 'juga melapor kepada: '">also reports to: </span>{{ $e->additionalManagers->pluck('name')->implode(', ') }}</div>
                @endif
            </div>
            <span data-count style="font-size:11px;color:var(--muted);background:var(--hairline-soft);padding:2px 9px;border-radius:9999px;flex-shrink:0;{{ $count > 0 ? '' : 'display:none;' }}">{{ $count }} report{{ $count > 1 ? 's' : '' }}</span>
            <span style="width:8px;height:8px;border-radius:50%;background:{{ Amanahku::SWATCH[$e->workload] ?? 'var(--muted-soft)' }};flex-shrink:0;"></span>
        </a>
    </div>

    {{-- Children. In edit mode every node carries a [data-children] drop zone (even when
         empty) so a person can be dragged under anyone. Read-only mode keeps the original
         "render a branch only when there are reports" behaviour. --}}
    @if ($canEdit)
        <div data-children data-parent="{{ $e->id }}" x-show="open" x-cloak
             class="org-children {{ $hasChildren ? 'has-kids' : '' }}"
             style="margin-left:32px;padding-left:18px;display:flex;flex-direction:column;gap:10px;{{ $hasChildren ? 'margin-top:10px;border-left:1.5px solid var(--hairline);' : '' }}">
            @foreach ($children as $child)
                @include('partials.org-node', ['node' => $child, 'canEdit' => true])
            @endforeach
        </div>
    @elseif ($hasChildren)
        <div x-show="open" x-cloak style="margin:10px 0 0 32px;padding-left:18px;border-left:1.5px solid var(--hairline);display:flex;flex-direction:column;gap:10px;">
            @foreach ($children as $child)
                @include('partials.org-node', ['node' => $child])
            @endforeach
        </div>
    @endif
</div>
