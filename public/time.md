# Time Service Contract

Time is Elonn's calendar Service.

It owns calendar and event data (CalDAV-backed calendar objects: events and tasks), scoped strictly to the
authenticated member's own calendar. It does not own Social events (`social.event`, owned by
`social.elonn`) — a Social event synced into a member's calendar arrives here only as an ingested calendar
object carrying a `source` reference back to the originating Social event.

## Contract

- Service id: `time.elonn`
- Domain: `calendar`
- Revision: `4`
- Published: `2026-09-16T00:00:00Z`
- Canonical JSON: `https://time.elonn.com/time.json`
- Service Publication: `https://time.elonn.com/time-publication.json`

The canonical JSON contract is authoritative. This Markdown document describes the same Service contract
for human readers.

## Labels

The contract carries a top-level `labels` pairs table — the display copy a generic consumer
(the runtime form renderer, the reasoning Model, a Service Dashboard) shows for each argument.
Argument schemas reference it by `label_ref`; a platform orchestrator resolves the refs to text
before presenting a schema.

| Ref | Text |
| --- | --- |
| `field.search_text` | Search |
| `field.result_limit` | How many to show |
| `field.title` | Title |
| `field.description` | Description |
| `field.location` | Location |
| `field.starts_at` | Starts |
| `help.starts_at` | An ISO 8601 date/time, e.g. 2026-09-18T12:00:00-04:00. |
| `field.ends_at` | Ends |
| `field.all_day` | All day |
| `field.timezone` | Time zone |
| `help.timezone` | An IANA time zone name, e.g. America/New_York. Defaults to the calendar's own time zone, or UTC. |
| `field.recurrence_rule` | Repeats |
| `help.recurrence_rule` | An RFC 5545 RRULE value, e.g. FREQ=WEEKLY;BYDAY=FR. Leave blank for a one-time item. |
| `field.calendar` | Calendar |
| `help.calendar` | The calendar to use, by name. Defaults to the member's first calendar, creating one if they have none yet. |
| `field.attendees` | Attendees |
| `help.attendees` | A comma-separated list of attendees, e.g. Jane Smith <jane@example.com>, bob@example.com. |
| `field.due_at` | Due |
| `field.priority` | Priority |
| `help.priority` | 0-9, where 1 is highest priority and 9 is lowest. Leave blank for no priority. |
| `field.range_start` | From |
| `field.range_end` | To |
| `field.task_status` | Status |
| `enum.task_status.open` | Open |
| `enum.task_status.completed` | Completed |
| `enum.task_status.all` | All |
| `field.due_before` | Due before |
| `field.due_after` | Due after |

## Authentication

Time requires authenticated platform Service calls using Conductor signed requests.

`POST /time/call` accepts authenticated Calls from `conductor.elonn`. `mind.elonn` remains accepted via a
static service token for compatibility.

## Endpoint

### `POST /time/call`

Accepts one canonical `Call` and returns one canonical Service `Dataset` (`side_effects: false`).

The Call must include:

- `id`
- `content`
- `context`

The `content.operation` value selects the Time operation.

## Entry Points

Time declares six entry points — its meaningful, standalone human entrances, independent of which
operations are `model_selectable`. **Today** is the primary (default) entrance. `time.search` and
`time.list` deliberately have no entry point of their own: neither has a preset that means anything
as a one-tap entrance, and both remain reachable through ordinary free-text Calls. Each entry point
also carries a `group` — `view` (Today, This week, Tasks, Calendars) or `create` (Add event, Add
task) — a layout hint a platform orchestrator passes through so a runtime can lay the dashboard out
as rows (one row per group) instead of one long stack; it is presentation only and does not affect
invocation.

The **Today** and **This week** presets use the literal relative words `today` / `tomorrow` /
`+7 days`, not pre-computed dates — Time's own date parsing resolves them fresh against the current
moment on every request, so these entrances always reflect the real current day regardless of when
the Contract was published.

| id | label | group | operation | preset arguments |
|---|---|---|---|---|
| `time.entry.today` (primary) | Today | `view` | `time.agenda` | `start: today`, `end: tomorrow` |
| `time.entry.week` | This week | `view` | `time.agenda` | `start: today`, `end: +7 days` |
| `time.entry.tasks` | Tasks | `view` | `time.tasks` | `status: open` |
| `time.entry.calendars` | Calendars | `view` | `time.calendars` | — |
| `time.entry.new_event` | Add event | `create` | `time.event.create` | — |
| `time.entry.new_task` | Add task | `create` | `time.task.create` | — |

## Operations

