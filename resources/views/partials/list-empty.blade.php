{{--
    Shared "nothing here yet" block for in-card lists (achievements, pending requests,
    onboarding, announcements, team status, people-pulse tabs, etc). One title line plus
    one explanatory line, both bilingual via the standard Alpine ternary.

    Usage:
        @include('partials.list-empty', [
            'en' => ['title' => '...', 'body' => '...'],
            'ms' => ['title' => '...', 'body' => '...'],
            'pad' => '28px 20px',  // optional, defaults to '14px 0'
            'center' => false,     // optional, defaults to true
        ])
--}}
@php
    $pad = $pad ?? '14px 0';
    $center = $center ?? true;
@endphp
{{-- @js, not raw {{ }}: the strings land inside an Alpine expression, so an apostrophe
     in the copy ("It's empty") would otherwise close the JS string and break the whole
     ternary. @js JSON-encodes and attribute-escapes in one step. --}}
<div style="padding:{{ $pad }};{{ $center ? 'text-align:center;' : '' }}">
    <div style="font-size:13px;color:var(--ink);font-weight:500;margin-bottom:2px;"><span x-text="$store.ui.lang==='en' ? @js($en['title']) : @js($ms['title'])">{{ $en['title'] }}</span></div>
    <div style="font-size:12px;color:var(--muted);line-height:1.5;"><span x-text="$store.ui.lang==='en' ? @js($en['body']) : @js($ms['body'])">{{ $en['body'] }}</span></div>
</div>
