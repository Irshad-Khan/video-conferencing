# ROADMAP — Self-Hosted Video Meeting Platform

Status legend: `[ ]` not started/in progress, `[x] ... — DONE` fully implemented, tested, and manually verified.
A box is only checked after: implementation complete, automated tests pass, manual testing done where applicable, no known blocking issue remains.

---

## 1. Project Overview

A self-hosted, web-based video conferencing platform (Zoom-like) built entirely on open-source technology, with no paid/proprietary video APIs. Phase 1 ships a web application; the backend is a versioned REST API (`/api/v1`) so Android/iOS/desktop clients can be added later without backend changes.

Non-negotiables:
- No Agora / Twilio Video / Daily / Zoom SDK / Vonage / 100ms / Cloudflare Calls / paid TURN.
- WebRTC for media, self-hosted WebSocket signaling.
- Everything runs via Docker; no dependency on locally installed PHP/MySQL/Node.
- Architecture must not require a backend rewrite to add mobile clients.

---

## 2. Technology Stack

| Layer | Choice | Why |
|---|---|---|
| Backend framework | Laravel 11 (PHP 8.3) | Mature, batteries-included, first-class Sanctum + Reverb + broadcasting support |
| Database | MySQL 8 | Relational data (users, meetings, participants, messages) fits well |
| Cache / queue / broadcast driver | Redis 7 | Backs Laravel queues, cache, and Reverb's scaling layer |
| API auth | Laravel Sanctum | Token auth for SPA + future mobile clients, same backend |
| Frontend | Vue 3 + Vite + TypeScript (practical subset) | Reactive UI, fast HMR, integrates natively with Laravel's Vite plugin |
| Signaling transport | Laravel Reverb (WebSocket server) | First-party, self-hosted, no external pusher-compatible SaaS needed |
| Real-time media | WebRTC (browser-native) | No paid SFU/API; P2P mesh for MVP |
| STUN/TURN | coturn (self-hosted, documented in [DEPLOYMENT.md](DEPLOYMENT.md)) | NAT traversal without a paid TURN provider |
| Web server | Nginx | Serves PHP-FPM app + static assets in Docker |
| Containerization | Docker + Docker Compose | Reproducible dev & prod environments |

Rejected/deferred: LiveKit, mediasoup, Jitsi Videobridge, Janus — evaluated as future SFU options once P2P mesh scaling limits are hit (see §5 and §16).

---

## 3. System Architecture

```
                        ┌─────────────────────┐
                        │      Browser         │
                        │  Vue 3 SPA (Vite)    │
                        └─────────┬────────────┘
                     REST (Sanctum)│  WebSocket (Reverb)   WebRTC (P2P)
                                  │                         media only
                        ┌─────────▼────────────┐
                        │       Nginx           │
                        └──┬─────────────┬──────┘
                           │             │
                 ┌─────────▼───┐   ┌─────▼─────────┐
                 │ Laravel app  │   │ Laravel Reverb │
                 │ (PHP-FPM)    │   │ (WS signaling) │
                 └───┬──────┬───┘   └───────┬────────┘
                     │      │               │
              ┌──────▼─┐  ┌─▼──────┐   ┌────▼───┐
              │ MySQL  │  │ Redis  │   │ Redis   │ (shared, pub/sub scaling)
              └────────┘  └────────┘   └─────────┘
```

Media (audio/video/screen share) never transits the app servers — only signaling (SDP offers/answers, ICE candidates, presence, chat, control events) goes over WebSocket. Actual media flows browser-to-browser via WebRTC, using STUN (and TURN when direct connectivity fails).

---

## 4. Database Architecture

Full schema detail: [DATABASE.md](DATABASE.md). Core tables:

- `users` — auth identity (Laravel default + profile fields)
- `meetings` — id, meeting_uuid, meeting_code, host_user_id, title, description, password (hashed), status, scheduled_at, started_at, ended_at, waiting_room_enabled, allow_guests, is_locked, timestamps
- `meeting_participants` — id, meeting_id, user_id (nullable), guest_name (nullable), role, status, joined_at, left_at
- `meeting_messages` — id, meeting_id, user_id, message, created_at
- `personal_access_tokens` — Sanctum tokens

---

## 5. WebRTC Architecture

Full detail: [WEBRTC.md](WEBRTC.md).

