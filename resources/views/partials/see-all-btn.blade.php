{{--
    "See others" — an icon-only button that jumps from a personal screen (my attendance /
    my board / my timesheet) to its company-wide counterpart. Shown only to management, HR
    and immediate superiors (qaCanSeeAll, set app-wide in AppController::quickActions()).

    Params:
      $target   — destination screen id (e.g. 'attendance-report', 'team-board')
      $label    — tooltip / aria-label (EN)
      $labelMs  — tooltip (BM), optional (falls back to $label)
--}}
@once
    <style>
        .see-all-btn{display:inline-flex;align-items:center;justify-content:center;width:38px;height:38px;border-radius:10px;border:1px solid var(--hairline);background:#fff;color:var(--muted);text-decoration:none;transition:background .14s,color .14s,border-color .14s;}
        .see-all-btn:hover{background:var(--canvas);color:var(--red);border-color:var(--red);}
    </style>
@endonce

@if ($qaCanSeeAll ?? false)
    <div style="display:flex;justify-content:flex-end;margin-bottom:14px;">
        <a href="{{ route('app.screen', $target) }}" class="see-all-btn"
           :title="$store.ui.lang==='en' ? @js($label) : @js($labelMs ?? $label)"
           :aria-label="$store.ui.lang==='en' ? @js($label) : @js($labelMs ?? $label)"
           title="{{ $label }}" aria-label="{{ $label }}">
            {{-- group-of-people glyph = "see everyone else" --}}
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">
                <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                <circle cx="9" cy="7" r="4"></circle>
                <path d="M23 21v-2a4 4 0 0 0-3-3.87"></path>
                <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
            </svg>
        </a>
    </div>
@endif
