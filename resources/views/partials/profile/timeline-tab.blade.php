{{-- Timeline tab: one card per employee_progressions row, newest first. Expects $progressions, $canSeeSalary. --}}
@php
    $L = fn ($en, $ms) => '<span x-text="$store.ui.lang===\'en\' ? '.json_encode($en).' : '.json_encode($ms).'">'.e($en).'</span>';
@endphp
<div style="font-size:11px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:0.6px;">{!! $L('Employment History', 'Sejarah Pekerjaan') !!}</div>
@forelse ($progressions as $row)
    @include('partials.profile.timeline-row', ['row' => $row, 'open' => $loop->first])
@empty
    <p style="font-size:12.5px;color:var(--muted);margin:0;">{!! $L('No employment history yet.', 'Tiada sejarah pekerjaan lagi.') !!}</p>
@endforelse
