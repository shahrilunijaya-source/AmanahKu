<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validates a workspace wallpaper save from the Appearance card.
 *
 * `wallpaper` is one of none | preset:<key> | upload. `upload` needs either a file in
 * this request or a photo already stored on the account, otherwise there is nothing
 * to show and the save is refused rather than silently stored as blank.
 */
class UpdateAppearanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $presets = array_map(fn (string $k) => 'preset:'.$k, array_keys(config('amanahku.wallpaper_presets')));

        return [
            'wallpaper' => ['required', 'string', Rule::in(['none', 'upload', ...$presets])],
            'dim' => ['nullable', 'string', Rule::in(array_keys(config('amanahku.wallpaper_dims')))],
            'photo' => ['nullable', 'image', 'mimes:jpeg,jpg,png,webp', 'max:5120'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $hasStored = ! empty($this->user()?->appearance['wallpaper_path'] ?? null);
            if ($this->input('wallpaper') === 'upload' && ! $this->hasFile('photo') && ! $hasStored) {
                $v->errors()->add('photo', 'Choose a photo to upload first.');
            }
        });
    }
}
