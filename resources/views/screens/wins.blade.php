@extends('layouts.app')

@php
    $deals = $deals ?? collect();
@endphp

@section('screen')
@include('partials.guide', [
    'key' => 'wins',
    'en'  => [
        'title' => 'Wins',
        'body'  => 'Every Big Deal Alert ever raised — project go-lives, tenders won, client compliments and more. Newest first, this is an archive, not a window.',
    ],
    'ms'  => [
        'title' => 'Kejayaan',
        'body'  => 'Setiap Big Deal Alert yang pernah diajukan — projek go-live, tender dimenangi, pujian klien dan banyak lagi. Terkini dahulu, ini arkib, bukan tetingkap.',
    ],
])

<div style="display:flex;flex-direction:column;gap:14px;">
    @forelse ($deals as $row)
        @php
            $deal = $row['deal'];
            $lines = preg_split('/\r?\n/', trim((string) $deal->story)) ?: [];
            $oneLiner = array_shift($lines) ?? '';
            $storyLines = array_values(array_filter($lines, fn ($l) => trim($l) !== ''));
        @endphp
        <div class="uj-card" data-win="{{ $deal->id }}" style="padding:18px 20px;display:flex;flex-direction:column;gap:8px;">
            <span style="font-size:11px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:.04em;">
                {{ ucfirst(str_replace('_', ' ', $deal->type)) }} · {{ $deal->published_at->format('D j M Y') }}@if ($deal->project) · {{ $deal->project->name }}@endif
            </span>
            <span style="font-size:16px;font-weight:600;color:var(--ink);">{{ $deal->title }}</span>
            @if ($deal->members->isNotEmpty())
                <span class="uj-bd-team">
                    @foreach ($deal->members as $member)
                        <span class="uj-db-avatar" data-big-deal-member="{{ $member->id }}" style="background:{{ $member->avatar_color ?? '#3a6ea5' }}">{{ $member->initials }}</span>
                    @endforeach
                    <small>{{ $deal->members->pluck('display_name')->implode(', ') }}</small>
                </span>
            @endif
            @if ($oneLiner)<span style="font-size:13px;color:var(--body);">{{ $oneLiner }}</span>@endif
            @if ($storyLines !== [] || $deal->track_ref || $deal->raisedBy)
                <div class="uj-bd-story">
                    <b>What it took</b>
                    @foreach ($storyLines as $line)<p>{{ $line }}</p>@endforeach
                    <i>Raised by {{ $deal->raisedBy?->display_name ?? '—' }}@if ($deal->track_ref) · Track {{ $deal->track_ref }}@endif @if ($deal->names_approved && $deal->client_contact) · {{ $deal->client_contact }}@endif</i>
                </div>
            @endif
            @if ($deal->photos->isNotEmpty())
                <div class="uj-bd-photos">
                    @foreach ($deal->photos as $photo)
                        <img src="{{ route('big-deals.photos.show', [$deal->id, $photo->id]) }}" alt="" />
                    @endforeach
                </div>
            @endif
            {!! $row['reactHtml'] !!}
        </div>
    @empty
        <p style="font-size:13px;color:var(--muted);">No Big Deals raised yet.</p>
    @endforelse
</div>
@endsection
