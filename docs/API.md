# API.md

Versioned REST API under `/api/v1`. Auth is Sanctum SPA cookie-session auth for the first-party web app (CSRF-protected via `withXSRFToken`); the same `auth:sanctum` guard will accept Sanctum personal-access tokens for future mobile clients without any route changes.

All responses are JSON. Successful single-resource responses are wrapped as `{"data": {...}}`. Validation failures return `422` with `{"message": "...", "errors": {"field": ["..."]}}` (standard Laravel Form Request format).

## Authentication

### `POST /api/v1/auth/register`

Public. Creates a user, logs them in (session), and returns the user.

Request: `{"name", "email", "password", "password_confirmation"}`
Response: `201 {"data": {"id", "name", "email", "created_at", "updated_at"}}`

### `POST /api/v1/auth/login`

Public. Requires an `X-XSRF-TOKEN` header from a prior `GET /sanctum/csrf-cookie` call. Rate-limited to 5 attempts per email+IP combination (exponential lockout on repeated failures).

Request: `{"email", "password"}`
Response: `200 {"data": {...user}}`, or `422` on invalid credentials / lockout.

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

Request: `{"title", "description"?, "password"?, "scheduled_at"?, "waiting_room_enabled"?, "allow_guests"?}` (`scheduled_at` omitted = instant meeting, started on demand via `/start`).
Response: `201 {"data": {...meeting}}`.

### `GET /api/v1/meetings/{meeting}`

Response: `200 {"data": {...meeting}}`, or `403` if the authenticated user isn't the host.

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

Transitions `live` → `ended`, sets `ended_at`.
Response: `200 {"data": {...meeting}}`, or `409` if not currently `live`.

### `POST /api/v1/meetings/{meeting}/cancel`

Transitions `scheduled` → `cancelled`.
Response: `200 {"data": {...meeting}}`, or `409` if not currently `scheduled`.

## Misc

### `GET /api/v1/health`

Public. `200 {"status": "ok"}`. Used for container/infra health checks (see [DEPLOYMENT.md](DEPLOYMENT.md)).

---

Join flow, chat, and admin endpoints are documented here as they're implemented in later phases (see [ROADMAP.md](ROADMAP.md) §11).
