# SECURITY.md

## Principle

Never trust client-supplied `user_id`, `role`, `host_id`, `meeting_id`, or `permissions`. Every authorization decision is re-derived server-side from the authenticated session/token and the database state.

## Authentication

- Sanctum cookie-session auth for the first-party SPA (CSRF-protected via Laravel's standard `VerifyCsrfToken` + Sanctum's stateful-domain check); Sanctum bearer tokens available for future mobile clients on the same `/api/v1` routes.
- Passwords hashed with bcrypt (Laravel default), never logged or returned in responses.
- Guests get a short-lived, signed, meeting-scoped token (not a full user session) — cannot access any route outside that meeting.
- An admin-disabled user (`is_disabled`, Phase 15) is blocked at login and, via `EnsureUserIsNotDisabled` on every `auth:sanctum` route, also cut off mid-session — an already-authenticated session doesn't stay valid just because it predates the admin's disable action.

## Authorization

- Laravel Policies/Gates for every resource: `MeetingPolicy` (view/update/delete/manageLifecycle [start/end] — host-only, never extends to a co-host), participant/settings actions (mute/remove/spotlight/lock/guests/waiting-room-toggle — host **or** promoted co-host, since Phase 22). Appointing/revoking a co-host itself (`manageCoHosts`) stays strictly host-only, on purpose — a co-host cannot appoint another co-host, the same restriction Zoom applies, and is checked with a real DB lookup (`meeting_participants.role = 'co_host'` for that user on that meeting), not a client-asserted role. Admin actions (Phase 15) use a group-level `EnsureUserIsAdmin` middleware over the whole `/admin/*` route group instead of a per-method Gate call — a flat role check, not a per-resource ownership check, so wrapping the group guarantees every admin route is covered rather than depending on each controller method remembering to check.
- Reverb private channel authorization (`routes/channels.php`) checks the requesting user/guest is an active (`admitted`/`joined`) participant of that specific meeting before allowing a subscribe — re-checked, not cached from connect time, for control events like mute/remove. Guests authenticate this one request via their `X-Guest-Token` header (resolved to their `meeting_participants` row by `AuthenticateBroadcastGuest`, since they have no Sanctum session); a `left`/`removed`/`rejected` guest's token stops authorizing immediately.
- Host-only WebSocket-triggered actions (mute participant, remove participant, end meeting, lock, spotlight a participant) are validated against the DB role, never against a client-sent "I am host" flag — this is why Spotlight (Phase 21) is a server-validated `POST` + broadcast, not a raw client whisper, unlike Pin (a same-named but purely local, per-viewer concept with no server involvement at all — see [WEBRTC.md](WEBRTC.md#active-speaker-detection)).

## Input Validation

- Every mutating endpoint uses a Laravel Form Request with explicit validation rules (required fields, string lengths, enum values for `role`/`status`, etc.).
- Meeting passwords: validated for presence/length on set, bcrypt-hashed at rest, checked via `Hash::check`, never included in any API response payload (hidden via `$hidden` on the model).

## Web-Layer Protections

- CSRF: Laravel's default protection on stateful (cookie-session) routes.
- XSS: Vue's default text interpolation escapes output; chat messages are never rendered via `v-html`. The API stores and returns message text verbatim (see [DATABASE.md](DATABASE.md#meeting_messages)) rather than stripping/escaping server-side — escaping belongs at the render boundary, and stripping tags server-side would corrupt legitimate message content (e.g. `5 < 10`) without adding any protection Vue's interpolation doesn't already provide.
- SQL injection: Eloquent/query builder parameter binding exclusively — no raw string-interpolated queries.
- CORS: no `config/cors.php` override, so `allowed_origins` stays the framework default (`*`) with `supports_credentials: false` (also the default). This is a deliberate combination, not an oversight: a wide-open origin list supports the "mobile clients hit the same `/api/v1`" architecture goal (§13, token-authenticated, no cookies involved), while `supports_credentials: false` means the browser never attaches or exposes the first-party SPA's session cookie on a cross-origin request regardless of origin — so opening `allowed_origins` doesn't expose cookie-authenticated session data to another site. `SameSite=Lax` on the session cookie (Laravel default, see [Transport](#transport)) is a second, independent layer of the same protection.
- Rate limiting (audited and closed up in Phase 16 — see below): every route in `routes/api.php` carries a general 120/min-per-user-or-IP ceiling (`RateLimiter::for('api', ...)`, wired via `$middleware->throttleApi()`), with tighter route-specific limits layered on top for the more sensitive ones — login (5 attempts per email+IP, application-level, see `LoginRequest`), registration (10/min **by IP only**, see below), meeting join (20/min), meeting-code lookup (30/min), leaving a meeting (30/min), chat message history (60/min) and sending (30/min).

## Transport

- Production: HTTPS terminated at the reverse proxy (see [DEPLOYMENT.md](DEPLOYMENT.md)); WebRTC media itself is SRTP-encrypted by the browser regardless.
- Reverb connections upgraded over WSS in production.
- Session/CSRF cookies: `HttpOnly` (Laravel default, not readable from JS), `SameSite=Lax` (Laravel default — blocks the cookie from being attached to a cross-site fetch/XHR at all, ahead of and independent of CSRF-token validation), `Secure` in production via `SESSION_SECURE_COOKIE=true` (unset in `.env.example` since local dev runs over plain HTTP — see [DEPLOYMENT.md](DEPLOYMENT.md)).

## File Handling

Not applicable to the MVP (no file uploads planned); if added later (e.g. avatars, recordings), uploads will be validated by MIME/type/size, stored outside the public webroot where possible, and served via signed URLs — documented here before implementation, per the project's "document before building" rule.

## Review Cadence

Security is revisited explicitly in Phase 16 (Security Hardening) but the baseline above applies from Phase 1 onward — it is not deferred to the end.

## Phase 16 Audit (2026-09-03)

A systematic pass against the four areas named in `ROADMAP.md`'s Phase 16 checklist. One real gap was found and fixed; everything else was reviewed and confirmed already solid from how each feature was built phase-by-phase.

**Rate limiting, CSRF, XSS, SQLi — reviewed:**
- **Rate limiting: real gap, fixed.** Before this pass, `bootstrap/app.php` called `$middleware->statefulApi()` but never `$middleware->throttleApi()`, so the `api` middleware group carried no throttle at all — only the handful of routes with an explicit `->middleware('throttle:N,1')` (join, chat send) had any limit. Every other route, including `/auth/register`, `/meetings/lookup/{code}`, `/meetings/{code}/leave`, chat history, and the *entire* `auth:sanctum` group (all of meeting CRUD, host controls, and the whole admin panel), was completely unthrottled. Fixed with a general `RateLimiter::for('api', ...)` (120/min, keyed by user id or IP) plus targeted tighter limits on the routes most worth capping harder — see the [Web-Layer Protections](#web-layer-protections) list above for the full breakdown. Caught and fixed a second issue while adding this: a naive `throttle:10,1` on `/auth/register` used Laravel's default key (user id if authenticated, else IP) — but a *successful* registration authenticates the caller as the brand-new user, so every successful attempt got a fresh, never-before-seen key, silently defeating the cap entirely (an 11th rapid registration in a test still succeeded). Fixed by defining a dedicated `register` limiter keyed by IP only, verified with a test that fires 11 registrations in a row and asserts the 11th is `429`.
- **CSRF: reviewed, solid.** No `$except` list, no `withoutMiddleware` bypass anywhere; standard Sanctum stateful-domain cookie + CSRF-token flow, unchanged since Phase 2.
- **XSS: reviewed, solid.** `grep -rn 'v-html\|innerHTML' resources/js/` returns nothing — every dynamic render goes through Vue's default escaping interpolation, chat included (live-verified with an `<img onerror>` payload back in Phase 9).
- **SQLi: reviewed, solid.** `grep -rn 'DB::raw\|whereRaw\|selectRaw\|DB::statement'  app/` returns nothing — Eloquent/query-builder parameter binding used exclusively across all 15 prior phases.

**Server-side validation audit of every client-supplied id/role/permission — reviewed, solid:**
- Every nested `{meeting}/{participant}` host-control route (`mute`/`remove`/`admit`/`reject`) checks `abort_if($participant->meeting_id !== $meeting->id, 404)`, closing the obvious IDOR of targeting a real participant row that belongs to a *different* meeting (tested since Phase 11: "a waiting participant from another meeting cannot be targeted").
- `role` is never read from client input for mass assignment anywhere — every place a `MeetingParticipant` gets a `role`, it's computed server-side from `$isHost`/session state, never `$request->input('role')`. Verified by grepping every `::create(`/`->fill(`/`->update(` call site in `app/Http/Controllers` and confirming each one passes either an explicit hardcoded/server-derived array or a `FormRequest::validated()`/`safe()->only([...])` result whose rule set never includes a privilege field (`role`, `status`, `host_user_id`, `is_admin`, `is_disabled`) — a payload can't smuggle a field through mass assignment that the corresponding Form Request doesn't itself validate.
- `Meeting`/`MeetingParticipant`/`User`'s `#[Fillable(...)]` lists were cross-checked against their own Form Requests' `rules()` and found to match exactly — no Fillable field exists that isn't also gated by validation.
- `GET /meetings/{meeting}` and friends rely on `#[RouteKey('meeting_uuid')]` implicit binding — supplying a numeric id instead of the UUID doesn't leak a different meeting; it just 404s, since the lookup column is `meeting_uuid` specifically.

**Reverb channel auth — reviewed, solid:** already covered in [Authorization](#authorization) above; re-read `routes/channels.php` line by line during this pass and found no change needed — both channel closures re-derive participant status from the database on every subscribe attempt (not cached from an earlier check), and a guest's token stops resolving to anything the moment their row moves to `left`/`removed`/`rejected` (`AuthenticateBroadcastGuest`'s `whereNotIn`).

**Meeting password hashing audit — reviewed, solid:** `Meeting`'s `password` cast is `'hashed'` (bcrypt via Laravel's cast, not a manual `Hash::make` call that could be forgotten at some call site), `#[Hidden(['password'])]` is a class-level attribute so it's masked in *every* serialization path including the newer admin listing (`AdminController::meetings()`) without needing a separate check there, and `Hash::check()` is the only place the raw stored value is ever read. Grepped for `getRawOriginal`/`->password` across `app/` and `resources/js/` and found no stray access path.

**Also found and fixed while auditing Transport/CORS (adjacent to the checklist, not one of its four bullets, but a real production-hardening gap):** `SESSION_SECURE_COOKIE` was never set or even mentioned in `.env.example` or `DEPLOYMENT.md`'s production checklist — meaning a real deployment following the existing docs literally would leave session/CSRF cookies without the `Secure` flag. Documented in both places now (see [Transport](#transport)).