- MVP uses a **P2P mesh**: every participant opens a direct `RTCPeerConnection` to every other participant. Practical target: 2–6 participants (documented, tested at each count in Phase 6).
- Signaling (offer/answer/ICE) travels over the Reverb WebSocket channel scoped to the meeting.
- STUN required for NAT traversal in all cases; TURN (coturn) required whenever direct/STUN connectivity fails (symmetric NAT, restrictive firewalls) — mandatory for real-world production use.
- **SFU migration trigger**: once manual/load testing shows P2P mesh CPU/bandwidth degrading meetings beyond ~6 participants (mesh bandwidth grows O(n²)), evaluate mediasoup or LiveKit self-hosted as documented in §16 of this roadmap. This is explicitly out of scope for the MVP.

---

## 6. Signaling Architecture

- Laravel Reverb private channels, one per meeting: `private-meeting.{meeting_uuid}`.
- Channel authorization checked server-side against `meeting_participants` (must be an active, non-removed participant) — never trust a client-asserted meeting/user id.
- Signaling event types: `participant.joined`, `participant.left`, `webrtc.offer`, `webrtc.answer`, `webrtc.ice-candidate`, `media.state-changed` (mic/cam), `chat.message`, `host.control` (mute/remove/lock/end).

---

## 7. Authentication Architecture

- Laravel Sanctum, SPA cookie-based session auth for the first-party web app (CSRF-protected), with the same Sanctum guard exposing token auth for future mobile clients hitting `/api/v1`.
- Guests (no account) may join a meeting via `guest_name` only if the meeting allows guests — guests never get a Sanctum session, only a scoped, short-lived signed participant token for that meeting.

---

## 8. API Architecture

Versioned REST under `/api/v1`, documented in [API.md](API.md) (created alongside Phase 2/3 implementation). Mobile apps consume the identical API — no separate backend.

---

## 9. Docker Architecture

- `docker/php/Dockerfile` — PHP 8.3-FPM image with required extensions (pdo_mysql, redis, bcmath, pcntl, sockets, gd), Composer installed. Shared by the `app`, `queue`, and `reverb` services (same image, different command).
- `docker/nginx/default.conf` — serves `public/`, proxies `.php` to `app:9000`.
- Services: `app` (PHP-FPM), `nginx`, `mysql`, `redis`, `reverb`, `vite` (dev-only HMR server), `queue` (queue worker, added when queued jobs are introduced).
- Named volume `mysql_data` for persistence. Bind mount of the repo into `app`/`reverb`/`vite` for live code editing in development.
- `docker compose up -d` + `docker compose exec app php artisan migrate` is the full bootstrap, per [README.md](../README.md).

---

## 10. Security Architecture

Full detail: [SECURITY.md](SECURITY.md). Baseline principles applied from Phase 1 onward:
- All authorization (host/co-host/participant/guest/admin) is enforced server-side via Laravel Policies/Gates — the frontend UI state is never trusted.
- Meeting passwords hashed (bcrypt), never returned in API responses.
- Rate limiting on auth and join endpoints.
- Input validation via Form Requests on every mutating endpoint.
- Reverb channel auth checks participant membership per-event, not just at connect time.

---

## 11. Development Phases

