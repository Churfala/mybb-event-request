# Event Request Intake API

The contract between the WordPress plugin and the MyBB `event_request.php` endpoint. This
is the piece intended for integration into the Event Management plugin, so that public event
requests have a supported way in rather than scraping posts or writing tables directly.

The endpoint is a thin caller of the plugin's own `events_save_event()` — it builds the same
validated `$input` the event form builds and hands it over. There is still one place an event
is created; this is just another caller of it.

## Authentication

Every request carries a shared secret:

```
Authorization: Bearer <token>
```

The token is `EVENTS_INTAKE_TOKEN` on the MyBB side and the "Shared token" setting on the
WordPress side. It is only ever held server-to-server — the browser posts to WordPress, and
WordPress (not the browser) calls MyBB with the token. The public form never carries it.

Compared with `hash_equals()`. A missing or wrong token returns `401`.

## `GET ?action=config`

Returns the board's live region and event-type lists, so the request form's dropdowns always
match what the board is configured with. This is what removes the old free-text "city vs
region" mismatch: the form can only offer regions the board actually has.

**Response `200`:**

```json
{
  "ok": true,
  "regions": ["Auckland", "Wellington", "Canterbury"],
  "event_types": [
    {"value": "troop", "label": "Troop"},
    {"value": "social", "label": "Social"}
  ],
  "wwcc_name": "Working With Children Check",
  "title_max": 64
}
```

## `POST` (create an event request)

Body is a JSON object. Unknown keys are ignored; absent optional keys take safe defaults.

| Field | Required | Maps to | Notes |
|---|---|---|---|
| `title` | yes | `title` | Capped to `title_max` server-side rather than rejected. |
| `region` | yes | `region` | Must be one of the configured regions. |
| `start_date` | yes | `start_date` (date part) | `YYYY-MM-DD`. |
| `start_time` | yes | `start_date` (time part) | `HH:MM`. |
| `end_date` | yes | `end_date` (date part) | `YYYY-MM-DD`. The caller sends the event date here too for a single-day event. |
| `end_time` | yes | `end_date` (time part) | `HH:MM`. |
| `address` | no | `address` | Max 255. |
| `description` | no | `description` | The requester's own words about the event. |
| `request_details` | no | appended to `description` | Everything else, as plain `Label: value` lines, one per line. |

`request_details` is how the caller passes the things the plugin has no field of its own for:
the requester's name/email/phone, and the answers to whatever extra questions that garrison's
form asks. The caller composes it (it knows the questions); the endpoint bolds each label and
appends the block to the description under an "Event Request Details" heading. A garrison
adding a question therefore never touches this endpoint — the new answer simply arrives as
another line.

Example:

```json
{
  "title": "Mall Appearance",
  "region": "Auckland",
  "address": "123 Queen St",
  "start_date": "2026-11-20",
  "start_time": "10:00",
  "end_date": "2026-11-20",
  "end_time": "14:00",
  "description": "Family fun day, two 45-minute walks.",
  "request_details": "Submitted by: Jane Doe\nEmail: jane@example.com\nPhone: 021 555 0101\nParking: Yes\nAmenities: Water, Toilets"
}
```

Fixed server-side for an intake event:

- `event_type` = `EVENTS_INTAKE_EVENT_TYPE` (default `troop`)
- `status` = `EVENTS_INTAKE_STATUS` (default `pending` — no announcement thread until staff set it live)
- `gec_user_id` = `EVENTS_INTAKE_UID` (the intake account; the real coordinator is assigned on review)
- `poc_user_id` = 0, no limits, no exclusions, single day described by start/end.

**Response `201` (created):**

```json
{ "ok": true, "event_id": 123, "status": "pending", "thread_error": null }
```

**Response `422` (the plugin's own validation rejected it):**

```json
{ "ok": false, "error": "The event request could not be validated.", "errors": ["Invalid region.", "..."] }
```

Other statuses: `400` (body not JSON), `401` (bad token), `405` (wrong method), `503` (endpoint
not configured — no token, or a missing intake user).

## Notes for integration

- The facility fields are folded into the description for now (one function,
  `events_intake_description()`). When the plugin grows real fields for any of them, that is
  the single place to change.
- The intake user (`EVENTS_INTAKE_UID`) must be a real account in an Event Coordinator user
  group. The saving user is always an allowed coordinator, so this validates cleanly.
- `status = pending` was chosen to mirror a moderation queue. Set it to `live` only if public
  requests should appear without review.
