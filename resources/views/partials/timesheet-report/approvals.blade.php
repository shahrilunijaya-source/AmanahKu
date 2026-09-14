{{-- "To approve" tab: every submitted day from the viewer's own reports, grouped by
     person, oldest first (App\Timesheet\ApprovalQueue). Rows are server-rendered; the
     timesheetApprovals component only posts to the existing CR-03 day endpoints and
     folds a row away once it is settled, so nothing reloads.

     $queue  list of ['employee' => Employee, 'days' => list<day row>] from ApprovalQueue
     $total  number of days in $queue --}}
@php
    $left = collect($queue)->mapWithKeys(fn ($p) => [$p['employee']->id => count($p['days'])])->all();
    $isos = collect($queue)->mapWithKeys(fn ($p) => [$p['employee']->id => array_column($p['days'], 'iso')])->all();
    $peopleCount = count($queue);
@endphp
<div x-data="timesheetApprovals({ left: @js($left), isos: @js($isos) })">
    <div class="uj-ta-intro" x-show="!allDone">
        <p x-text="$store.ui.lang==='en'
            ? 'Days your team has sent in and nobody has approved yet, oldest first. Approving is optional: a sent day already counts in reports. A day you return goes back to the person with your note.'
            : 'Hari yang dihantar pasukan anda dan belum diluluskan, yang paling lama dahulu. Kelulusan adalah pilihan: hari yang dihantar sudah dikira dalam laporan. Hari yang anda kembalikan pergi semula kepada orang itu bersama nota anda.'">
            Days your team has sent in and nobody has approved yet, oldest first. Approving is optional: a sent day already counts in reports. A day you return goes back to the person with your note.
        </p>
        <span class="sum">
            <b x-text="total()">{{ $total }}</b>
            <span x-text="$store.ui.lang==='en' ? (total() === 1 ? 'day from' : 'days from') : 'hari daripada'">{{ \Illuminate\Support\Str::plural('day', $total) }} from</span>
            <b x-text="peopleLeft()">{{ $peopleCount }}</b>
            <span x-text="$store.ui.lang==='en' ? (peopleLeft() === 1 ? 'person' : 'people') : 'orang'">{{ $peopleCount === 1 ? 'person' : 'people' }}</span>
        </span>
    </div>

    @foreach ($queue as $person)
        @php
            $emp = $person['employee'];
            $first = explode(' ', trim((string) $emp->display_name))[0];
        @endphp
        <section class="uj-ta-person" :data-gone="gonePerson[{{ $emp->id }}] ? '' : null" aria-label="{{ $emp->display_name }}">
            <div class="uj-ta-clip"><div class="uj-card uj-ta-card">
                <div class="uj-ta-person-hd">
                    <span class="uj-tr-who">
                        <span class="uj-tr-av" style="background: {{ $emp->avatar_color ?: config('amanahku.avatar_color', '#1f8a65') }}">{{ $emp->initials }}</span>
                        <span>
                            <span class="uj-tr-name">{{ $emp->display_name }}</span>
                            @if ($emp->positionBand?->title)
                                <span class="uj-tr-sub">{{ $emp->positionBand->title }}</span>
                            @endif
                        </span>
                    </span>
                    <button type="button" class="uj-tr-btn" :disabled="busy !== null || left[{{ $emp->id }}] === 0" @click="approveAll({{ $emp->id }}, @js($first))">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6L9 17l-5-5"/></svg>
                        <span x-text="$store.ui.lang==='en' ? 'Approve all' : 'Luluskan semua'">Approve all</span>
                        <span class="uj-ta-n" x-text="left[{{ $emp->id }}]">{{ count($person['days']) }}</span>
                    </button>
                </div>

                @foreach ($person['days'] as $day)
                    @php
                        $key = $emp->id.'|'.$day['iso'];
                        $date = \Illuminate\Support\Carbon::parse($day['iso']);
                    @endphp
                    <div class="uj-ta-day" data-approval-day="{{ $key }}" :data-state="state[@js($key)] || null" :data-asking="asking === @js($key) ? '' : null" :data-gone="gone[@js($key)] ? '' : null">
                        <div class="uj-ta-clip"><div class="uj-ta-day-in">
                            <div class="uj-ta-date">
                                <span class="m">{{ $day['dow'] }}</span>
                                <span class="d">{{ $day['dayNum'] }}</span>
                                <span class="mo">{{ $day['month'] }}</span>
                            </div>
                            <div class="uj-ta-main">
                                @if ($day['late'] || $day['resubmitted'])
                                    <div class="uj-ta-flags">
                                        @if ($day['late'])
                                            <span class="uj-stamp" data-tone="red" x-text="$store.ui.lang==='en' ? 'Late submission' : 'Lewat dihantar'">Late submission</span>
                                        @endif
                                        @if ($day['resubmitted'])
                                            <span class="uj-stamp" x-text="$store.ui.lang==='en' ? 'Resubmitted' : 'Dihantar semula'">Resubmitted</span>
                                        @endif
                                    </div>
                                @endif
                                @if (count($day['lines']) === 0)
                                    <p class="uj-ta-none" x-text="$store.ui.lang==='en' ? 'No lines on this day.' : 'Tiada baris pada hari ini.'">No lines on this day.</p>
                                @else
                                    <ul class="uj-ta-lines">
                                        @foreach ($day['lines'] as $line)
                                            <li>
                                                <i class="dot" style="background: {{ $line['colour'] ?: 'var(--muted-soft)' }}"></i>
                                                <span class="what">{{ $line['card'] }}@if ($line['project'])<span>{{ $line['project'] }}</span>@endif</span>
                                                <span class="uj-tr-bar"><i style="width: {{ min(100, $line['percent']) }}%; background: {{ $line['colour'] ?: 'var(--muted-soft)' }};"></i></span>
                                                <span class="pct">{{ rtrim(rtrim(number_format($line['percent'], 2), '0'), '.') }}%</span>
                                            </li>
                                        @endforeach
                                    </ul>
                                @endif
                                @if ($day['returnReason'])
                                    <div class="uj-ta-was">
                                        <span x-text="$store.ui.lang==='en' ? 'Returned earlier:' : 'Dikembalikan sebelum ini:'">Returned earlier:</span>
                                        <b>{{ $day['returnReason'] }}</b>
                                    </div>
                                @endif
                            </div>
                            <div class="uj-ta-side">
                                <span class="uj-ta-total">{{ rtrim(rtrim(number_format($day['percent'], 2), '0'), '.') }}<span>%</span></span>
                                <div class="uj-ta-acts">
                                    <button type="button" class="uj-tr-btn" :disabled="busy !== null" @click="ask(@js($key))">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 14L4 9l5-5"/><path d="M4 9h11a5 5 0 010 10h-3"/></svg>
                                        <span x-text="$store.ui.lang==='en' ? 'Return' : 'Kembalikan'">Return</span>
                                    </button>
                                    <button type="button" class="uj-tr-btn" :disabled="busy !== null" @click="approve({{ $emp->id }}, @js($day['iso']), @js($first), @js($date->format('D j M')))">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6L9 17l-5-5"/></svg>
                                        <span x-text="busy === @js($key) ? ($store.ui.lang==='en' ? 'Saving…' : 'Menyimpan…') : ($store.ui.lang==='en' ? 'Approve' : 'Luluskan')">Approve</span>
                                    </button>
                                </div>
                                <span class="uj-ta-result" data-k="approved" aria-live="polite">
                                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6L9 17l-5-5"/></svg>
                                    <span x-text="$store.ui.lang==='en' ? 'Approved' : 'Diluluskan'">Approved</span>
                                </span>
                                <span class="uj-ta-result" data-k="returned" aria-live="polite">
                                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 14L4 9l5-5"/><path d="M4 9h11a5 5 0 010 10h-3"/></svg>
                                    <span x-text="$store.ui.lang==='en' ? 'Returned' : 'Dikembalikan'">Returned</span>
                                </span>
                            </div>
                            <div class="uj-ta-reason"><div class="uj-ta-clip"><div class="uj-ta-reason-in">
                                <label for="ta-reason-{{ $emp->id }}-{{ $day['iso'] }}"
                                    x-text="$store.ui.lang==='en' ? @js('Why are you sending '.$date->format('D j M').' back to '.$first.'?') : @js('Kenapa '.$date->format('D j M').' dikembalikan kepada '.$first.'?')">Why are you sending {{ $date->format('D j M') }} back to {{ $first }}?</label>
                                <textarea id="ta-reason-{{ $emp->id }}-{{ $day['iso'] }}" rows="2" maxlength="1000" x-model="reason"
                                    @keydown.escape.stop="cancel(@js($key))"
                                    :placeholder="$store.ui.lang==='en' ? 'e.g. Tuesday belongs on the Payroll card.' : 'cth. Selasa sepatutnya pada kad Payroll.'"></textarea>
                                <div class="row">
                                    <button type="button" class="uj-tr-btn" data-primary :disabled="!reason.trim() || busy !== null" @click="sendBack({{ $emp->id }}, @js($day['iso']), @js($first), @js($date->format('D j M')))">
                                        <span x-text="$store.ui.lang==='en' ? 'Send back' : 'Hantar semula'">Send back</span>
                                    </button>
                                    <button type="button" class="uj-tr-btn" @click="cancel(@js($key))">
                                        <span x-text="$store.ui.lang==='en' ? 'Cancel' : 'Batal'">Cancel</span>
                                    </button>
                                    <span class="hint" x-text="$store.ui.lang==='en' ? @js($first.' sees your note and can fix and resend.') : @js($first.' nampak nota anda dan boleh betulkan lalu hantar semula.')"></span>
                                </div>
                            </div></div></div>
                        </div></div>
                    </div>
                @endforeach
            </div></div>
        </section>
    @endforeach

    <div class="uj-ta-empty" x-show="allDone" x-cloak>
        <div class="mark">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6L9 17l-5-5"/></svg>
        </div>
        <h3 x-text="$store.ui.lang==='en' ? 'All caught up' : 'Semua sudah selesai'">All caught up</h3>
        <p x-text="$store.ui.lang==='en' ? 'Nothing from your team is waiting for you. New days show up here as they come in.' : 'Tiada apa-apa daripada pasukan anda menunggu anda. Hari baharu akan muncul di sini apabila dihantar.'">Nothing from your team is waiting for you. New days show up here as they come in.</p>
    </div>
</div>
