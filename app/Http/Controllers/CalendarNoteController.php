<?php

namespace App\Http\Controllers;

use App\Models\CalendarNote;
use App\Models\Employee;
use App\Models\WorkItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View as ViewContract;

/**
 * The Personal tab's own entries on the dashboard calendar: a note on a day, or
 * one of the viewer's open board cards pinned to a day. Every action answers
 * with the rebuilt calendar card, landed on the day that was just touched, so
 * the dashboard swaps one widget's insides and nothing else moves.
 *
 * Rows are private. Ownership is the employee on the request, never a route
 * parameter, so nobody can reach another person's notes by guessing an id.
 */
class CalendarNoteController extends Controller
{
    public function store(Request $request): ViewContract
    {
        $employee = $this->owner($request);

        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'work_item_id' => ['nullable', 'integer'],
            'title' => ['required_without:work_item_id', 'nullable', 'string', 'max:120'],
            'starts_at' => ['nullable', 'date_format:H:i'],
            'ends_at' => ['nullable', 'date_format:H:i', 'after:starts_at'],
            'body' => ['nullable', 'string', 'max:2000'],
        ]);

        if (isset($data['work_item_id'])) {
            // Only your own open cards can be pinned, and only once per day.
            $card = $this->pinnable($employee)->whereKey($data['work_item_id'])->firstOrFail();

            $exists = CalendarNote::where('employee_id', $employee->id)
                ->where('work_item_id', $card->id)
                ->whereDate('date', $data['date'])
                ->exists();

            if (! $exists) {
                CalendarNote::create([
                    'employee_id' => $employee->id,
                    'work_item_id' => $card->id,
                    'date' => $data['date'],
                ]);
            }
        } else {
            CalendarNote::create([
                'employee_id' => $employee->id,
                'date' => $data['date'],
                'title' => $data['title'],
                'starts_at' => $data['starts_at'] ?? null,
                'ends_at' => $data['ends_at'] ?? null,
                'body' => $data['body'] ?? null,
            ]);
        }

        return $this->widget($request, $data['date']);
    }

    public function update(Request $request, int $note): ViewContract
    {
        $employee = $this->owner($request);
        $row = CalendarNote::where('employee_id', $employee->id)->whereNull('work_item_id')->findOrFail($note);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'starts_at' => ['nullable', 'date_format:H:i'],
            'ends_at' => ['nullable', 'date_format:H:i', 'after:starts_at'],
            'body' => ['nullable', 'string', 'max:2000'],
        ]);

        $row->update([
            'title' => $data['title'],
            'starts_at' => $data['starts_at'] ?? null,
            'ends_at' => $data['ends_at'] ?? null,
            'body' => $data['body'] ?? null,
        ]);

        return $this->widget($request, $row->date->toDateString());
    }

    public function destroy(Request $request, int $note): ViewContract
    {
        $employee = $this->owner($request);
        $row = CalendarNote::where('employee_id', $employee->id)->findOrFail($note);
        $date = $row->date->toDateString();
        $row->delete();

        return $this->widget($request, $date);
    }

    /**
     * The viewer's open cards: assigned to them, not done, not archived. This is
     * the list the day panel offers to pin from.
     *
     * @return Builder<WorkItem>
     */
    public static function pinnable(Employee $employee)
    {
        return WorkItem::query()
            ->where('employee_id', $employee->id)
            ->where('status', '!=', 'done')
            ->whereNull('archived_at')
            ->whereNull('cancelled_at')
            ->orderBy('due_at')
            ->orderBy('title');
    }

    private function owner(Request $request): Employee
    {
        $employee = $request->attributes->get('employee');
        abort_unless($employee instanceof Employee, 403);

        return $employee;
    }

    private function widget(Request $request, string $date): ViewContract
    {
        $request->query->set('at', substr($date, 0, 7));
        $request->query->set('sel', $date);

        return app(AppController::class)->dashboardWidgetPartial($request, 'calendar');
    }
}
