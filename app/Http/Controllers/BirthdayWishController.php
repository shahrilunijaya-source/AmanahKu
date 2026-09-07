<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\BirthdayWish;
use App\Models\BirthdayWishReaction;
use App\Models\Employee;
use App\Models\PublicHoliday;
use App\Models\TotSession;
use App\Support\DashboardBands;
use App\Tenancy\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Wishes on a colleague's dashboard birthday band (CR-13). Route-model binding
 * is not tenant-scoped (AK note), so every action re-checks tenant_id itself
 * before touching a bound Employee/BirthdayWish.
 */
class BirthdayWishController extends Controller
{
    public function wish(Request $request, Employee $employee): RedirectResponse|JsonResponse
    {
        $this->assertSameTenant($employee->tenant_id);

        $author = $request->attributes->get('employee');
        abort_unless($author, 403, 'No employee profile in this workspace.');
        abort_if($author->id === $employee->id, 422, 'Cannot wish yourself.');
        abort_unless($this->isCelebratedToday($employee), 422, 'That person is not celebrated today.');

        $data = $request->validate(['body' => ['required', 'string', 'max:280']]);

        $wish = BirthdayWish::create([
            'employee_id' => $employee->id,
            'author_id' => $author->id,
            'body' => $data['body'],
            'celebrated_on' => $this->realBirthdayDate($employee),
        ]);

        return $this->respond($request, $employee);
    }

    /** Only the celebrant, one thank-you per real birthday date — replaces the previous one. */
    public function thanks(Request $request, Employee $employee): RedirectResponse|JsonResponse
    {
        $this->assertSameTenant($employee->tenant_id);

        $author = $request->attributes->get('employee');
        abort_unless($author, 403, 'No employee profile in this workspace.');
        abort_unless($author->id === $employee->id, 403);
        abort_unless($this->isCelebratedToday($employee), 422, 'Not celebrated today.');

        $data = $request->validate(['body' => ['required', 'string', 'max:280']]);
        $celebratedOn = $this->realBirthdayDate($employee);

        BirthdayWish::where('employee_id', $employee->id)
            ->where('is_thanks', true)->whereDate('celebrated_on', $celebratedOn)
            ->delete();

        BirthdayWish::create([
            'employee_id' => $employee->id,
            'author_id' => $author->id,
            'body' => $data['body'],
            'is_thanks' => true,
            'celebrated_on' => $celebratedOn,
        ]);

        return $this->respond($request, $employee);
    }

    /** One emoji per person per wish; the same one again undoes it, a new one replaces it. */
    public function react(Request $request, BirthdayWish $wish): RedirectResponse|JsonResponse
    {
        $this->assertSameTenant($wish->tenant_id);

        $reactor = $request->attributes->get('employee');
        abort_unless($reactor, 403, 'No employee profile in this workspace.');

        $data = $request->validate([
            'emoji' => ['required', 'string', 'in:'.implode(',', TotSession::EMOJI)],
        ]);

        $had = BirthdayWishReaction::where('wish_id', $wish->id)->where('employee_id', $reactor->id)->pluck('emoji');
        BirthdayWishReaction::where('wish_id', $wish->id)->where('employee_id', $reactor->id)->delete();

        if (! $had->contains($data['emoji'])) {
            try {
                BirthdayWishReaction::create([
                    'wish_id' => $wish->id,
                    'employee_id' => $reactor->id,
                    'emoji' => $data['emoji'],
                ]);
            } catch (QueryException $e) {
                // 23xxx = the unique (wish_id, employee_id) guard raced by a double click.
                if (! str_starts_with((string) $e->getCode(), '23')) {
                    throw $e;
                }
            }
        }

        $employee = Employee::findOrFail($wish->employee_id);

        return $this->respond($request, $employee);
    }

    private function respond(Request $request, Employee $employee): RedirectResponse|JsonResponse
    {
        $html = $this->wishesPartial($employee, $request->attributes->get('employee'));

        return $request->expectsJson()
            ? response()->json(['ok' => true, 'html' => $html])
            : back()->with('ok', 'Saved.');
    }

    /** Re-rendered by every wish/thanks/react action, and by the dashboard band on page load. */
    public function wishesPartial(Employee $employee, ?Employee $viewer): string
    {
        return view('partials.dash.birthday-wishes', $this->wishesViewData($employee, $viewer))->render();
    }

    /** @return array<string, mixed> */
    private function wishesViewData(Employee $employee, ?Employee $viewer): array
    {
        $wishes = BirthdayWish::with(['author', 'reactions'])
            ->where('employee_id', $employee->id)
            ->whereDate('celebrated_on', $this->realBirthdayDate($employee))
            ->orderByDesc('is_thanks')->orderByDesc('created_at')
            ->get();

        return [
            'employee' => $employee,
            'wishes' => $wishes,
            'viewerId' => $viewer?->id,
            'isCelebrant' => $viewer && $viewer->id === $employee->id,
            'celebratedToday' => $this->isCelebratedToday($employee),
        ];
    }

    /** Same working-day rule as the dashboard band (CR-13's celebratedOn()). */
    private function isCelebratedToday(Employee $employee): bool
    {
        if ($employee->birthday_private || $employee->date_of_birth === null) {
            return false;
        }

        $today = CarbonImmutable::now()->startOfDay();
        $isWorkingDay = fn (CarbonImmutable $day): bool => ! $day->isWeekend()
            && ! PublicHoliday::whereDate('date', $day->toDateString())->exists();

        foreach (DashboardBands::celebratedOn($today, $isWorkingDay) as $date) {
            if ((int) $employee->date_of_birth->format('n') === $date->month
                && (int) $employee->date_of_birth->format('j') === $date->day) {
                return true;
            }
        }

        return false;
    }

    /** This year's real birthday date, used as the wish/wall grouping key. */
    private function realBirthdayDate(Employee $employee): string
    {
        $today = CarbonImmutable::now();
        $dob = $employee->date_of_birth;

        return CarbonImmutable::create($today->year, (int) $dob->format('n'), (int) $dob->format('j'))->toDateString();
    }

    private function assertSameTenant(int $recordTenantId): void
    {
        abort_unless($recordTenantId === app(CurrentTenant::class)->id(), 404);
    }
}
