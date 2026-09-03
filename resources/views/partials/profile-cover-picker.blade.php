{{-- Six colour chips plus upload. Each chip is a submit button in one form; upload is its own form. --}}
<div class="uj-cover-picker" x-data style="backdrop-filter:blur(14px);-webkit-backdrop-filter:blur(14px);">
    <span class="uj-cover-picker-label" x-text="$store.ui.lang==='en' ? 'Pick a colour or upload a photo' : 'Pilih warna atau muat naik foto'">Pick a colour or upload a photo</span>
    <form method="post" action="{{ route('employees.cover.update', $employee) }}" class="uj-cover-chips">
        @csrf
        @foreach (config('amanahku.wallpaper_presets') as $key => $css)
            <button type="submit" name="preset" value="{{ $key }}" class="uj-cover-chip" style="background:{{ $css }};" data-on="{{ $employee->cover_path === 'preset:'.$key ? '1' : '0' }}" aria-label="{{ ucfirst($key) }}" title="{{ ucfirst($key) }}"></button>
        @endforeach
    </form>
    <form method="post" action="{{ route('employees.cover.update', $employee) }}" enctype="multipart/form-data" style="display:contents;">
        @csrf
        <input type="file" name="photo" accept="image/jpeg,image/png,image/webp" x-ref="f" style="display:none;" @change="$el.form.requestSubmit()">
        <button type="button" class="uj-cover-chip uj-cover-chip--upload" @click="$refs.f.click()">
            + <span x-text="$store.ui.lang==='en' ? 'Upload photo' : 'Muat naik foto'">Upload photo</span>
        </button>
    </form>
    @error('photo')<p class="uj-cover-picker-error">{{ $message }}</p>@enderror
    @error('preset')<p class="uj-cover-picker-error">{{ $message }}</p>@enderror
</div>
