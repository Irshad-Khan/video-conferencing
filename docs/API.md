# API.md

Versioned REST API under `/api/v1`. Auth is Sanctum SPA cookie-session auth for the first-party web app (CSRF-protected via `withXSRFToken`); the same `auth:sanctum` guard will accept Sanctum personal-access tokens for future mobile clients without any route changes.

All responses are JSON. Successful single-resource responses are wrapped as `{"data": {...}}`. Validation failures return `422` with `{"message": "...", "errors": {"field": ["..."]}}` (standard Laravel Form Request format).

Every route below carries a general rate limit of 120 requests/minute (per authenticated user, or per IP for a guest/unauthenticated caller), returning `429` once exceeded — added in Phase 16. Routes noted individually as "rate-limited" below carry an additional, tighter limit on top of that general one, for the endpoints most worth capping harder (auth, meeting-code guessing, chat spam). See [SECURITY.md](SECURITY.md).

## Authentication

### `POST /api/v1/auth/register`

Public, rate-limited (10/min, by IP — deliberately not the default user-id-or-IP key, since a successful registration authenticates the caller as a brand-new user, which would otherwise hand every successful attempt a fresh, never-throttled key; see BUG-011 in [BUGS.md](BUGS.md)). Creates a user, logs them in (session), and returns the user.

Request: `{"name", "email", "password", "password_confirmation"}`
Response: `201 {"data": {"id", "name", "email", "created_at", "updated_at"}}`

### `POST /api/v1/auth/login`

Public. Requires an `X-XSRF-TOKEN` header from a prior `GET /sanctum/csrf-cookie` call. Rate-limited to 5 attempts per email+IP combination (exponential lockout on repeated failures).

Request: `{"email", "password"}`
Response: `200 {"data": {...user}}`, or `422` on invalid credentials, lockout, or a disabled account (Phase 15 — `is_disabled`, same `{"errors": {"email": [...]}}` shape as bad credentials, distinguished only by the message text: "This account has been disabled.").

Every `auth:sanctum` route also re-checks `is_disabled` on each request (not just at login) via `EnsureUserIsNotDisabled` — a user disabled mid-session gets `403` and is logged out on their very next authenticated request, not only kept out of future logins.

### `POST /api/v1/auth/logout`

Requires `auth:sanctum`. Invalidates the session and regenerates the CSRF token.
Response: `204 No Content`.

## Profile

### `GET /api/v1/profile`

Requires `auth:sanctum`. Returns the current user.
Response: `200 {"data": {...user}}`.

### `PUT /api/v1/profile`

Requires `auth:sanctum`. Updates name/email, and optionally password (requires `current_password` to change it).

Request: `{"name", "email", "current_password"?, "password"?, "password_confirmation"?}`
Response: `200 {"data": {...user}}`.

## Meetings

All endpoints below require `auth:sanctum` and only ever operate on meetings hosted by the authenticated user (enforced by `MeetingPolicy`; a non-host gets `403`). Meetings are addressed by `meeting_uuid` in the URL, not the numeric id. A meeting's `password` is never returned — responses instead include a `has_password` boolean.

### `GET /api/v1/meetings`

Lists every meeting hosted by the authenticated user (all statuses), newest-scheduled-first.
Response: `200 {"data": [{...meeting}]}`.

### `POST /api/v1/meetings`

Creates a meeting. `meeting_uuid` and a unique `meeting_code` (e.g. `ABC-123-XYZ`) are generated server-side; the meeting starts in `scheduled` status.

Request: `{"title", "description"?, "password"?, "scheduled_at"?, "duration_minutes"?, "waiting_room_enabled"?, "allow_guests"?}` (`scheduled_at` omitted = instant meeting, started on demand via `/start`; `duration_minutes` is informational only, 1-1440, shown on the dashboard — nothing auto-ends the meeting when it elapses). Creating a meeting with a future `scheduled_at` dispatches `App\Events\MeetingScheduled` server-side (not broadcast over Reverb — a plain Laravel event); no listeners are registered on it yet, since the MVP ships no email/SMS notifications, but it's the extension point a future reminder feature would hook into without touching this controller. See [ARCHITECTURE.md](ARCHITECTURE.md#notifications).
Response: `201 {"data": {...meeting}}`.

### `GET /api/v1/meetings/{meeting}`

Response: `200 {"data": {...meeting}}`, or `403` if the authenticated user isn't the host.

### `GET /api/v1/meetings/{meeting}/participants`

