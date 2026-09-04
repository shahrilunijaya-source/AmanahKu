{{-- Pills above the identity card: owner changes or removes, HR/management only removes. --}}
<div class="uj-cover-actions">
    @if ($isOwn ?? false)
        <button type="button" class="uj-cover-change" x-data @click="$dispatch('cover-pick')">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"></path></svg>
            <span x-text="$store.ui.lang==='en' ? 'Change cover' : 'Tukar cover'">Change cover</span>
        </button>
    @endif
    <form method="post" action="{{ route('employees.cover.destroy', $employee) }}" style="display:contents;">
        @csrf
        <button type="submit" class="uj-cover-change">
            <span x-text="$store.ui.lang==='en' ? 'Remove cover' : 'Buang cover'">Remove cover</span>
        </button>
    </form>
</div>
