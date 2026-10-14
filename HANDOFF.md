# Handoff — MyBB Event Request

For Kevin (TK-33151) / the Event Management plugin. This is a public event-request setup a
member garrison built against your plugin, offered for integration. It replaces a Contact
Form 7 + direct-database bridge with something that goes through your own event-save logic.

TL;DR: there's a WordPress plugin (the public form) and a small MyBB endpoint
(`event_request.php`). The endpoint calls `events_save_event()` — it doesn't touch your tables.
The one open question is at the bottom: **would you want real fields for the venue/request
metadata, instead of us folding it into the description?**

## The two halves

```
Browser (public form)
    │  posts to WordPress (nonce + honeypot, no secret)
    ▼
WordPress plugin  ── server-to-server, bearer token ──►  event_request.php  (your side)
                                                              │
                                                              ▼
                                                    events_save_event(0, $input, $uid)
                                                              │
                                                              ▼
                                                    pending event (+ announcement
                                                    thread when staff set it live)
```

- **Your side:** `mybb-endpoint/event_request.php` — the only file that would live in the
  plugin. Written to your conventions (`events_*` naming, Apache-2.0).
- **Our side:** `wordpress-plugin/` — the request form. Not yours to maintain.
- **The contract:** `API.md`.

## How the endpoint works

Built against Event Management **v1.4.0**. On a `POST` it:

1. Checks the shared bearer token (`hash_equals`).
2. Loads the configured intake user into `$mybb->user` (a real account in a coordinator
   group — the saving user is always an allowed coordinator, so this validates cleanly).
3. Builds the same `$input` array `events_event_form_input()` returns.
4. `events_validate_event_input($input)` → returns your own error strings on `422`.
5. `events_save_event(0, $input, $intake_uid)` → creates the event as **`pending`**, so no
   announcement thread is posted until staff set it live. Same review step a mod queue gives.

A `GET ?action=config` returns your live `events_regions()` and `events_event_types()` so the
form's region dropdown only ever offers regions the board actually has. (That's what removed
the old free-text "city vs region" mismatch.)

## Field mapping — and the question for you

**These map onto real event fields:**

| Form field | → |
|---|---|
| Event name | `title` (capped to `EVENTS_TITLE_MAX_LENGTH`) |
| Region | `region` (validated against `events_regions()`) |
| Venue address | `address` |
| Event date + start/end time | `start_date` / `end_date` |
| Description | `description` |

Single-day only for now: the form sends one date, so `end_date = start_date`. Multi-day is
left for staff to extend on review.

**Everything else has nowhere to go in your schema**, so it's folded into the description as a
labelled "Event Request Details" block — the requester's name/email/phone, plus whatever extra
questions that garrison's form asks (parking, changing room, amenities, props allowed, charity,
sister clubs, "how did you hear about us", etc.). Those extra questions are **admin-configured
per garrison** on the WordPress side, so the plugin ships generic. The WordPress side composes
them into a `request_details` string; the endpoint just appends it. Adding a question never
touches your code.

**The ask:** folding into the description works, but it's a dumping ground. Would you be open
to either:

1. **A few real fields** for the common venue/facility metadata (parking, amenities,
   changing/staging, props policy), so an intake request could map onto columns instead of
   text? and/or
2. **The intake endpoint as a supported part of the plugin**, so public event requests have a
   blessed way in rather than each garrison bolting one on.

If you add fields, it's a one-place change here: the endpoint's `events_intake_description()`
is where folding happens, and real fields would just move those keys into `$input` instead.
Happy to do that work and send it as a PR once you've said how you'd want it shaped.

## Try it

See `README.md` for the step-by-step. Short version: create an intake user, drop
`event_request.php` at the forum root, set the token + intake uid in its config block; on
WordPress, set the endpoint URL + token and add your custom questions.

## Status

**Not yet tested against a live stack** — built against your v1.4.0 source, but it needs a real
end-to-end run (submit → pending event appears → set live → thread posts) before production.
The form has a honeypot and rate limit; a public form should also get a CAPTCHA.
