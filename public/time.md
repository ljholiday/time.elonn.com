# Time Service Contract

Time is Elonn's calendar Service.

It owns calendar and event data (CalDAV-backed calendar objects: events and tasks), scoped strictly to the
authenticated member's own calendar. It does not own Social events (`social.event`, owned by
`social.elonn`) — a Social event synced into a member's calendar arrives here only as an ingested calendar
object carrying a `source` reference back to the originating Social event.

## Contract

- Service id: `time.elonn`
- Domain: `calendar`
- Revision: `1`
- Published: `2026-08-22T00:00:00Z`
- Canonical JSON: `https://time.elonn.com/time.json`
- Service Publication: `https://time.elonn.com/time-publication.json`

The canonical JSON contract is authoritative. This Markdown document describes the same Service contract
for human readers.

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

## Response

Time returns one canonical Service `Dataset` containing `time.calendar_event` or `time.task` objects as
appropriate to the operation.

## Side Effects

None of the operations in this Contract create or modify data.

## Privacy

All operations are scoped to the authenticated member identity supplied by the calling platform service
(`X-Elonn-Member-Id`) — every query filters by `identity_user_id`, no cross-member visibility exists.

## Errors

Time may return these errors in the response Dataset:

- `time.service_auth_failed`
- `time.member_required`
- `time.unsupported_operation`
- `time.invalid_search_call`
- `time.object_not_found`