The full participant roster of this meeting — every row ever created for it, any status, not just who's currently in the call (see [Meeting Controls](#meeting-controls-host)'s `/participants/waiting` for the currently-waiting subset). Used by the History view (Phase 13) to show who attended a past meeting and for how long; `duration_seconds` is the running total accumulated across every join/leave/reconnect cycle for that participant, added to (not overwritten by) each session — see [DATABASE.md](DATABASE.md#meeting_participants).

Response: `200 {"data": [{"id", "name", "role", "status", "joined_at", "left_at", "duration_seconds"}]}`, ordered by `joined_at` ascending (MySQL sorts `NULL` first, so anyone who never made it past the waiting room lists before everyone who actually joined, tie-broken by `created_at`), or `403` if the authenticated user isn't the host.

### `PUT /api/v1/meetings/{meeting}`

Edits a meeting. Only allowed while `status = scheduled`.

Request: same shape as create (all fields optional/`sometimes`).
Response: `200 {"data": {...meeting}}`, or `409` if the meeting isn't `scheduled`.

### `DELETE /api/v1/meetings/{meeting}`

Permanently deletes a meeting. Not allowed while `status = live` (end it first).
Response: `204 No Content`, or `409` if the meeting is live.

### `POST /api/v1/meetings/{meeting}/start`

Transitions `scheduled` → `live`, sets `started_at`.
Response: `200 {"data": {...meeting}}`, or `409` if not currently `scheduled`.

### `POST /api/v1/meetings/{meeting}/end`

Transitions `live` → `ended`, sets `ended_at`. Also finalizes every participant still `joined`/`admitted`/`waiting` at that moment — moves them to `left`, sets `left_at`, and (for anyone who was actually in the call) adds their final session to `duration_seconds` — so the meeting's history record is complete even for participants who never explicitly called `/leave` (Phase 13).
Response: `200 {"data": {...meeting}}`, or `409` if not currently `live`.

### `POST /api/v1/meetings/{meeting}/cancel`

Transitions `scheduled` → `cancelled`.
Response: `200 {"data": {...meeting}}`, or `409` if not currently `scheduled`.

## Meeting Controls (Host)

All endpoints below require `auth:sanctum` and are host-only (`MeetingPolicy::manageSettings`/`manageParticipants`; a non-host gets `403`). Meetings are addressed by `meeting_uuid`. Every mutating action broadcasts an `App\Events\HostControlEvent` on the meeting's Reverb channel as `.host.control` so connected clients react in real time — see [WEBRTC.md](WEBRTC.md#host-control-signaling).

### `POST /api/v1/meetings/{meeting}/lock` / `POST /api/v1/meetings/{meeting}/unlock`

Toggles `is_locked`. While locked, `POST .../join` rejects any non-host joiner with `403` (already-connected participants are unaffected).
Response: `200 {"data": {...meeting}}`.

### `POST /api/v1/meetings/{meeting}/guests/enable` / `POST /api/v1/meetings/{meeting}/guests/disable`

Toggles `allow_guests` live.
Response: `200 {"data": {...meeting}}`.

### `POST /api/v1/meetings/{meeting}/waiting-room/enable` / `POST /api/v1/meetings/{meeting}/waiting-room/disable`

Toggles `waiting_room_enabled` live — takes effect on the next `/join` call, does not affect anyone already `joined`/`admitted`.
Response: `200 {"data": {...meeting}}`.

### `GET /api/v1/meetings/{meeting}/participants/waiting`

Lists everyone currently `status: waiting` for this meeting, oldest first.
Response: `200 {"data": [{"id", "name", "role"}]}`.

### `POST /api/v1/meetings/{meeting}/participants/{participant}/admit`

Lets a waiting participant into the call: sets `status` to `admitted` and `admitted_at` to now, and broadcasts `action: admitted` (`target` = the participant's `presenceId()`) on `private-waiting-room.{meeting_uuid}` — see [WEBRTC.md](WEBRTC.md#waiting-room-signaling). `admitted_at` being set is also what lets this participant skip the waiting room on any future reconnect.
Response: `200 {"data": {...participant}}`, or `409` if the participant isn't currently `waiting`, or `404` if `{participant}` doesn't belong to `{meeting}`.

### `POST /api/v1/meetings/{meeting}/participants/{participant}/reject`

Turns away a waiting participant: sets `status` to `rejected` and `left_at` to now (never admitted, so a future `/join` attempt is blocked with `403` like any other rejected participant), and broadcasts `action: rejected` the same way `/admit` broadcasts `admitted`.
Response: `200 {"data": {...participant}}`, or `409` if the participant isn't currently `waiting`, or `404` if `{participant}` doesn't belong to `{meeting}`.

### `POST /api/v1/meetings/{meeting}/participants/{participant}/mute`

Force-mutes a participant currently in the call (broadcasts `action: mute` with the participant's `presenceId()` as `target`; the target's own client disables its mic).
Response: `200 {"data": {"muted": true}}`, or `409` if the participant isn't currently `joined`, or `404` if `{participant}` doesn't belong to `{meeting}`.

### `POST /api/v1/meetings/{meeting}/participants/{participant}/spotlight` / `POST /api/v1/meetings/{meeting}/spotlight/clear`

Forces every participant's Speaker view (Phase 20) to show this one as the main tile, overriding both active-speaker detection and any individual viewer's own local "Pin" (Pin is entirely client-side — see [WEBRTC.md](WEBRTC.md#active-speaker-detection) — so it isn't a server concept and has no endpoint). Broadcasts `action: spotlight` (`target` = the participant's `presenceId()`) or `action: spotlight-cleared` on `.host.control`, the same event type/channel host mute/remove/lock already use.
Response (`spotlight`): `200 {"data": {"spotlighted": true}}`, or `409` if the participant isn't currently `joined`, or `404` if `{participant}` doesn't belong to `{meeting}`.
Response (`spotlight/clear`): `200 {"data": {"spotlighted": false}}`.

### `POST /api/v1/meetings/{meeting}/participants/{participant}/remove`

Sets the participant's `status` to `removed` and disconnects them in real time.
Response: `200 {"data": {...participant}}`, or `409` if the target is the host or has already left/been removed, or `404` if `{participant}` doesn't belong to `{meeting}`.

## Joining a Meeting

These endpoints are public (no `auth:sanctum` required) so both authenticated users and guests can join. Meetings are addressed by `meeting_code` (the short human-friendly code, e.g. `ABC-123-XYZ`), not `meeting_uuid`.

### `GET /api/v1/meetings/lookup/{code}`

Public, rate-limited (30/min) — since this needs no `password`/`guest_name` to call, it's the cheapest endpoint to use for brute-force meeting-code guessing, so it carries its own limit rather than relying on the general one alone. Returns just enough for a join screen to render — no auth details.
Response: `200 {"data": {"meeting_code", "title", "host_name", "status", "has_password", "allow_guests"}}`, or `404` if the code doesn't exist.

### `POST /api/v1/meetings/{code}/join`

Public, rate-limited (20/min). Joins the meeting as the authenticated user, or as a guest if `allow_guests` is true and no session is present. The host joins their own meeting as `role: host` and always bypasses the password check and waiting room. Re-joining (an authenticated user who previously left, or a guest supplying their `guest_token`) updates their existing `meeting_participants` row rather than creating a new one.

If `waiting_room_enabled` is true, a non-host joiner gets `status: waiting` instead of `joined` — *unless* this exact participant has been admitted past the waiting room before (`admitted_at` is set on their row), in which case they skip straight to `joined` even on a fresh reconnect. This is also how a client responds to being admitted while waiting: it re-calls `/join` (now sending the `guest_token` it already holds, for guests) after receiving the real-time `admitted` notification — see [WEBRTC.md](WEBRTC.md#waiting-room-signaling).

Request: `{"password"?, "guest_name"?, "guest_token"?}` (`guest_name` required for guests when `allow_guests` is true; `guest_token`, from a previous `/join` response, lets a guest reconnect to their existing row instead of creating a new one).
Response: `200 {"data": {"participant": {...}, "meeting": {..., "waiting_room_enabled"}, "guest_token": string | null}}`. `guest_token` is a one-time, non-authenticated join token returned only to guests (never for signed-in users) — the frontend stores it client-side and must send it back on `/leave` and on any later `/join` call, since guests have no session to authenticate those.

Errors: `404` unknown code; `409` meeting is `ended`/`cancelled`; `422` wrong password or missing `guest_name` (standard validation-error shape); `403` guests disallowed, or the requester was previously `removed`/`rejected` from this meeting.

### `POST /api/v1/meetings/{code}/leave`

Public, rate-limited (30/min). Authenticated users are matched by session; guests must pass `{"guest_token": "..."}` (the value returned from `/join`). Sets the participant's `status` to `left` and records `left_at`.
Response: `200 {"data": {...participant}}`, or `404` if no matching participant/guest-token is found.

## Chat

Public (no `auth:sanctum` required, so guests can participate), but every endpoint requires the caller to currently be an active (`joined`/`admitted`) participant of the meeting — the same rule `routes/channels.php` uses to authorize the Reverb channel. Authenticated users are matched by session; guests must pass `guest_token` (the value returned from `/join`) as a query param on `GET` or a body field on `POST`. Meetings are addressed by `meeting_code`. Sending a message broadcasts `App\Events\ChatMessageEvent` on the meeting's Reverb channel as `.chat.message`; messages are persisted (`meeting_messages` table) and never pre-rendered as HTML server-side — escaping for safe display is the frontend's job (Vue's default `{{ }}` interpolation), not the API's.

### `GET /api/v1/meetings/{code}/messages`

Rate-limited (60/min). Returns the full message history in chronological order.
Response: `200 {"data": [{"id", "message", "sender_name", "sender_role", "presence_id", "created_at"}]}`, or `403` if the caller isn't currently an active participant, or `404` for an unknown code.

### `POST /api/v1/meetings/{code}/messages`

Rate-limited (30/min). Sends a message; `message` is required, trimmed server-side, and capped at 2000 characters (whitespace-only is rejected as empty).

Request: `{"message", "guest_token"?}`
Response: `201 {"data": {...message}}` (same shape as a list entry), `422` for an empty/oversized message, `403` if the caller isn't currently an active participant, or `404` for an unknown code.

## WebRTC Signaling

### `POST /broadcasting/auth`

Not under `/api/v1` — this is Laravel's standard Reverb/Pusher-protocol channel authorization endpoint (`routes/channels.php`), called automatically by Laravel Echo before subscribing to `private-meeting.{meeting_uuid}` or `private-waiting-room.{meeting_uuid}`. Authorizes an authenticated user via their session, or a guest via an `X-Guest-Token` header (the token returned by `/join`) — see [SECURITY.md](SECURITY.md) and [WEBRTC.md](WEBRTC.md#guest-signaling-auth). For `meeting.{uuid}`, returns `403` unless the requester is an active (`joined`/`admitted`) participant of that specific meeting; `waiting-room.{uuid}` is deliberately less restrictive — any participant of the meeting may subscribe, regardless of status, since its whole purpose is reaching participants who are `waiting` and therefore not yet allowed onto the main channel.

Once subscribed, offer/answer/ICE-candidate exchange and the participant roster (`participant.announce`/`participant.bye`) travel as client whisper events on this channel — Reverb relays them directly, no further `/api/v1` calls involved. Full protocol: [WEBRTC.md](WEBRTC.md#signaling-sequence-per-new-peer-pair).

## Admin

All endpoints below require `auth:sanctum` **and** `is_admin: true` on the authenticated user — enforced by a single `EnsureUserIsAdmin` middleware wrapping the whole `/admin` route group, rather than a per-method authorization check, so every route under it is covered by construction (see [ROADMAP.md](ROADMAP.md) Phase 15: "Server-side authorization on every admin endpoint"). A non-admin gets `403`; a guest gets `401`. There is no in-app way to grant admin access — the first admin (and any afterward) is granted via `php artisan admin:grant {email}` on the server, deliberately kept out of the API surface (see [DEPLOYMENT.md](DEPLOYMENT.md)).

### `GET /api/v1/admin/stats`

System-wide counts for a dashboard view: users (total/admins/disabled), meetings (total, and per-status), and total participants ever recorded.
Response: `200 {"data": {"users": {"total", "admins", "disabled"}, "meetings": {"total", "scheduled", "live", "ended", "cancelled"}, "participants_total"}}`.

### `GET /api/v1/admin/users`

Every user, newest-registered-first, with a `meetings_count` (as host) alongside the normal user fields.
Response: `200 {"data": [{...user, "meetings_count"}]}`.

### `POST /api/v1/admin/users/{user}/disable` / `POST /api/v1/admin/users/{user}/enable`

Toggles `is_disabled`. A disabled user is blocked from logging in (`422`, see [Authentication](#post-apiv1authlogin)) and, if already signed in, is signed out and blocked (`403`) on their very next authenticated request.
Response: `200 {"data": {...user}}`, or `409` if an admin targets their own account via `/disable` (self-lockout guard, same pattern as a host being unable to remove themselves from their own meeting).

### `GET /api/v1/admin/meetings`

Every meeting across every host, newest-created-first, each with its `host` (id/name/email) eager-loaded. Unlike `GET /meetings`, this is not scoped to the authenticated user.
Response: `200 {"data": [{...meeting, "host": {"id", "name", "email"}}]}`.

### `GET /api/v1/admin/meetings/{meeting}/participants`

The admin equivalent of the host-only `GET /meetings/{meeting}/participants`, for any meeting regardless of host — same response shape, same underlying `Meeting::participantHistory()` helper.
Response: `200 {"data": [{"id", "name", "role", "status", "joined_at", "left_at", "duration_seconds"}]}`.

### `DELETE /api/v1/admin/meetings/{meeting}`

Deletes any meeting regardless of host, with the same live-meeting guard as the host's own `DELETE /meetings/{meeting}`.
Response: `204 No Content`, or `409` if the meeting is live.

## Misc

### `GET /api/v1/health`

Public. `200 {"status": "ok"}`. Used for container/infra health checks (see [DEPLOYMENT.md](DEPLOYMENT.md)).
