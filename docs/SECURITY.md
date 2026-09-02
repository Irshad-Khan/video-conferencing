# SECURITY.md

## Principle

Never trust client-supplied `user_id`, `role`, `host_id`, `meeting_id`, or `permissions`. Every authorization decision is re-derived server-side from the authenticated session/token and the database state.

## Authentication

- Sanctum cookie-session auth for the first-party SPA (CSRF-protected via Laravel's standard `VerifyCsrfToken` + Sanctum's stateful-domain check); Sanctum bearer tokens available for future mobile clients on the same `/api/v1` routes.
- Passwords hashed with bcrypt (Laravel default), never logged or returned in responses.
- Guests get a short-lived, signed, meeting-scoped token (not a full user session) — cannot access any route outside that meeting.

## Authorization

- Laravel Policies/Gates for every resource: `MeetingPolicy` (view/update/delete/start/end — host or co-host only), participant actions (mute/remove — host/co-host only), admin actions (`is_admin` guard).
- Reverb private channel authorization (`routes/channels.php`) checks the requesting user/guest is an active (`admitted`/`joined`) participant of that specific meeting before allowing a subscribe — re-checked, not cached from connect time, for control events like mute/remove. Guests authenticate this one request via their `X-Guest-Token` header (resolved to their `meeting_participants` row by `AuthenticateBroadcastGuest`, since they have no Sanctum session); a `left`/`removed`/`rejected` guest's token stops authorizing immediately.
- Host-only WebSocket-triggered actions (mute participant, remove participant, end meeting, lock) are validated against the DB role, never against a client-sent "I am host" flag.

## Input Validation

- Every mutating endpoint uses a Laravel Form Request with explicit validation rules (required fields, string lengths, enum values for `role`/`status`, etc.).
- Meeting passwords: validated for presence/length on set, bcrypt-hashed at rest, checked via `Hash::check`, never included in any API response payload (hidden via `$hidden` on the model).

## Web-Layer Protections

- CSRF: Laravel's default protection on stateful (cookie-session) routes.
- XSS: Vue's default text interpolation escapes output; chat messages are never rendered via `v-html`. Server additionally strips/escapes on store as defense in depth.
- SQL injection: Eloquent/query builder parameter binding exclusively — no raw string-interpolated queries.
- Rate limiting: Laravel's throttle middleware on `/api/v1/auth/*` (login/register) and on meeting-join endpoints to slow brute-force meeting-code/password guessing.

## Transport

- Production: HTTPS terminated at the reverse proxy (see [DEPLOYMENT.md](DEPLOYMENT.md)); WebRTC media itself is SRTP-encrypted by the browser regardless.
- Reverb connections upgraded over WSS in production.

## File Handling

Not applicable to the MVP (no file uploads planned); if added later (e.g. avatars, recordings), uploads will be validated by MIME/type/size, stored outside the public webroot where possible, and served via signed URLs — documented here before implementation, per the project's "document before building" rule.

## Review Cadence

Security is revisited explicitly in Phase 16 (Security Hardening) but the baseline above applies from Phase 1 onward — it is not deferred to the end.
