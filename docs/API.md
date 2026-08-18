# AmanahKu API (v1)

This is the reference for AmanahKu's read-only HTTP API. It is written for a
developer — or a coding agent — in another repository (DevStage 01, Track,
SupportOS) who has never seen AmanahKu's codebase and needs to wire up a
client against it. A machine-readable version of the same six endpoints
lives at [`/openapi.json`](/openapi.json) (OpenAPI 3.1).

Base URL: `https://amanahku.unijaya.com/api/v1`

## 1. What this is

AmanahKu is the source of truth for **which projects exist in Unijaya and
what kind each is** — its name, its code, and the category tags (e.g.
`Development`, `Maintenance`) that describe the kind of work it is. The API
also exposes the employee directory, position bands, leave requests,
finalized payslips, and weekly effort aggregated per project and position
band.

It deliberately does **not** answer *how a project is going*. Budget,
phases and delivery cadence live in Track, on purpose, so there is not a
second record of that information to keep in sync. If you need project
status, timeline, or budget, that is a Track concern, not an AmanahKu one.

## 2. What it is not

Read carefully — an integration that assumes any of the following will
break or silently do the wrong thing:

- **No write endpoints of any kind.** Every route in this document is
  `GET`. There is no way to create, update, or delete anything through this
  API — not a project, not a leave request, not a payslip. All six routes
  are read-only.
- **No webhooks or callbacks.** AmanahKu never calls out to you. If you
  need to know when something changes, poll the relevant endpoint on your
  own schedule.
- **No pagination.** Every list endpoint returns the whole tenant's data
  in one response. There is no `page`, `per_page`, `cursor`, or `next`
  field to follow, and none will appear in a future response you have not
  already coded for.
- **No rate limit today.** There is no `429` response, and no
  `X-RateLimit-*` header, to plan around. This may change later, but
  nothing in the current API enforces one.

## 3. Authentication

Send the key as a bearer token on every request:

```
Authorization: Bearer <key>
```

