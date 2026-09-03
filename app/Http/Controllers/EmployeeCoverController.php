<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Support\ImageCompressor;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

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

        $request->validate([
            'photo' => ['required', 'image', 'mimes:jpeg,jpg,png,webp', 'max:5120'],
        ]);

        $file = $request->file('photo');
        $path = $file->store('covers/'.$employee->id, self::DISK);
        abort_unless($path !== false, 500, 'Photo could not be stored.');
        ImageCompressor::compress(Storage::disk(self::DISK)->path($path), (string) $file->getMimeType());

        if ($employee->cover_path && $employee->cover_path !== $path) {
            Storage::disk(self::DISK)->delete($employee->cover_path);
        }
        $employee->update(['cover_path' => $path]);

        return back()->with('ok', 'Cover updated.');
    }

    public function destroy(Request $request, Employee $employee): RedirectResponse
    {
        abort_unless($employee->tenant_id === app(CurrentTenant::class)->id(), 404);
        abort_unless(
            $employee->user_id === $request->user()->id || $this->hasTenantRole($request, ['management', 'hr']),
            403
        );

        if ($employee->cover_path) {
            Storage::disk(self::DISK)->delete($employee->cover_path);
        }
        $employee->update(['cover_path' => null]);

        return back()->with('ok', 'Cover removed.');
    }
}
