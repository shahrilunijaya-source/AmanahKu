<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\UpdateAppearanceRequest;
use App\Support\ImageCompressor;
use App\Support\Tone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

/**
 * The signed-in user's workspace wallpaper (Account & security → Appearance).
 *
 * Stored on users.appearance; only the owner ever sees it. One personal photo per
 * account: a new upload replaces and deletes the previous file. Choosing a preset
 * keeps the photo on disk so the user can switch back without re-uploading.
 */
class AppearanceController extends Controller
{
    private const DISK = 'public';

    public function update(UpdateAppearanceRequest $request): JsonResponse|RedirectResponse
    {
        $user = $request->user();
        $current = $user->appearance ?? [];
        $path = $current['wallpaper_path'] ?? null;
        $luminance = $current['wallpaper_lum'] ?? null;

        if ($request->hasFile('photo')) {
            $file = $request->file('photo');
            $mime = (string) $file->getMimeType();
            $new = $file->store('wallpapers/'.$user->id, self::DISK);
            abort_unless($new !== false, 500, 'Photo could not be stored.');
            ImageCompressor::compress(Storage::disk(self::DISK)->path($new), $mime);
            $luminance = Tone::ofImage(Storage::disk(self::DISK)->path($new), $mime);

            if ($path && $path !== $new) {
                Storage::disk(self::DISK)->delete($path);
            }
            $path = $new;
        }

        $user->appearance = [
            'wallpaper' => $request->input('wallpaper'),
            'wallpaper_path' => $path,
            'wallpaper_lum' => $luminance,
            'dim' => $request->input('dim') ?: ($current['dim'] ?? 'soft'),
        ];
        $user->save();

        return $request->expectsJson()
            ? response()->json(['luminance' => $luminance])
            : back()->with('ok', 'Background saved.');
    }

    public function destroyPhoto(Request $request): Response|RedirectResponse
    {
        $user = $request->user();
        $current = $user->appearance ?? [];

        if (! empty($current['wallpaper_path'])) {
            Storage::disk(self::DISK)->delete($current['wallpaper_path']);
        }

        $user->appearance = [
            'wallpaper' => ($current['wallpaper'] ?? 'none') === 'upload' ? 'none' : ($current['wallpaper'] ?? 'none'),
            'wallpaper_path' => null,
            'wallpaper_lum' => null,
            'dim' => $current['dim'] ?? 'soft',
        ];
        $user->save();

        return $request->expectsJson() ? response()->noContent() : back()->with('ok', 'Photo removed.');
    }
}
