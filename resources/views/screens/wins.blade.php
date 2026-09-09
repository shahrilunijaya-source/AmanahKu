@extends('layouts.app')

@php
    $rows = $rows ?? collect();
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
    @forelse ($rows as $row)
        @if ($row['kind'] === 'big-deal')
            @php
                $deal = $row['deal'];
                [$oneLiner, $storyLines] = $deal->storyParts();
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
        @else
            @php
                $bell = $row['bell'];
                $card = $bell->workItem;
                $team = collect([$card?->employee])->merge($card?->participants ?? [])->filter()->unique('id');
            @endphp
            <div class="uj-card" data-win-bell="{{ $bell->id }}" style="padding:18px 20px;display:flex;flex-direction:column;gap:8px;">
                <span style="font-size:11px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:.04em;">
                    Victory bell · {{ $bell->rung_at->format('D j M Y') }}@if ($bell->project) · {{ $bell->project->name }}@endif
                </span>
                <span style="font-size:16px;font-weight:600;color:var(--ink);">{{ $card?->title }}</span>
                @if ($team->isNotEmpty())
                    <span class="uj-bd-team">
                        @foreach ($team as $member)
                            <span class="uj-db-avatar" data-victory-bell-member="{{ $member->id }}" style="background:{{ $member->avatar_color ?? '#3a6ea5' }}">{{ $member->initials }}</span>
                        @endforeach
                        <small>{{ $team->pluck('display_name')->implode(', ') }}</small>
                    </span>
                @endif
                @if ($bell->line)<span style="font-size:13px;color:var(--body);font-style:italic;">{{ $bell->line }}</span>@endif
                {!! $row['reactHtml'] !!}
            </div>
        @endif
    @empty
        <p style="font-size:13px;color:var(--muted);">Nothing here yet.</p>
    @endforelse
</div>
@endsection
