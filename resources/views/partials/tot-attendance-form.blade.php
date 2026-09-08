{{-- CR-09: tick list from the staff directory, one row each, with a reason box that
     appears once a row is unticked. Submits the whole list every time (tot.attendance
     replaces it), so an employee left untouched here simply keeps no attendance row for
     this session rather than defaulting either way. --}}
<form method="post" action="{{ route('tot.attendance', $session) }}"
      x-data="{
          present: {{ \Illuminate\Support\Js::from($session->attendance->where('present', true)->pluck('employee_id')->values()) }},
          reasons: {{ \Illuminate\Support\Js::from((object) $session->attendance->where('present', false)->pluck('reason', 'employee_id')->all()) }},
      }">
    @csrf
    <div style="max-height:260px;overflow:auto;display:flex;flex-direction:column;gap:6px;max-width:620px;">
        @foreach ($assignableEmployees as $e)
            <div style="display:flex;align-items:center;gap:8px;">
                <label style="display:flex;align-items:center;gap:6px;flex:1;min-width:0;">
                    <input type="checkbox" :checked="present.includes({{ $e->id }})"
                           @change="$event.target.checked ? present.push({{ $e->id }}) : present.splice(present.indexOf({{ $e->id }}), 1)">
                    <span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">{{ $e->name }}</span>
                </label>
                <input type="text" class="tot-field" style="width:220px;" x-show="!present.includes({{ $e->id }})"
                       x-model="reasons[{{ $e->id }}]"
                       :placeholder="$store.ui.lang==='en' ? 'Reason for absence' : 'Sebab tidak hadir'">
            </div>
        @endforeach
    </div>
    <template x-for="id in present" :key="'p-' + id">
        <input type="hidden" name="present[]" :value="id">
    </template>
    <template x-for="(reason, id) in reasons" :key="'a-' + id">
        <template x-if="!present.includes(Number(id))">
            <span>
                <input type="hidden" :name="`absent[${id}][employee_id]`" :value="id">
                <input type="hidden" :name="`absent[${id}][reason]`" :value="reason">
            </span>
        </template>
    </template>
    <button type="submit" class="tot-btn-p" style="margin-top:10px;" x-text="$store.ui.lang==='en' ? 'Save attendance' : 'Simpan kehadiran'">Save attendance</button>
</form>
