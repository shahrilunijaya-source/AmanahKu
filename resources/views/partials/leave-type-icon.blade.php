{{-- One leave type's icon tile. Params: $slug (lowercased type name), $tint (tile
     background), $accent (stroke colour). Stroke SVG rather than emoji: the rest of
     the revamp draws its icons this way, and an emoji set renders differently on every
     OS — not what a government screenshot should be at the mercy of. --}}
@php
    $paths = [
        // Planned time off — a sun.
        'annual' => '<circle cx="12" cy="12" r="4"/><path d="M12 2.5v2M12 19.5v2M2.5 12h2M19.5 12h2M5.2 5.2l1.4 1.4M17.4 17.4l1.4 1.4M18.8 5.2l-1.4 1.4M6.6 17.4l-1.4 1.4"/>',
        // Certified sick leave — a medical cross.
        'medical' => '<path d="M12 6v12M6 12h12"/>',
        // Warded — a hospital bed.
        'hospitalization' => '<path d="M3 7v11M3 18h18M3 13h13a4 4 0 0 1 4 4v1M7.5 13V9.5h4V13"/>',
        // Around childbirth — a heart.
        'maternity' => '<path d="M12 20s-7-4.4-7-9.2A3.9 3.9 0 0 1 12 8a3.9 3.9 0 0 1 7 2.8C19 15.6 12 20 12 20z"/>',
        // Parent and child.
        'paternity' => '<circle cx="9" cy="7.5" r="2.8"/><circle cx="17" cy="11" r="2"/><path d="M4 20a5 5 0 0 1 10 0M14.5 20a2.9 2.9 0 0 1 5.8 0"/>',
        // Time off in lieu — a cycle.
        'replacement' => '<path d="M4.5 10.5A7.5 7.5 0 0 1 17 6.2l2.5 2.3M19.5 13.5A7.5 7.5 0 0 1 7 17.8L4.5 15.5"/><path d="M19.5 4v4.5H15M4.5 20v-4.5H9"/>',
        // Unplanned — a bolt.
        'emergency' => '<path d="M13.5 3 6 13.5h4.5L9.5 21 17.5 10h-4.5z"/>',
        // Bereavement — a leaf.
        'compassionate' => '<path d="M5 19C5 11.3 11.3 5 19 5c0 7.7-6.3 14-14 14z"/><path d="M5.5 18.5c2.8-2.8 5-4.8 8.5-6"/>',
        // Two rings.
        'marriage' => '<circle cx="9.5" cy="14" r="4.6"/><circle cx="15.5" cy="11" r="4.6"/>',
        // Without pay — a pause.
        'unpaid' => '<path d="M9.5 5v14M14.5 5v14"/>',
    ];
    $d = $paths[$slug] ?? '<path d="M7 3.5h6.5L18 8v12.5H7zM13.5 3.5V8H18"/>';
@endphp
<svg viewBox="0 0 24 24" fill="none" stroke="{{ $accent }}" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">{!! $d !!}</svg>
