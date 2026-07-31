<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Announcement;
use App\Models\AuditLog;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class AnnouncementController extends Controller
{
    private const PRIVILEGED_ROLES = ['management', 'hr'];

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeTenantRole($request, self::PRIVILEGED_ROLES);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'tag' => ['nullable', 'string', 'max:40'],
        ]);

        Announcement::create([
            'tenant_id' => app(CurrentTenant::class)->id(),
            'title' => $data['title'],
            'tag' => $data['tag'] ?? 'Company',
            'date' => now()->toDateString(),
        ]);

        AuditLog::record('Posted announcement', $data['title']);

        return back()->with('ok', 'Announcement posted.');
    }
}
