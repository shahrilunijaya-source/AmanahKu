<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AppNotification;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Flower;
use App\Support\ProfileWall;
use App\Tenancy\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "Caught Being Brilliant" flowers on a colleague's profile Wall (CR-23). No
 * approval needed — the only gates are the 3-per-month and 1-per-recipient
 * caps, enforced here. Route-model binding is not tenant-scoped (AK note), so
 * every action re-checks tenant_id itself before touching a bound
 * Employee/Flower.
 */
class FlowerController extends Controller
{
    public const MAX_PER_MONTH = 3;

    public function store(Request $request, Employee $employee): JsonResponse
    {
        $this->assertSameTenant($employee->tenant_id);

        $giver = $request->attributes->get('employee');
        abort_unless($giver, 403, 'No employee profile in this workspace.');
        abort_if($giver->id === $employee->id, 422, 'Cannot give yourself a flower.');
        abort_unless($employee->status === 'active', 422, 'That person is not active.');

        $month = CarbonImmutable::now()->format('Y-m');

        abort_if(
            Flower::where('giver_id', $giver->id)->where('month', $month)->count() >= self::MAX_PER_MONTH,
            422,
            'You have given all 3 flowers this month.',
        );
        abort_if(
            Flower::where('giver_id', $giver->id)->where('recipient_id', $employee->id)->where('month', $month)->exists(),
            422,
            'You already gave this person a flower this month.',
        );

        $data = $request->validate(['note' => ['required', 'string', 'max:200']]);

        Flower::create([
            'giver_id' => $giver->id,
            'recipient_id' => $employee->id,
            'note' => $data['note'],
            'month' => $month,
        ]);

        AppNotification::send(
            $employee->user_id,
            "{$giver->display_name} gave you a flower \u{1F338}",
            null,
            route('app.screen', 'profile').'?emp='.$employee->id,
        );

        return $this->respond($request, $employee, $giver);
    }

    /** HR only — moderation, not a per-giver delete. */
    public function hide(Request $request, Flower $flower): JsonResponse
    {
        $this->assertSameTenant($flower->tenant_id);
        $this->authorizeTenantRole($request, ['hr']);

        $actor = $request->attributes->get('employee');
        $flower->update(['hidden_at' => now(), 'hidden_by_id' => $actor?->id]);

        $recipient = Employee::findOrFail($flower->recipient_id);
        AuditLog::record('Hid flower', "From {$flower->giver?->display_name} to {$recipient->display_name}");

        return $this->respond($request, $recipient, $actor);
    }

    private function respond(Request $request, Employee $recipient, ?Employee $viewer): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'left' => $viewer ? $this->leftThisMonth($viewer) : 0,
            'html' => view('partials.wall', $this->wallViewData($request, $recipient, $viewer))->render(),
        ]);
    }

    /** Flowers this employee may still give this calendar month. */
    private function leftThisMonth(Employee $giver): int
    {
        $given = Flower::where('giver_id', $giver->id)
            ->where('month', CarbonImmutable::now()->format('Y-m'))
            ->count();

        return max(0, self::MAX_PER_MONTH - $given);
    }

    /**
     * Data the profile Wall partial needs, merging birthday wishes and visible
     * flowers this person has received. Also used by BuildsPeopleData for the
     * initial profile page render.
     *
     * @return array<string, mixed>
     */
    public function wallViewData(Request $request, Employee $recipient, ?Employee $viewer): array
    {
        $month = CarbonImmutable::now()->format('Y-m');

        return [
            'employee' => $recipient,
            'wall' => ProfileWall::forEmployee($recipient),
            'viewer' => $viewer,
            'canGiveFlower' => $viewer !== null && $viewer->id !== $recipient->id,
            'flowersLeft' => $viewer ? $this->leftThisMonth($viewer) : 0,
            'alreadyGaveThisMonth' => $viewer
                ? Flower::where('giver_id', $viewer->id)->where('recipient_id', $recipient->id)->where('month', $month)->exists()
                : false,
            'canHideFlowers' => $viewer !== null && $this->hasTenantRole($request, ['hr']),
        ];
    }

    private function assertSameTenant(int $recordTenantId): void
    {
        abort_unless($recordTenantId === app(CurrentTenant::class)->id(), 404);
    }
}
