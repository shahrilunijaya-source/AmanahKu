{{--
    Flat illustration on the right of a dashboard band, one per kind
    (docs/build/design/banners/index.html). Rendered only when Keep it plain is off.

    $art  string  cake | calendar | trophy | bell | blocks | medals | moon | mailbox
    $num  string|int|null  the number printed on the "blocks" stack (Wrapped's cards closed)
--}}
<div class="uj-db-art" aria-hidden="true">
@switch($art)
    @case('cake')
        <svg viewBox="0 0 220 150">
          <rect x="40" y="86" width="140" height="44" rx="8" fill="#fff"/>
          <rect x="40" y="86" width="140" height="14" rx="7" fill="#ffd6d9"/>
          <rect x="58" y="58" width="104" height="34" rx="8" fill="#fff"/>
          <rect x="58" y="58" width="104" height="12" rx="6" fill="#ffd6d9"/>
          <path d="M40 130h140v6a8 8 0 0 1-8 8H48a8 8 0 0 1-8-8z" fill="#f3c9cc"/>
          <g fill="#ffd166"><circle cx="70" cy="112" r="4"/><circle cx="110" cy="116" r="4"/><circle cx="150" cy="112" r="4"/><circle cx="90" cy="78" r="3.5"/><circle cx="130" cy="78" r="3.5"/></g>
          <g><rect x="84" y="30" width="6" height="30" rx="3" fill="#1f2a5a"/><rect x="107" y="24" width="6" height="36" rx="3" fill="#1f2a5a"/><rect x="130" y="30" width="6" height="30" rx="3" fill="#1f2a5a"/>
          <ellipse cx="87" cy="24" rx="5" ry="8" fill="#ffd166"/><ellipse cx="110" cy="17" rx="5" ry="8" fill="#ffd166"/><ellipse cx="133" cy="24" rx="5" ry="8" fill="#ffd166"/>
          <ellipse cx="87" cy="26" rx="2.2" ry="4" fill="#ff8c42"/><ellipse cx="110" cy="19" rx="2.2" ry="4" fill="#ff8c42"/><ellipse cx="133" cy="26" rx="2.2" ry="4" fill="#ff8c42"/></g>
        </svg>
        @break
    @case('calendar')
        <svg viewBox="0 0 220 170">
          <rect x="64" y="30" width="96" height="110" rx="10" fill="#fff"/>
          <rect x="64" y="30" width="96" height="30" rx="10" fill="#c9424c"/><rect x="64" y="48" width="96" height="12" fill="#c9424c"/>
          <g fill="#1f2a5a"><rect x="86" y="18" width="8" height="22" rx="4"/><rect x="130" y="18" width="8" height="22" rx="4"/></g>
          <text x="112" y="112" text-anchor="middle" font-family="JetBrains Mono, monospace" font-weight="600" font-size="40" fill="#26251e">{{ $day ?? '' }}</text>
          <text x="112" y="130" text-anchor="middle" font-family="Poppins, sans-serif" font-weight="600" font-size="11" fill="#7b8ab2">{{ strtoupper($dow ?? '') }}</text>
          <g fill="#ffd166"><circle cx="176" cy="48" r="5"/><circle cx="186" cy="66" r="3"/><circle cx="46" cy="120" r="4"/></g>
        </svg>
        @break
    @case('trophy')
        <svg viewBox="0 0 220 170">
          <rect x="128" y="18" width="70" height="88" rx="6" fill="#fff"/>
          <g fill="#c4a5d9"><rect x="140" y="32" width="46" height="5" rx="2.5"/><rect x="140" y="44" width="34" height="5" rx="2.5"/><rect x="140" y="56" width="40" height="5" rx="2.5"/></g>
          <path d="M142 88c6-8 10 4 16-4s8 6 14-2 6 4 14-2" stroke="#26251e" stroke-width="2.2" fill="none" stroke-linecap="round"/>
          <path d="M50 30h64v34a32 32 0 0 1-64 0z" fill="#ffd166"/>
          <path d="M50 38H30a16 16 0 0 0 16 24h6M114 38h20a16 16 0 0 1-16 24h-6" stroke="#ffd166" stroke-width="8" fill="none" stroke-linecap="round"/>
          <rect x="72" y="94" width="20" height="18" fill="#e5b84e"/>
          <rect x="54" y="110" width="56" height="12" rx="4" fill="#26251e"/>
          <rect x="44" y="122" width="76" height="14" rx="5" fill="#3d3a33"/>
          <path d="M66 46l16-8 16 8-3 18H69z" fill="#fff" opacity=".5"/>
        </svg>
        @break
    @case('bell')
        <svg viewBox="0 0 220 170">
          <path d="M110 22c-26 0-44 20-44 46v30l-14 20h116l-14-20V68c0-26-18-46-44-46z" fill="#fff"/>
          <path d="M66 98v-30c0-26 18-46 44-46v96H52z" fill="#dff3e8"/>
          <rect x="102" y="12" width="16" height="14" rx="5" fill="#1f2a5a"/>
          <path d="M96 122a14 14 0 0 0 28 0z" fill="#1f2a5a"/>
          <circle cx="110" cy="138" r="6" fill="#ffd166"/>
          <g stroke="#fff" stroke-width="4" stroke-linecap="round" fill="none"><path d="M172 48c8 10 8 24 0 34"/><path d="M186 36c14 18 14 40 0 58"/><path d="M48 48c-8 10-8 24 0 34"/><path d="M34 36c-14 18-14 40 0 58"/></g>
        </svg>
        @break
    @case('blocks')
        <svg viewBox="0 0 220 170">
          <g>
            <path d="M60 112l40-22 40 22-40 22z" fill="#fff"/><path d="M60 112v22l40 22v-22z" fill="#f3c9cc"/><path d="M140 112v22l-40 22v-22z" fill="#e8a4aa"/>
            <path d="M60 80l40-22 40 22-40 22z" fill="#fff"/><path d="M60 80v22l40 22v-22z" fill="#f3c9cc"/><path d="M140 80v22l-40 22v-22z" fill="#e8a4aa"/>
            <path d="M60 48l40-22 40 22-40 22z" fill="#ffd166"/><path d="M60 48v22l40 22v-22z" fill="#e5b84e"/><path d="M140 48v22l-40 22v-22z" fill="#c99a33"/>
          </g>
          <g fill="#1f2a5a"><circle cx="164" cy="128" r="9"/><circle cx="182" cy="118" r="9"/><circle cx="173" cy="140" r="9"/></g>
          <text x="100" y="52" text-anchor="middle" font-family="JetBrains Mono, monospace" font-weight="600" font-size="15" fill="#26251e">{{ $num ?? '' }}</text>
        </svg>
        @break
    @case('medals')
        <svg viewBox="0 0 220 170">
          <g><path d="M60 16l14 40h-28z" fill="#c9424c"/><path d="M60 16l14 40H60z" fill="#a3161d"/><circle cx="60" cy="74" r="24" fill="#ffd166"/><circle cx="60" cy="74" r="16" fill="#e5b84e"/><text x="60" y="80" text-anchor="middle" font-family="Poppins, sans-serif" font-weight="700" font-size="16" fill="#26251e">1</text></g>
          <g><path d="M110 40l14 40H96z" fill="#3a6ea5"/><path d="M110 40l14 40h-14z" fill="#2b537c"/><circle cx="110" cy="98" r="24" fill="#fff"/><circle cx="110" cy="98" r="16" fill="#e6e5e0"/><text x="110" y="104" text-anchor="middle" font-family="Poppins, sans-serif" font-weight="700" font-size="16" fill="#26251e">2</text></g>
          <g><path d="M160 40l14 40h-28z" fill="#4f9d78"/><path d="M160 40l14 40h-14z" fill="#37785a"/><circle cx="160" cy="98" r="24" fill="#f3c9cc"/><circle cx="160" cy="98" r="16" fill="#e8a4aa"/><text x="160" y="104" text-anchor="middle" font-family="Poppins, sans-serif" font-weight="700" font-size="16" fill="#26251e">?</text></g>
        </svg>
        @break
    @case('moon')
        <svg viewBox="0 0 220 170">
          <path d="M120 22a54 54 0 1 0 54 70 44 44 0 0 1-54-70z" fill="#fff"/>
          <g fill="#ffd166"><circle cx="56" cy="40" r="4"/><circle cx="40" cy="92" r="3"/><circle cx="180" cy="34" r="3"/><path d="M70 120l3 8 8 3-8 3-3 8-3-8-8-3 8-3z"/></g>
          <rect x="132" y="118" width="60" height="36" rx="6" fill="#fff"/>
          <rect x="132" y="118" width="60" height="10" rx="5" fill="#a3aecb"/>
          <g fill="#7b8ab2"><rect x="142" y="134" width="40" height="4" rx="2"/><rect x="142" y="142" width="26" height="4" rx="2"/></g>
        </svg>
        @break
    @case('mailbox')
        <svg viewBox="0 0 220 170">
          <rect x="104" y="96" width="14" height="60" rx="4" fill="#3d3a33"/>
          <path d="M52 60a30 30 0 0 1 30-30h72a30 30 0 0 1 30 30v42H52z" fill="#1f2a5a"/>
          <path d="M52 60a30 30 0 0 1 30-30h8v72H52z" fill="#2b537c"/>
          <rect x="96" y="56" width="70" height="40" rx="5" fill="#fff"/>
          <g fill="#c9c6bd"><rect x="106" y="66" width="34" height="4" rx="2"/><rect x="106" y="76" width="24" height="4" rx="2"/></g>
          <rect x="140" y="62" width="16" height="12" rx="2" fill="#3a6ea5"/>
          <rect x="176" y="18" width="6" height="44" rx="3" fill="#c9424c"/><rect x="176" y="18" width="24" height="14" rx="3" fill="#c9424c"/>
        </svg>
        @break
@endswitch
</div>
