# MyBB Event Request

A public event-request form for the garrison website that feeds straight into the
[Southern Cross Garrison Event Management plugin](https://github.com/southern-cross-garrison/mybb-plugin-events)
for MyBB. It replaces the old Contact Form 7 + database-bridge approach: instead of scraping
a forum post back out of a thread, the request is created through the plugin's own event-save
logic, as a `pending` event for staff to review.

## The two halves

```
Browser (public form)
    │  posts to WordPress (nonce + honeypot, no secret)
    ▼
WordPress plugin  ── server-to-server, bearer token ──►  MyBB endpoint
                                                          event_request.php
                                                              │
                                                              ▼
                                                    events_save_event()  →  pending event
```

- **`wordpress-plugin/`** — a WordPress plugin (ours). Renders the request form with a region
  dropdown pulled live from the forum, and posts submissions server-side to the MyBB endpoint.
  The shared token lives only on the WordPress server, never in the browser.

- **`mybb-endpoint/event_request.php`** — a drop-in endpoint for the forum, written to match
  the Event Management plugin's conventions so it can be integrated into it. It authenticates
  the caller, builds the plugin's event input, validates it and saves it as a pending event.

- **`API.md`** — the contract between the two. This is the part meant for Kevin / the plugin.

## Why this shape

- **No raw DB writes.** The endpoint calls `events_save_event()`, so events go through the
  plugin's own validation, day handling, locking and thread generation. The plugin's schema
  can change without this breaking.
- **The region dropdown is live.** The form only offers regions the board actually has, which
  removes the free-text "city vs region" mismatch that the old bridge had.
- **Pending by default.** A request posts no announcement thread until staff set it live —
  the same review step the old mod-queue flow gave, but cleaner.

## Install (trial)

### MyBB side
1. Create a forum user for intake (e.g. "Event Intake") and add it to an Event Coordinator
   user group (Admin CP → Event Management → Settings).
2. Copy `mybb-endpoint/event_request.php` to the forum root (beside `events.php`).
3. Edit the config block at the top: set `EVENTS_INTAKE_TOKEN` to a long random secret and
   `EVENTS_INTAKE_UID` to the intake user's id.

### WordPress side
1. Copy the `wordpress-plugin/` folder into `wp-content/plugins/` (as
   `mybb-event-request/`) and activate it.
2. Settings → Event Request: set the endpoint URL (`.../event_request.php`) and the same
   token. The page shows whether it connected and which regions it can see.
3. Add any **custom questions** your garrison asks (parking, amenities, props, charity,
   sister clubs, "how did you hear about us", etc.). Each has a label, a type (single line /
   paragraph / dropdown / checkboxes), options, and a required toggle. The core fields (event
   name, region, venue, date, times, description, requester name/email/phone) are always shown.
4. Put `[mybb_event_request]` on a page.

The core fields are universal; everything your old Contact Form 7 form asked beyond them is
reproduced as custom questions, so the plugin ships generic and each garrison shapes its own
form without editing code. Custom answers are added to the event's description for staff.

## Status

First cut, **not yet tested against a live MyBB + WordPress stack.** The MyBB half is built
against Event Management v1.4.0's `events_save_event()` / `events_validate_event_input()`
input shape. Needs an end-to-end test (submit a request, confirm a pending event appears, set
it live, confirm the announcement thread posts) before it goes anywhere near production.

## Spam

The form has a honeypot and a per-client rate limit. For a public-facing form, add a CAPTCHA
(e.g. reCAPTCHA / hCaptcha / Turnstile) before going live — that is the one piece Contact
Form 7 gave for free that a custom form has to add.

## Licence

Intended for contribution to the Event Management plugin, which is Apache-2.0. Licensed the
same.
