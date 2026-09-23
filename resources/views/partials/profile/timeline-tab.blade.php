{{-- Timeline tab: one card per employee_progressions row, newest first. Expects $progressions, $canSeeSalary, $role. --}}
@php
    $L = fn ($en, $ms) => '<span x-text="'.e("\$store.ui.lang==='en' ? ".json_encode($en).' : '.json_encode($ms)).'">'.e($en).'</span>';
    $editable = in_array($role ?? null, ['management', 'hr'], true);
@endphp
<div class="uj-section-head">{!! $L('Employment History', 'Sejarah Pekerjaan') !!}</div>
@forelse ($progressions as $row)
    @include('partials.profile.timeline-row', ['row' => $row, 'open' => $loop->first, 'editable' => $editable])
@empty
    <p style="font-size:12.5px;color:var(--muted);margin:0;">{!! $L('No employment history yet.', 'Tiada sejarah pekerjaan lagi.') !!}</p>
@endforelse
