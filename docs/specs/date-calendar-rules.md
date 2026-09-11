# Date & Calendar Rules

Applies to CR-01, 05, 10, 11, 14, 17, 19. This is a cross-cutting section of the tracker, not a numbered CR.

**Run status:** built in session S02, before any feature work. Contract file: `contracts/dates.md`.

## 1. Locked work due dates
Due date is mandatory for Task, Assignment, Adhoc and Subtask, and **cannot be changed after first save**. Applies to all users, roles, APIs and integrations. If the work genuinely moves, the owner closes the card as Cancelled (reason) and a new card with a new date is created, history stays honest.

## 2. Reschedulable Event dates
Any T.A.A. item of type Event may have start date, end date and time changed, whether created in Amanahku or Google Calendar. Meetings, client sessions, training and company activities get postponed.

## 3. Two-way Event sync
Amanahku Event date/time change updates the linked Google Calendar event. Google Calendar change updates the linked T.A.A. Event. Same integration ID maintained so rescheduling never creates a duplicate. Every reschedule appears in the Event activity history: previous schedule, new schedule, who, timestamp. Loop protection: each side ignores echoes of its own update (version stamp), so the two systems never ping-pong.

## 4. Work items in Google Calendar
Task / Assignment / Adhoc / Subtask may appear in Google Calendar for visibility only; the due date is controlled by T.A.A. Moving the calendar entry must not change the locked due date. Amanahku restores the calendar entry to the locked date on the next sync and notes it in the card history.

## 5. Event cancellation
Deleting or cancelling a linked Google Calendar event marks the T.A.A. Event Cancelled. It is never deleted or archived; the record and history remain.

## 6. TOT Tindakan
A TOT Tindakan creates a Task, not an Event. Sasaran may be edited until the Tindakan is first saved; once saved and the Task is created, Sasaran and the Task due date are locked (rule 1).

## 7. Awards and overdue
Event cards are excluded from overdue calculations, Deadline Who?, Done & Dusted, Chief Firefighter and all other task-completion awards, because Event dates are legitimately reschedulable. All task-based awards use the locked due date of the Task / Assignment / Adhoc / Subtask.

## Acceptance
1. Change a T.A.A. Event from 10 Sep to 15 Sep, linked Google Calendar event updates to 15 Sep.
2. Change the Google Calendar event to 18 Sep, T.A.A. Event updates to 18 Sep.
3. Reschedule an Event several times, only one Event exists in both systems; activity history shows every change.
4. Attempt to change a Task due date in T.A.A., blocked (UI and API).
5. Move a Task's calendar entry to another date, T.A.A. due date unchanged; calendar entry returns to the locked date.
6. Cancel an Event in Google Calendar, T.A.A. Event marked Cancelled, remains in history.
7. Reschedule an Event, no effect on overdue statistics or monthly awards.
8. Edit a TOT Tindakan Sasaran before save, allowed; after save, locked.

**Run note:** acceptance items 1, 2, 3, 5 and 6 involve the Google leg and are satisfied in the autonomous run through the CalendarPort stub. Items 4, 7 and 8 are fully testable and are non-negotiable gates in every session.
