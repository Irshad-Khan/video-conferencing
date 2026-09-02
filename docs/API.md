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

## Misc

### `GET /api/v1/health`

Public. `200 {"status": "ok"}`. Used for container/infra health checks (see [DEPLOYMENT.md](DEPLOYMENT.md)).

---

Meeting management, join flow, chat, and admin endpoints are documented here as they're implemented in later phases (see [ROADMAP.md](ROADMAP.md) §11).
