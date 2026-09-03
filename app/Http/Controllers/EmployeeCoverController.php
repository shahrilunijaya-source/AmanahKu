<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Support\ImageCompressor;
use App\Support\Tone;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * The cover photo across the top of an employee's profile (employees.cover_path).
 *
 * Everyone who can open the profile sees it, so only the person themselves may put
 * one up. HR and management may take one down (the same people who may edit the
 * profile), which is moderation, not editing someone's picture for them.
 *
 * Route-model binding is not tenant-scoped in this app, so the tenant check comes
 * first and answers 404, never a hint that the id exists elsewhere.
 */
class EmployeeCoverController extends Controller
{
    private const DISK = 'public';

    public function update(Request $request, Employee $employee): RedirectResponse
    {
        abort_unless($employee->tenant_id === app(CurrentTenant::class)->id(), 404);
        abort_unless($employee->user_id === $request->user()->id, 403);

        $presets = array_keys(config('amanahku.wallpaper_presets'));
        $request->validate([
            'preset' => ['nullable', 'string', Rule::in($presets), 'required_without:photo'],
            'photo' => ['nullable', 'image', 'mimes:jpeg,jpg,png,webp', 'max:5120', 'required_without:preset'],
        ]);

        $luminance = null;
        if ($request->filled('preset')) {
            $newPath = 'preset:'.$request->input('preset');
        } else {
            $file = $request->file('photo');
            $mime = (string) $file->getMimeType();
            $newPath = $file->store('covers/'.$employee->id, self::DISK);
            abort_unless($newPath !== false, 500, 'Photo could not be stored.');
            ImageCompressor::compress(Storage::disk(self::DISK)->path($newPath), $mime);
            $luminance = Tone::ofImage(Storage::disk(self::DISK)->path($newPath), $mime);
        }

        if ($employee->coverIsFile() && $employee->cover_path !== $newPath) {
            Storage::disk(self::DISK)->delete($employee->cover_path);
        }
        $employee->update(['cover_path' => $newPath, 'cover_luminance' => $luminance]);

        return back()->with('ok', 'Cover updated.');
    }

    public function destroy(Request $request, Employee $employee): RedirectResponse
    {
        abort_unless($employee->tenant_id === app(CurrentTenant::class)->id(), 404);
        abort_unless(
            $employee->user_id === $request->user()->id || $this->hasTenantRole($request, ['management', 'hr']),
            403
        );

        if ($employee->coverIsFile()) {
            Storage::disk(self::DISK)->delete($employee->cover_path);
        }
        $employee->update(['cover_path' => null, 'cover_luminance' => null]);

        return back()->with('ok', 'Cover removed.');
    }
}