Each operation's arguments are declared in the canonical JSON contract under `endpoints[0].operations`. An
argument's `source` is either `model` (supplied by whichever caller selected the operation) or `context`
(resolved by the calling platform orchestrator itself, never asked of a Model). Member identity is resolved
this way already, forwarded as the `X-Elonn-Member-Id` request header — Time does not declare it as a Call
argument.

An operation may also declare `model_selectable: false`, meaning a reasoning Model shall never select it
directly — it remains reachable only through an explicit `operation_invocation` whose target was already
established by an earlier Dataset (an object the member already saw), never invented from a raw query.

### `time.search`

Search the member's own calendar objects by title, description, or location.

| argument | required | source | default |
|---|---|---|---|
| `text` | yes | model | — |
| `limit` | no | model | `10` |

### `time.list`

Show the member's recent calendar objects.

| argument | required | source | default |
|---|---|---|---|
| `limit` | no | model | `10` |

### `time.open` — not Model-selectable

Open a single calendar object already identified by a prior Dataset action's `object_id`.

| argument | required | source |
|---|---|---|
| `object_id` | yes | context (`object_id`) |

### `time.calendars`

Discover the calendars available to the member. Takes no arguments.

### `time.agenda`

Show calendar events within a requested time range, expanding recurring events that fall within it.

| argument | required | source | default |
|---|---|---|---|
| `start` | yes | model | — |
| `end` | yes | model | — |
| `timezone` | no | model | `UTC` |

### `time.tasks`

Show the member's tasks, optionally filtered by status and due date.

| argument | required | source | default |
|---|---|---|---|
| `status` | no | model (enum: `open`, `completed`, `all`) | `open` |
| `due_before` | no | model | — |
| `due_after` | no | model | — |

### `time.event.create`

Create a new calendar event.

| argument | required | source | default |
|---|---|---|---|
| `title` | yes | model | — |
| `starts_at` | yes | model | — |
| `ends_at` | no | model | `starts_at` + 1 hour |
| `all_day` | no | model | `false` |
| `timezone` | no | model | the calendar's time zone, else UTC |
| `location` | no | model | — |
| `description` | no | model | — |
| `recurrence_rule` | no | model | — |
| `calendar` | no | model | the member's first calendar, created if none exists |
| `attendees` | no | model | — |

### `time.event.update` — not Model-selectable

Update an existing calendar event already identified by a prior Dataset action's `object_id`. Every
argument besides `object_id` is optional; only supplied fields change.

| argument | required | source |
|---|---|---|
| `object_id` | yes | context (`object_id`) |
| `title`, `starts_at`, `ends_at`, `all_day`, `timezone`, `location`, `description`, `recurrence_rule`, `calendar`, `attendees` | no | model |

### `time.event.delete` — not Model-selectable

| argument | required | source |
|---|---|---|
| `object_id` | yes | context (`object_id`) |

### `time.task.create`

Create a new task.

| argument | required | source |
|---|---|---|
| `title` | yes | model |
| `description`, `due_at`, `starts_at`, `priority`, `recurrence_rule`, `calendar` | no | model |

### `time.task.update` — not Model-selectable

| argument | required | source |
|---|---|---|
| `object_id` | yes | context (`object_id`) |
| `title`, `description`, `due_at`, `starts_at`, `priority`, `recurrence_rule`, `calendar` | no | model |

### `time.task.complete` / `time.task.reopen` — not Model-selectable

| argument | required | source |
|---|---|---|
| `object_id` | yes | context (`object_id`) |

### `time.task.delete` — not Model-selectable

| argument | required | source |
|---|---|---|
| `object_id` | yes | context (`object_id`) |

## Response

Time returns one canonical Service `Dataset` containing `time.calendar_event`, `time.task`, or
`time.calendar` objects as appropriate to the operation.

## Side Effects

`time.event.create`, `time.event.update`, `time.event.delete`, `time.task.create`, `time.task.update`,
`time.task.complete`, `time.task.reopen`, and `time.task.delete` create or modify data. Every other
operation is read-only.

## Privacy

All operations are scoped to the authenticated member identity supplied by the calling platform service
(`X-Elonn-Member-Id`) — every query filters by `identity_user_id`, no cross-member visibility exists.

## Errors

Time may return these errors in the response Dataset:

- `time.service_auth_failed`
- `time.member_required`
- `time.unsupported_operation`
- `time.invalid_search_call`
- `time.invalid_event_call`
- `time.invalid_task_call`
- `time.calendar_not_found`
- `time.forbidden_mutation`
- `time.validation_failed`
- `time.object_not_found`