Keys are issued per application (one key per app, e.g. one for Track, one
for SupportOS) from AmanahKu's super-admin console, at
`/admin/companies/{slug}/api-keys`. Each key carries a fixed list of
**scopes** chosen at issue time — see [Scopes](#6-scopes) below — and a
key only works for the endpoints its scopes cover.

A key is bound to exactly **one company** (tenant). There is no way to use
one company's key to read another company's data, and no header or
parameter to select a different company — the key itself is the only thing
that decides whose data you see.

The key's plaintext is shown once, at the moment it is issued, and never
again. If it is lost, it must be revoked and a new one issued from the same
screen — there is no recovery flow.

## 4. Envelope

Every response is JSON with the same two top-level keys:

```json
{ "data": ..., "error": null }
```

on success, or

```json
{ "data": null, "error": "message" }
```

on failure. `data` is `null` on every failure and never `null` on success
(a list endpoint with nothing to return still gets `"data": []`, not
`"data": null`).

**Status codes:**

| Status | Meaning |
|---|---|
| `200` | Success. |
| `401` | The key is missing, unrecognized, or (if recognized) its company binding no longer holds — e.g. the app it belongs to was moved to another company. |
| `403` | The key is valid but lacks the scope the endpoint requires. |
| `422` | A required query parameter is missing or malformed (`/timesheet-effort` only — see below). |
| `5xx` | Something broke on AmanahKu's side. |

**A `401` has two different bodies depending on which failure it is** —
this is easy to get wrong if you only test one case:

- A **missing or completely unrecognized** key never reaches AmanahKu's own
  code at all; Laravel's authentication layer rejects it first, so the body
  is Laravel's default shape, not this API's envelope:
  ```json
  { "message": "Unauthenticated." }
  ```
- A key that Laravel **does** recognize, but whose company binding is
  broken (its app was reassigned to a different company, or — for a
  person-token — the person's membership was revoked), gets this API's own
  envelope:
  ```json
  { "data": null, "error": "Unauthenticated." }
  ```

  Either way the status is `401` and the safe thing to do is the same:
  treat the key as dead and stop retrying with it. Do not depend on the
  body shape to tell the two cases apart.

A `5xx` means the request never reached a controller's own error handling —
it is an uncaught fault, so it does **not** use this API's `{data, error}`
envelope either. The body is whatever AmanahKu's framework-level handler
renders for that fault, plus a `reference` key added on top **when the fault
was actually logged**:

```json
{ "message": "Server Error", "reference": "AB12CD34" }
```

Do not depend on `message` being any particular string — treat a `5xx` as
"AmanahKu failed, try again later." If `reference` is present, quote it when
reporting the fault — it is how the team finds the matching entry in
AmanahKu's own error log. Most faults carry one, but do not assume it is
always there: `reference` is only set when the fault was captured for the
error log, and a deliberate `abort(500, ...)` or a maintenance-mode `503` is
a kind of exception Laravel does not log, so those responses have no
`reference` key at all. Code that reads the body should treat the key as
optional.

## 5. Endpoints

All six endpoints live under `/api/v1` and require `Authorization: Bearer
<key>`. Listed in the order scopes are declared in `ApiClient::SCOPES`.

### `GET /projects` — requires `projects:read`

Every active project in the key's company, with its category tags.
Categories are drawn from a fixed set: `Development`, `Maintenance`,
`InHouse Project`, `Sales`. A project with no category tags returns an
empty array, not `null`. `categories` is sorted alphabetically — the
underlying relation carries no defined order, so this API sorts it rather
than leave a consumer that stores or diffs the array to see phantom
changes.

```json
{
  "data": [
    { "id": 1, "code": "UJ-1", "name": "iLPF", "categories": ["Development"] }
  ],
  "error": null
}
```

```bash
curl -H "Authorization: Bearer $AMANAHKU_KEY" https://amanahku.unijaya.com/api/v1/projects
```

### `GET /employees` — requires `employees:read`

The company's active employee directory (name, email, position label,
status, department, branch). Archived employees are excluded.

```json
{
  "data": [
    {
      "id": 1,
      "name": "Ali Hassan",
      "email": "ali@example.com",
      "position": "Senior Engineer",
      "status": "active",
      "department": "Operation",
      "branch": null
    }
  ],
  "error": null
}
```

```bash
curl -H "Authorization: Bearer $AMANAHKU_KEY" https://amanahku.unijaya.com/api/v1/employees
```

### `GET /positions` — requires `positions:read`

The company's position bands (title, code, department, level, status).
Inactive bands are included — historical effort can still reference a
band that has since been retired. Salary is deliberately absent: there is
no `max_salary` or any other pay figure in this response, ever.

```json
{
  "data": [
    {
      "id": 1,
      "title": "Senior Engineer",
      "code": "SE",
      "department": "Operation",
      "level": "Senior",
      "status": "active"
    }
  ],
  "error": null
}
```

```bash
curl -H "Authorization: Bearer $AMANAHKU_KEY" https://amanahku.unijaya.com/api/v1/positions
```

### `GET /timesheet-effort?week_start=YYYY-MM-DD` — requires `effort:read`

Every project's timesheet effort for one week, aggregated per position
band. `week_start` is **required** and must be the exact `YYYY-MM-DD` date
the target week starts on (the Monday) — AmanahKu's timesheets are stored
against that Monday and matched on it exactly. **Sending any other day of
that week is not an error**: you get a normal `200` back with `"projects":
[]`, because the match is exact, not a range. There is no signal in the
response that tells you the date you sent was off by a day or two — get
the Monday right, or you will read a real week as having no effort at all.
Calling without `week_start`, or with a value that does not parse as a
date, returns `422` with Laravel's standard validation body
(`{"message": ..., "errors": {"week_start": [...]}}`), not this API's
`{data, error}` envelope.

A week is counted once it is `submitted` or `approved`; draft and rejected
weeks contribute nothing. Figures are aggregated **server-side on
purpose**: no employee name, no employee id, and no salary figure ever
leaves AmanahKu through this endpoint. `person_days` is the sum of each
person's daily percentage divided by 100; `alloc_pct` is average dedication
across the band's headcount for the days they appear.

```json
{
  "data": {
    "week_start": "2026-08-03",
    "projects": [
      {
        "project_id": 1,
        "positions": [
          {
            "position_id": 1,
            "position_title": "Senior Engineer",
            "headcount": 1,
            "person_days": 1,
            "days_present": 1,
            "alloc_pct": 100
          }
        ]
      }
    ]
  },
  "error": null
}
```

A project with no counted effort that week does not appear in `projects`
at all (there is no zero-effort placeholder row). An employee with no
position band is grouped under `"position_id": null, "position_title":
null` rather than dropped.

```bash
curl -H "Authorization: Bearer $AMANAHKU_KEY" "https://amanahku.unijaya.com/api/v1/timesheet-effort?week_start=2026-08-03"
```

### `GET /leave-requests` — requires `leave:read`

Every leave request in the company, newest first.

```json
{
  "data": [
    {
      "id": 1,
      "employee": "Ali Hassan",
      "leave_type": "Annual",
      "date_from": "2026-07-01",
      "date_to": "2026-07-02",
      "days": 2,
      "status": "submitted"
    }
  ],
  "error": null
}
```

```bash
curl -H "Authorization: Bearer $AMANAHKU_KEY" https://amanahku.unijaya.com/api/v1/leave-requests
```

### `GET /payslips` — requires `payslips:read`

Requires the payslips:read scope, which is held only by staff tokens — it cannot be granted to an application key.

Every **finalized** payslip in the company. Draft or in-progress payroll
runs (still awaiting the four-eyes approval AmanahKu requires before a
payslip is final) never appear here, regardless of the key's scopes —
there is no way to read a payslip AmanahKu itself does not yet consider
final.

```json
{
  "data": [
    {
      "id": 1,
      "employee": "Ali Hassan",
      "period": "2026-05",
      "run_status": "finalized",
      "gross": 5000,
      "net_pay": 4200,
      "total_deductions": 800
    }
  ],
  "error": null
}
```

```bash
curl -H "Authorization: Bearer $AMANAHKU_KEY" https://amanahku.unijaya.com/api/v1/payslips
```

## 6. Scopes

A key carries whichever of these scopes were ticked when it was issued.
Requesting an endpoint without the scope it requires returns `403`, not a
filtered response — there is no partial access to an endpoint.

| Scope | Grants |
|---|---|
| `projects:read` | Projects and their categories |
| `employees:read` | Employee directory (names, emails, positions) |
| `positions:read` | Position bands (no salary) |
| `effort:read` | Weekly timesheet effort per band (no names, no salary) |
| `leave:read` | Leave requests |

`payslips:read` cannot be granted to an application key. The endpoint remains reachable by a staff token, which carries every ability, and is documented below for that reason.

`effort:read` should only be granted to an app that costs work against it
— it is the one scope whose data exists specifically to feed another
system's cost or billing calculations (Track's staff-dedication rows), so
handing it to an app with no use for that number is scope creep, not
convenience.
