{{--
    CR-14b: one badge per award key this person has ever won, plus a hall-of-fame
    star once a single award reaches 3 wins. Shown on both the slim public card
    and the full profile (test_acceptance_5) — the caller passes $awardBadges
    regardless of canViewFull, so this partial never checks that gate itself.

    $awardBadges  Collection<array{key:string,wins:int,hallOfFame:bool}>
--}}
@if (($awardBadges ?? collect())->isNotEmpty())
    <div style="margin-top:8px;display:flex;flex-wrap:wrap;gap:6px;justify-content:center;">
        @foreach ($awardBadges as $badge)
            @php $copy = \App\Support\AwardCatalog::copy($badge['key']); @endphp
            <span data-award-badge="{{ $badge['key'] }}"
                  title="{{ $copy['en']['name'] }}"
                  style="display:inline-flex;align-items:center;gap:4px;font-size:11px;font-weight:600;color:var(--ink);background:var(--canvas);border:1px solid var(--hairline);padding:3px 9px;border-radius:9999px;">
                🏅 <span x-text="$store.ui.lang==='en' ? @js($copy['en']['name']) : @js($copy['ms']['name'])">{{ $copy['en']['name'] }}</span>
                @if ($badge['wins'] > 1)<span style="color:var(--muted);">×{{ $badge['wins'] }}</span>@endif
            </span>
            @if ($badge['hallOfFame'])
                <span data-hall-of-fame="{{ $badge['key'] }}"
                      title="Hall of Fame"
                      style="display:inline-flex;align-items:center;gap:4px;font-size:11px;font-weight:600;color:#8a6300;background:#fff6db;border:1px solid #f0d98c;padding:3px 9px;border-radius:9999px;">
                    ⭐ <span x-text="$store.ui.lang==='en' ? 'Hall of Fame' : 'Dewan Kemasyhuran'">Hall of Fame</span>
                </span>
            @endif
        @endforeach
    </div>
@endif
