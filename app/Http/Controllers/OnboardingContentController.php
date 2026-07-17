<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\OnboardingResource;
use App\Models\Position;
use App\Services\OnboardingService;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Manages the company onboarding content library — the text/video/file/acknowledge material
 * a new hire sees behind each standard checklist item. General items carry one company-wide
 * record (position_id NULL); position items may add per-position overrides on top of that
 * default. Privileged roles (management/HR) only — company-wide policy content is not a
 * team-lead concern, so managers are excluded here (unlike OnboardingController).
 */
class OnboardingContentController extends Controller
{
    private const PRIVILEGED_ROLES = ['management', 'hr'];

    /** Private disk — files stream through the gated download route, never a public URL. */
    private const FILE_DISK = 'local';

    /**
     * Build the editor model: every standard item with its default record and any per-position
     * overrides, plus the tenant's positions for the override picker.
     */
    public function screenData(Request $request): array
    {
        $this->authorizeTenantRole($request, self::PRIVILEGED_ROLES);

        $items = OnboardingService::standardItems();
        $library = OnboardingResource::orderBy('position_id')->get()->groupBy('item_key');

        $rows = [];
        foreach ($items as $key => $meta) {
            $bucket = $library->get($key) ?? collect();

            $rows[$key] = [
                'track' => $meta['track'],
                'title' => $meta['title'],
                'default' => $bucket->firstWhere('position_id', null),
                // position_id => override record, for quick lookup in the blade.
                'overrides' => $bucket->filter(fn ($r) => $r->position_id !== null)->keyBy('position_id'),
            ];
        }

        return [
            'items' => $rows,
            'positions' => Position::with(['department', 'staffLevel'])->orderBy('sort')->orderBy('title')->get(),
            'privileged' => true,
        ];
    }

    /**
     * Upsert one content record for (item_key, position_id). A position override is only
     * honoured for position-track items; general items always write the NULL default. When a
     * record is emptied (no text/video/file/ack) it is deleted rather than kept as a blank row.
     */
    public function save(Request $request): RedirectResponse
    {
        $this->authorizeTenantRole($request, self::PRIVILEGED_ROLES);
        $tenantId = app(CurrentTenant::class)->id();
        $items = OnboardingService::standardItems();

        $data = $request->validate([
            'item_key' => ['required', 'string', Rule::in(array_keys($items))],
            'position_id' => ['nullable', 'integer', Rule::exists('positions', 'id')->where('tenant_id', $tenantId)],
            'body' => ['nullable', 'string', 'max:20000'],
            'video_url' => ['nullable', 'url', 'max:500'],
            'requires_ack' => ['nullable', 'boolean'],
            'file' => ['nullable', 'file', 'max:10240', 'mimes:pdf,doc,docx,ppt,pptx,xls,xlsx,png,jpg,jpeg'],
            'remove_file' => ['nullable', 'boolean'],
        ]);

        // Overrides only make sense per position item; ignore any position_id on a general item.
        $positionId = $items[$data['item_key']]['track'] === 'position'
            ? ($data['position_id'] ?? null)
            : null;

        $resource = OnboardingResource::firstOrNew([
            'tenant_id' => $tenantId,
            'item_key' => $data['item_key'],
            'position_id' => $positionId,
        ]);

        $resource->body = filled($data['body'] ?? null) ? $data['body'] : null;
        $resource->video_url = filled($data['video_url'] ?? null) ? $data['video_url'] : null;
        $resource->requires_ack = (bool) ($data['requires_ack'] ?? false);

        if ($request->boolean('remove_file') && $resource->file_path) {
            Storage::disk(self::FILE_DISK)->delete($resource->file_path);
            $resource->file_path = null;
            $resource->file_name = null;
        }

        if ($request->hasFile('file')) {
            if ($resource->file_path) {
                Storage::disk(self::FILE_DISK)->delete($resource->file_path);
            }
            $file = $request->file('file');
            $resource->file_path = $file->store('onboarding-content', self::FILE_DISK);
            $resource->file_name = $file->getClientOriginalName();
        }

        // Nothing left on the record — drop it so it does not linger as an empty entry.
        if (! $resource->hasContent() && ! $resource->requires_ack) {
            if ($resource->exists) {
                $resource->delete();
                AuditLog::record('Cleared onboarding content', $items[$data['item_key']]['title']);
            }

            return back()->with('ok', 'Onboarding content cleared.');
        }

        $resource->save();
        AuditLog::record('Saved onboarding content', $items[$data['item_key']]['title']);

        return back()->with('ok', 'Onboarding content saved.');
    }

    /** Stream an item's attachment. Tenant-scoped model binding already blocks cross-tenant IDs. */
    public function download(Request $request, OnboardingResource $resource): StreamedResponse
    {
        abort_unless($resource->tenant_id === app(CurrentTenant::class)->id(), 403);
        abort_unless(
            $resource->file_path && Storage::disk(self::FILE_DISK)->exists($resource->file_path),
            404,
        );

        return Storage::disk(self::FILE_DISK)->download($resource->file_path, $resource->file_name ?? 'onboarding-file');
    }
}
