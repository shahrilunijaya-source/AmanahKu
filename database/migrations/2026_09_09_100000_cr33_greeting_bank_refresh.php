<?php

declare(strict_types=1);

use App\Models\GreetingLine;
use App\Models\Tenant;
use App\Support\GreetingBank;
use Illuminate\Database\Migrations\Migration;

/**
 * CR-33: removes the `overdue`/`not_clocked_in` triggers (contradict "never about
 * performance or lateness") from every existing tenant's bank, then inserts the new
 * spec-bucket default lines (early, wednesday, saturday, month_start, all_clear,
 * long_weekend, anniversary, back_from_leave, rain) for any tenant whose bank doesn't
 * already carry that exact English line — GreetingBank::seed() alone is a no-op for a
 * tenant that already has any row, so a tenant seeded before this session never gets the
 * new lines without this explicit insert. Idempotent: safe to run more than once.
 */
return new class extends Migration
{
    public function up(): void
    {
        GreetingLine::whereIn('trigger', ['overdue', 'not_clocked_in'])->delete();

        $now = now();

        Tenant::pluck('id')->each(function (int $tenantId) use ($now) {
            $existingEn = GreetingLine::where('tenant_id', $tenantId)->pluck('text_en')->all();

            $newLines = array_filter(
                GreetingBank::DEFAULTS,
                fn (array $line) => ! in_array($line[1], $existingEn, true)
            );

            if ($newLines === []) {
                return;
            }

            GreetingLine::query()->insert(array_map(fn (array $line) => [
                'tenant_id' => $tenantId,
                'bucket' => GreetingLine::TRIGGERS[$line[0]]['bucket'],
                'trigger' => $line[0],
                'text_en' => $line[1],
                'text_ms' => $line[2],
                'approved_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ], $newLines));
        });
    }

    public function down(): void
    {
        // Data-only migration; the removed overdue/not_clocked_in rows and the exact
        // set of newly inserted rows are not recoverable in reverse. No-op down.
    }
};
