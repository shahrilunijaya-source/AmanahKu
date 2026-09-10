<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\BirthdayWish;
use App\Models\Employee;
use App\Models\Flower;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The profile "Wall" (CR-13 birthday wishes, CR-23 flowers): everything a
 * person has received, newest first, grouped by year. A hidden flower (HR
 * moderation) never appears here.
 */
final class ProfileWall
{
    /**
     * @return Collection<int, Collection<int, array{type: string, date: Carbon, model: BirthdayWish|Flower}>>
     */
    public static function forEmployee(Employee $employee): Collection
    {
        $wishes = BirthdayWish::with('author')->where('employee_id', $employee->id)->get()
            ->map(fn (BirthdayWish $w): array => ['type' => 'wish', 'date' => $w->celebrated_on, 'model' => $w]);

        $flowers = Flower::with('giver')->visible()->where('recipient_id', $employee->id)->get()
            ->map(fn (Flower $f): array => ['type' => 'flower', 'date' => $f->created_at, 'model' => $f]);

        return $wishes->concat($flowers)
            ->sortByDesc(fn (array $row): int => $row['date']->timestamp)
            ->values()
            ->groupBy(fn (array $row): int => $row['date']->year);
    }
}
