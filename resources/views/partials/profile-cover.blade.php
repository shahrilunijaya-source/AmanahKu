{{-- Profile cover: two copies of one photo. The sharp one holds the top, the blurred
     one takes over from 30% down, both are gone before the box ends, so the picture
     dissolves into the page instead of stopping on a line. $height in px. --}}
@php $coverUrl = \Illuminate\Support\Facades\Storage::disk('public')->url($employee->cover_path); @endphp
<div class="uj-cover" style="height:{{ $height }}px;">
    <div class="uj-cover-blur" style="background-image:url('{{ $coverUrl }}');"></div>
    <div class="uj-cover-img" style="background-image:url('{{ $coverUrl }}');"></div>
    @if ($isOwn ?? false)
        <form method="post" action="{{ route('employees.cover.update', $employee) }}" enctype="multipart/form-data" x-data style="display:contents;">
            @csrf
            <input type="file" name="photo" accept="image/jpeg,image/png,image/webp" x-ref="f" style="display:none;" @change="$el.form.requestSubmit()">
            <button type="button" class="uj-cover-change" @click="$refs.f.click()" style="backdrop-filter:blur(10px);-webkit-backdrop-filter:blur(10px);">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"></path></svg>
                <span x-text="$store.ui.lang==='en' ? 'Change cover' : 'Tukar cover'">Change cover</span>
            </button>
        </form>
    @endif
    @if (($isOwn ?? false) || ($canRemove ?? false))
        <form method="post" action="{{ route('employees.cover.destroy', $employee) }}" style="display:contents;">
            @csrf
            <button type="submit" class="uj-cover-change uj-cover-change--remove" style="backdrop-filter:blur(10px);-webkit-backdrop-filter:blur(10px);">
                <span x-text="$store.ui.lang==='en' ? 'Remove cover' : 'Buang cover'">Remove cover</span>
            </button>
        </form>
    @endif
</div>