### Phase 1 — Project Foundation
- [x] Docker environment — DONE (all 6 services build and reach `Up`/`healthy`: app, nginx, mysql, redis, reverb, vite)
- [x] Laravel application — DONE (Laravel 13 / PHP 8.4, boots and serves requests through nginx)
- [x] Vue 3 frontend — DONE (`resources/js/App.vue` mounts at `#app`, verified rendering in a real browser)
- [x] Vite — DONE (dev server serves TS/Vue with HMR on the mapped `VITE_PORT`)
- [x] MySQL — DONE (migrations run against it; `migrate:status` confirms)
- [x] Redis — DONE (read/write round-trip verified via `artisan tinker`)
- [x] WebSocket infrastructure (Reverb) — DONE (`reverb:start` process stable, port accepts connections; full signaling exchange not yet exercised — that's Phase 5)
- [x] Environment configuration — DONE (`.env.example` documents all vars incl. Docker host ports, DB, Redis, Reverb, Sanctum, TURN placeholders)
- [x] Health check endpoint — DONE (`GET /api/v1/health` returns `{"status":"ok"}`, covered by `tests/Feature/HealthCheckTest.php`)
- [x] Basic frontend layout — DONE (header + status page, confirmed via screenshot)
- [x] Basic backend API structure — DONE (`/api/v1` prefix established in `routes/api.php`, Sanctum wired for future auth)

### Phase 2 — Authentication
- [x] Registration, login, logout — DONE (`/api/v1/auth/register`, `/auth/login`, `/auth/logout`; see [API.md](API.md))
- [x] Password hashing, validation — DONE (bcrypt via `Hash::make`, `Password::defaults()` rule, password confirmation)
- [x] Sanctum session/token auth — DONE (SPA cookie-session via `statefulApi()` + `EnsureFrontendRequestsAreStateful`; token auth path kept open for future mobile clients)
- [x] Auth middleware — DONE (`auth:sanctum` on `/auth/logout` and `/profile`; login rate-limited 5/email+IP with exponential lockout)
- [x] User profile — DONE (`GET`/`PUT /api/v1/profile`, password change requires `current_password`)
- [x] Frontend: login, register, dashboard, logout, profile pages — DONE (Vue Router with `requiresAuth`/`guestOnly` route guards; verified end-to-end in a real headless-Chrome session)
- [x] Tests: registration, login, security (unauthenticated/expired/unauthorized access) — DONE (21 automated Feature tests: registration, login incl. rate limiting, logout, profile view/update/authorization boundaries — see [TESTING.md](TESTING.md))

### Phase 3 — Meeting Management
- [x] `meetings` table + model — DONE (`Meeting` model with `host()` relation, attribute-based fillable/hidden/route-key/policy config)
- [x] Create/edit/delete/cancel meeting — DONE (`/api/v1/meetings` CRUD + `/cancel`; edit/delete guarded by meeting status, see [API.md](API.md))
- [x] Unique meeting code generation — DONE (`Meeting::generateUniqueCode()`, retry-on-collision, verified under a forced collision)
- [x] Optional meeting password — DONE (bcrypt via the same `hashed` cast used on `User::password`; never returned, `has_password` exposed instead)
- [x] Scheduling fields — DONE (`scheduled_at`; omitted = instant meeting)
- [x] Start/end meeting lifecycle — DONE (`/start`, `/end`; invalid transitions rejected with 409)
- [x] Dashboard: My/Upcoming/Live/Past meetings — DONE (`Dashboard.vue` groups the host's meetings into Live/Upcoming/Past sections; verified end-to-end in a real headless-Chrome session)
- [x] Tests: create, duplicate code prevention, unauthorized edit/delete, host permissions, lifecycle — DONE (23 automated Feature tests across `tests/Feature/Meetings/{MeetingCreation,MeetingAuthorization,MeetingLifecycle,MeetingList}Test.php`)

### Phase 4 — Join Meeting
- [ ] Public join URL (`/meeting/{code}`)
- [ ] Meeting existence + password check
- [ ] Guest join support (if allowed)
- [ ] `meeting_participants` table + roles (host/co_host/participant/guest)
- [ ] Waiting room hook (full feature in Phase 11)
- [ ] Tests: valid/invalid/password-protected/unauthorized meeting, host/participant/guest join, leave, rejoin

### Phase 5 — WebRTC Audio/Video (core)
- [ ] Camera/mic access, local preview
- [ ] Reverb signaling channel per meeting
- [ ] Offer/answer/ICE exchange
- [ ] Peer connection lifecycle + connection state UI
- [ ] Mute/unmute, camera on/off (client-side toggle of local tracks)
- [ ] Manual test: 2-browser call works end-to-end

### Phase 6 — Multi-Participant Video
- [ ] P2P mesh for 2–6 participants
- [ ] Video tile grid (responsive: 1/2/2x2/grid)
- [ ] Per-tile name, mic/camera/connection status
- [ ] Manual test at 2, 3, 4, 5, 6 participants + documented performance notes

### Phase 7 — Meeting Controls
- [ ] Participant: mute, camera off, leave
- [ ] Host: mute participant, remove participant, end meeting, lock meeting, allow/disallow guests
- [ ] Server-side authorization for every host action
- [ ] Tests: participant cannot call host endpoints, removed participant is disconnected, lock/end work

### Phase 8 — Screen Sharing
- [ ] `getDisplayMedia` start/stop
- [ ] Switch between camera and screen track
- [ ] Camera restoration after stop
- [ ] Tests: start/stop, remote receipt, permission-denied handling, unsupported-browser fallback

### Phase 9 — Real-Time Chat
- [ ] `meeting_messages` table
- [ ] Send/receive over Reverb, persisted
- [ ] XSS-safe rendering
- [ ] Tests: send/receive, empty message rejected, unauthorized user blocked, persistence

### Phase 10 — Participants Panel
- [ ] List with mic/camera/host indicators
- [ ] Live participant count
- [ ] Host controls inline

### Phase 11 — Waiting Room
- [ ] Per-meeting toggle
- [ ] Host admit/reject UI + API
- [ ] Tests: enabled/disabled, admit, reject, reconnect

### Phase 12 — Scheduling
- [ ] Date/time/duration/title/password/waiting-room/guest settings on create
- [ ] Dashboard grouping: Today/Tomorrow/Upcoming/Past
- [ ] Notification architecture left extensible; no email/SMS in MVP

### Phase 13 — Meeting History
- [ ] Persisted join/leave/duration per participant
- [ ] History view: meeting/date/duration/participants/status

### Phase 14 — Recording (design-first)
- [ ] Architecture decision documented (browser MediaRecorder vs. server-side/SFU) before any implementation
- [ ] Not implemented until WebRTC MVP (Phases 5–8) is stable

### Phase 15 — Admin Panel
- [ ] Users, meetings, active meetings, history, participants views
- [ ] Disable user, delete meeting
- [ ] System stats dashboard
- [ ] Server-side authorization on every admin endpoint

### Phase 16 — Security Hardening
- [ ] Rate limiting, CSRF, XSS, SQLi protection review
- [ ] Server-side validation audit of every client-supplied id/role/permission
- [ ] Secure Reverb channel auth
- [ ] Meeting password hashing audit

### Phase 17 — Testing
- [ ] Unit/Feature/Browser test suites for auth, meetings, permissions, chat, participants
- [ ] Manual WebRTC test procedures ([TESTING.md](TESTING.md))

---

## 12. Testing Strategy

See [TESTING.md](TESTING.md) for the full manual E2E procedure and the automated test matrix. Summary: PHPUnit/Pest Feature tests for every API endpoint and permission boundary; manual multi-browser testing for anything WebRTC-dependent (browsers don't reliably fake camera/mic in CI without extra tooling, so real-media flows are manually verified per phase and logged in TESTING.md).

---

## 13. Future Mobile-App Architecture

```
                 Laravel API (/api/v1)
                     │
        ┌────────────┼────────────┐
        │            │            │
        ▼            ▼            ▼
      Web App     Android       iOS
        │            │            │
        └────────────┼────────────┘
                     │
                  WebRTC
```

Mobile apps authenticate via Sanctum tokens against the same `/api/v1` endpoints and join the same Reverb channels for signaling; no parallel backend. Not built in this phase — the API is simply kept mobile-ready (versioned, token-auth-capable, no session-only assumptions in business logic).

---

## 14. Deployment Strategy

See [DEPLOYMENT.md](DEPLOYMENT.md). Summary: same Docker Compose topology for prod with hardened env vars, a self-hosted coturn TURN server, HTTPS via a reverse proxy/Let's Encrypt, and MySQL/Redis on persistent volumes (or managed equivalents if the deployment target has them).

---

## 15. Known Limitations

- P2P mesh WebRTC realistically supports ~2–6 participants; beyond that, CPU/bandwidth degrade (O(n²) mesh links). No SFU in MVP.
- No email/SMS notifications in MVP.
- Recording is not implemented until Phase 14 is explicitly scheduled and its architecture is documented.
- No mobile apps in this phase — API is kept ready, not yet consumed by a mobile client.
- Updated continuously as real limitations are discovered during testing (see [BUGS.md](BUGS.md)).

---

## 16. Future Improvements

- SFU adoption (mediasoup or self-hosted LiveKit) once mesh participant limits are hit — triggers documented in §5.
- Server-side recording pipeline (FFmpeg + SFU) once Phase 14 is scheduled.
- Email/SMS meeting notifications.
- Native Android/iOS clients consuming the existing `/api/v1`.
- Breakout rooms, virtual backgrounds, reactions — evaluated post-MVP.

---

## MVP Status

`IN PROGRESS` — Phase 1 (Project Foundation) complete and verified (2026-09-01); Phase 2 (Authentication) complete and verified (2026-09-02); Phase 3 (Meeting Management) complete and verified (2026-09-02); Phase 4 (Join Meeting) not yet started. See §11 for live per-phase checklists; a box is checked only once implemented, tested, and manually verified per the phase-completion rule in the project's development workflow.
