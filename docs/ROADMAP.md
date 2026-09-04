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
- Signaling event types: `participant.joined`, `participant.left`, `webrtc.offer`, `webrtc.answer`, `webrtc.ice-candidate`, `media.state-changed` (mic/cam), `chat.message`, `host.control` (mute/remove/lock/end), `waiting-room` (participant-waiting/admitted/rejected — the one event type broadcast on two different private channels depending on audience, see [WEBRTC.md](WEBRTC.md#waiting-room-signaling)), `reaction` (Phase 18 — ephemeral emoji, client whisper only, never persisted), `hand-raise` (Phase 19 — persistent per-participant boolean, client whisper only).

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
- [x] Public join URL (`/meeting/{code}`) — DONE (`Join.vue`, unauthenticated route; looks up via `GET /meetings/lookup/{code}`)
- [x] Meeting existence + password check — DONE (404 for unknown codes; bcrypt `Hash::check` against `meetings.password`, host bypasses)
- [x] Guest join support (if allowed) — DONE (`allow_guests` gate; guest gets a one-time `guest_token` for `/leave`, never a session)
- [x] `meeting_participants` table + roles (host/co_host/participant/guest) — DONE (`MeetingParticipant` model; role auto-assigned by join context)
- [x] Waiting room hook (full feature in Phase 11) — DONE (`waiting_room_enabled` puts non-host joiners in `status: waiting` instead of `joined`; admit/reject UI deferred to Phase 11)
- [x] Tests: valid/invalid/password-protected/unauthorized meeting, host/participant/guest join, leave, rejoin — DONE (18 automated Feature tests in `tests/Feature/Meetings/MeetingJoinTest.php`)

### Phase 5 — WebRTC Audio/Video (core)
- [x] Camera/mic access, local preview — DONE (`getUserMedia` in `Room.vue`, attached to the local tile on entering the room)
- [x] Reverb signaling channel per meeting — DONE (`private-meeting.{meeting_uuid}`; guests authorize via `X-Guest-Token` since they have no Sanctum session — see `AuthenticateBroadcastGuest`, [WEBRTC.md](WEBRTC.md#guest-signaling-auth))
- [x] Offer/answer/ICE exchange — DONE (client whisper events on the private channel — `participant.announce`/`webrtc-offer`/`webrtc-answer`/`webrtc-ice-candidate`; see [WEBRTC.md](WEBRTC.md#signaling-sequence-per-new-peer-pair))
- [x] Peer connection lifecycle + connection state UI — DONE (per-tile `connecting`/`connected`/`failed`/`disconnected`, driven by `RTCPeerConnection.connectionState`; `participant.bye` whisper on leave tears down the peer)
- [x] Mute/unmute, camera on/off (client-side toggle of local tracks) — DONE (`track.enabled` toggle + `media-state` whisper so remote tiles reflect it)
- [x] Manual test: 2-browser call works end-to-end — DONE (two independent headless Chrome profiles with fake media devices, driven over CDP — host+participant and host+guest both verified; see TESTING.md's Phase 5 checklist)

### Phase 6 — Multi-Participant Video
- [x] P2P mesh for 2–6 participants — DONE (no code change needed: Phase 5's `participant.announce`-driven offer/answer signaling in `Room.vue` is symmetric and participant-count-agnostic; verified to form a correct full O(n²) mesh at every tested size — see [TESTING.md](TESTING.md#phase-6-verification-checklist-multi-participant-video))
- [x] Video tile grid (responsive: 1/2/2x2/grid) — DONE (`Room.vue`'s `sqrt`-based `gridTemplateColumns` already adapts column count to roster size; visually confirmed at 6 participants, 3×2 layout)
- [x] Per-tile name, mic/camera/connection status — DONE (unchanged from Phase 5; confirmed correct at scale — mute/camera-off indicators verified on multiple simultaneous peers)
- [x] Manual test at 2, 3, 4, 5, 6 participants + documented performance notes — DONE (all 5 sizes reach full mesh with zero JS errors; settle times and O(n²) connection-count notes in [TESTING.md](TESTING.md#phase-6-verification-checklist-multi-participant-video))

### Phase 7 — Meeting Controls
- [x] Participant: mute, camera off, leave — DONE (unchanged from Phase 5: local `track.enabled` toggle + `media-state` whisper; `leave` posts `/leave` and tears down the peer)
- [x] Host: mute participant, remove participant, end meeting, lock meeting, allow/disallow guests — DONE (new `App\Events\HostControlEvent`, broadcast on `meeting.{uuid}` as `.host.control`; `MeetingControlController` for lock/unlock/guests toggle/mute/remove; `MeetingController::end()` now also broadcasts. `Room.vue` reacts in real time: a force-muted participant's own mic is disabled and their button flips to "Unmute"; a removed participant is torn down and redirected immediately, and every peer's tile for them disappears without waiting for ICE/DTLS timeout; ending the meeting redirects every connected client. See [WEBRTC.md](WEBRTC.md#host-control-signaling) and [TESTING.md](TESTING.md#phase-7-verification-checklist-meeting-controls))
- [x] Server-side authorization for every host action — DONE (`MeetingPolicy::manageSettings`/`manageParticipants`, same host-only pattern as `manageLifecycle`; every host-control route sits behind `auth:sanctum` + `Gate::authorize`)
- [x] Tests: participant cannot call host endpoints, removed participant is disconnected, lock/end work — DONE (16 new Feature tests across `MeetingControlsTest.php`, `MeetingControlsAuthorizationTest.php`, plus one added to `MeetingBroadcastAuthTest.php`; "removed participant is disconnected" verified live over CDP, not just via the DB status flip, since that requires the real-time broadcast path)

### Phase 8 — Screen Sharing
- [x] `getDisplayMedia` start/stop — DONE (`startScreenShare()`/`stopScreenShare()` in `Room.vue`; "Share screen"/"Stop sharing" toggle in the control bar)
- [x] Switch between camera and screen track — DONE (`RTCRtpSender.replaceTrack()` on every existing peer connection's video sender — no renegotiation, no new offer/answer round-trip; a late-joining peer's initial `addTrack` also picks the screen track via `currentVideoTrack()` if a share is already in progress when they connect)
- [x] Camera restoration after stop — DONE (`stopScreenShare()` replaces the sender's track back to the original camera track; also triggered automatically by the screen track's own `onended` event, so the browser/OS-native "Stop sharing" control — not just our button — correctly restores the camera; also torn down on `leaveRoom()`/`teardown()`)
- [x] Tests: start/stop, remote receipt, permission-denied handling, unsupported-browser fallback — DONE (all four verified live over CDP with two real headless-Chrome participants — see [TESTING.md](TESTING.md#phase-8-verification-checklist-screen-sharing); "remote receipt" is a `media-state` whisper extended with a `screenSharing` flag so peers show a "presenting" indicator, since `replaceTrack` itself needs no signaling — the swapped video frames arrive over the existing RTP flow with no separate confirmation needed. This feature is entirely client-side WebRTC — no backend routes or Feature tests were added)

### Phase 9 — Real-Time Chat
- [x] `meeting_messages` table — DONE (per [DATABASE.md](DATABASE.md#meeting_messages); `meeting_id`, nullable `user_id`, nullable `meeting_participant_id` for guest sender resolution, `message`, `created_at` only — no `updated_at`, messages are immutable)
- [x] Send/receive over Reverb, persisted — DONE (`MeetingMessageController::store()` persists to `meeting_messages` then broadcasts `App\Events\ChatMessageEvent` as `.chat.message` on the existing `meeting.{uuid}` channel, reusing the same server-triggered-broadcast pattern Phase 7 established for host control, for the same reason: persistence and real-time delivery need to be one atomic server action, not a client whisper. `GET /meetings/{code}/messages` returns full history so a participant joining mid-conversation isn't limited to messages sent after they connected. See [WEBRTC.md](WEBRTC.md#chat-signaling) and [API.md](API.md#chat))
- [x] XSS-safe rendering — DONE (message text is stored and returned verbatim — never sanitized/mutated server-side — and rendered in `Room.vue` exclusively through Vue's default `{{ }}` interpolation, which HTML-escapes automatically; `v-html` is never used for message content. Verified live over CDP with an `<img src=x onerror=...>` payload: rendered as literal text, no `<img>` element created, handler never fired)
- [x] Tests: send/receive, empty message rejected, unauthorized user blocked, persistence — DONE (12 new Feature tests in `MeetingChatTest.php` — send/receive for both authenticated participants and guests, chronological ordering, empty/whitespace-only/over-length rejection, unauthenticated non-participant/never-joined/left/removed/invalid-guest-token all blocked with `403`, unknown meeting code `404`; persistence across a reload verified live over CDP since it exercises the real history endpoint + Reverb delivery together, not just the DB row)

### Phase 10 — Participants Panel
- [x] List with mic/camera/host indicators — DONE (new "Participants" side panel in `Room.vue`, alongside the existing chat panel; each row shows name, role badge (Host/Co-host/Guest/Participant), and live mic/camera status text. Required threading a `role` field through the `participant.announce`/`webrtc-offer` whisper payloads, which previously carried no role information — see [WEBRTC.md](WEBRTC.md#signaling-sequence-per-new-peer-pair))
- [x] Live participant count — DONE (`Participants (N)` on both the toggle button and the panel header, `N = Object.keys(peers).length + 1`; updates immediately on join/leave/remove since it derives from the same reactive `peers` roster the video grid already uses, no separate state to keep in sync)
- [x] Host controls inline — DONE (per-row Mute/Remove buttons, host-only, reusing the exact `muteParticipant()`/`removeParticipant()` functions Phase 7 built for the video-tile overlay controls — same actions, same server endpoints, just a second entry point. The video-tile overlay controls were left in place rather than removed, so host actions remain available whichever panel is open)

### Phase 11 — Waiting Room
- [x] Per-meeting toggle — DONE (`waiting_room_enabled` was already settable at create/edit time since Phase 3/4; Phase 11 adds a *live* in-room toggle too — `POST .../waiting-room/enable`/`disable`, broadcast as `HostControlEvent` actions `waiting-room-enabled`/`waiting-room-disabled`, same pattern as the existing lock/guests toggles — since a host may want to turn it on/off mid-meeting, not just at scheduling time)
- [x] Host admit/reject UI + API — DONE. API: `GET .../participants/waiting` (list), `POST .../participants/{id}/admit`, `POST .../participants/{id}/reject`, all host-only (`MeetingPolicy::manageParticipants`). UI: a new "Waiting Room" side panel in `Room.vue` (host-only, same pattern as the Chat/Participants panels), with a live badge count and Admit/Reject buttons per row; `Join.vue`'s existing "You're in the waiting room" screen now actually resolves — it subscribes to a new `waiting-room.{uuid}` channel and auto-transitions to the room on admission, or shows a terminal "Not admitted" message on rejection, instead of sitting there forever. See [WEBRTC.md](WEBRTC.md#waiting-room-signaling)
- [x] Tests: enabled/disabled, admit, reject, reconnect — DONE (14 new Feature tests in `MeetingWaitingRoomTest.php`, covering all four plus live toggle and authorization boundaries; automated suite 112/112, 344 assertions. "Reconnect" specifically covers both an authenticated participant and a guest — via their `guest_token` — skipping the waiting room a second time once previously admitted, using a new `admitted_at` column distinct from `joined_at`/`left_at`. Full end-to-end flow — including the real-time admit/reject notification a `waiting` participant can't receive on the main channel — verified live over CDP with a host, an authenticated participant, and two guests; see [TESTING.md](TESTING.md#phase-11-verification-checklist-waiting-room))

### Phase 12 — Scheduling
- [x] Date/time/duration/title/password/waiting-room/guest settings on create — DONE (title/password/waiting-room/guests already existed since Phase 3/4; Phase 12 adds `scheduled_at` time-of-day, which already existed too, plus a new `duration_minutes` field (1-1440, nullable, informational-only — nothing auto-ends a meeting when it elapses) on both create and edit, validated server-side in `StoreMeetingRequest`/`UpdateMeetingRequest` and shown on the dashboard as e.g. "1h 30m")
- [x] Dashboard grouping: Today/Tomorrow/Upcoming/Past — DONE (`Dashboard.vue`'s single "Upcoming" section split into Today/Tomorrow/Upcoming by calendar-day comparison of `scheduled_at` against the client's local today; an instant meeting with no `scheduled_at` groups under Today since it's meant to start immediately. The existing "Live now" section — a status, not a date — is kept as-is above these, since a live call needs to stay visible regardless of when it was scheduled)
- [x] Notification architecture left extensible; no email/SMS in MVP — DONE (creating a meeting with a future `scheduled_at` dispatches a new plain `App\Events\MeetingScheduled` event server-side, with no listener registered — the extension point a future reminder feature hooks into without touching `MeetingController`; see [ARCHITECTURE.md](ARCHITECTURE.md#notifications))

### Phase 13 — Meeting History
- [x] Persisted join/leave/duration per participant — DONE (`joined_at`/`left_at` already persisted since Phase 4; Phase 13 adds a `duration_seconds` running total, accumulated by a new `MeetingParticipant::endSession()` model method every time a participant's status moves to a terminal one — `left` via `/leave`, `removed` via the host action, `rejected` via the waiting-room reject action — so it sums correctly across multiple reconnects on the same row rather than only reflecting the last session. Ending a meeting (`MeetingController::end()`) now also sweeps every still `joined`/`admitted`/`waiting` participant through the same finalization, so no one is left with a null `left_at` just because they closed the tab instead of calling `/leave`)
- [x] History view: meeting/date/duration/participants/status — DONE (new `GET /meetings/{meeting}/participants` endpoint, host-only, returns the full roster with `duration_seconds`; new `History.vue` page — reachable via a "History" nav link — lists every `ended`/`cancelled` meeting with its date and actual call duration (`ended_at - started_at`), expandable per meeting to show each participant's name, role, final status, and duration. See [API.md](API.md#get-apiv1meetingsmeetingparticipants))

### Phase 14 — Recording (design-first)
- [x] Architecture decision documented (browser MediaRecorder vs. server-side/SFU) before any implementation — DONE (see [RECORDING.md](RECORDING.md): client-side `MediaRecorder` is the near-term path if recording is needed before the SFU migration described in [WEBRTC.md](WEBRTC.md#when-an-sfu-becomes-necessary); server-side FFmpeg-fed recording, already named in §16 below, is sequenced *after* that migration rather than used to justify bringing it forward early)
- [x] Not implemented until WebRTC MVP (Phases 5–8) is stable — DONE (Phases 5–8 have been complete and verified since 2026-09-02/03; per [RECORDING.md](RECORDING.md), this phase is documentation-only — no recording toggle, `MediaRecorder` usage, upload endpoint, or storage schema ships here. Implementation remains a separately-scheduled future phase)

### Phase 15 — Admin Panel
- [x] Users, meetings, active meetings, history, participants views — DONE (new `Admin.vue` page, admin-only nav link, three tabs: Overview/Users/Meetings. The Meetings tab covers "meetings, active meetings, history" as one list with All/Active/History filter chips over every meeting across every host — not just the admin's own, unlike `Dashboard.vue` — since a single admin-scoped `GET /admin/meetings` endpoint already returns everything needed; a per-meeting participants view is available by expanding any row, same expand/collapse pattern `History.vue` established in Phase 13, reusing the new `Meeting::participantHistory()` helper both the host's own and the admin's participants endpoints now share)
- [x] Disable user, delete meeting — DONE (`POST /admin/users/{user}/disable`/`enable` toggles a new `is_disabled` column — blocks login and, via a new `EnsureUserIsNotDisabled` middleware on every authenticated route, also cuts off an already-open session on its next request; an admin cannot disable their own account, 409. `DELETE /admin/meetings/{meeting}` deletes any host's meeting, blocked while live with the same 409 the host's own delete already enforces)
- [x] System stats dashboard — DONE (`GET /admin/stats`: user counts by admin/disabled status, meeting counts by status, total participants ever recorded — shown on the Overview tab)
- [x] Server-side authorization on every admin endpoint — DONE (new `is_admin` column + a single `EnsureUserIsAdmin` middleware wrapping the entire `/admin/*` route group, rather than a `Gate::authorize()` call repeated per controller method — guarantees every admin route is covered by construction. No in-app way to grant admin access exists; the first admin, and any afterward, is granted via a new `php artisan admin:grant {email}` command, kept deliberately out of the API surface. See [API.md](API.md#admin), [SECURITY.md](SECURITY.md))

### Phase 16 — Security Hardening
- [x] Rate limiting, CSRF, XSS, SQLi protection review — DONE. **Rate limiting: a real gap found and fixed** — before this phase, `bootstrap/app.php` never called `$middleware->throttleApi()`, so almost the entire API (registration, meeting-code lookup, leaving a meeting, chat history, and *every* `auth:sanctum` route including all of meeting CRUD, host controls, and the whole admin panel) had zero rate limiting. Added a general 120/min `RateLimiter::for('api', ...)` plus tighter per-route limits on the most sensitive endpoints. Caught a second bug while doing this — registration's first-draft throttle used the default user-id-or-IP key, which a successful registration defeats by handing the very next attempt a fresh identity (see BUG-011 in [BUGS.md](BUGS.md)); fixed with a dedicated IP-only limiter, verified by a test that fires 11 rapid registrations and asserts the 11th is `429`. CSRF/XSS/SQLi: reviewed, no gaps found — see [SECURITY.md](SECURITY.md#phase-16-audit-2026-09-03) for the full write-up
- [x] Server-side validation audit of every client-supplied id/role/permission — DONE, reviewed, no gaps found. Every nested `{meeting}/{participant}` host-control route already checked cross-meeting ownership (since Phase 11); `role` is never read from client input for mass assignment anywhere (always server-derived); every model's `#[Fillable(...)]` list was cross-checked against its Form Requests' validation rules and found to match exactly, so no fillable field exists that bypasses validation
- [x] Secure Reverb channel auth — DONE, reviewed, no gaps found. `routes/channels.php` re-derives participant status from the database on every subscribe (not cached from connect time), and a guest's token stops authorizing the instant their row moves to `left`/`removed`/`rejected`
- [x] Meeting password hashing audit — DONE, reviewed, no gaps found. `'hashed'` Eloquent cast (not a call-site `Hash::make()` that could be forgotten), class-level `#[Hidden(['password'])]` masks it from every serialization path including the Phase 15 admin listing, and `Hash::check()` is the only place the raw value is ever read
- Also found and fixed while auditing Transport/CORS (adjacent, not one of the four checklist bullets, but a real production-hardening gap): `SESSION_SECURE_COOKIE` was never set or documented anywhere — a real deployment following the existing docs literally would ship session/CSRF cookies without the `Secure` flag. Now documented in `.env.example` and [DEPLOYMENT.md](DEPLOYMENT.md)

### Phase 17 — Testing
- [x] Unit/Feature/Browser test suites for auth, meetings, permissions, chat, participants — DONE. Feature: 149 tests spanning every area named (see [TESTING.md](TESTING.md#test-coverage-matrix) for the full file-by-file matrix). Unit: was a real gap — `tests/Unit` held only the untouched Laravel skeleton stub until this phase; added framework-free tests for `MeetingPolicy` (all six abilities) and two pure model methods (`MeetingParticipant::presenceId()`, `MeetingMessage::senderName()`), replacing the stub. Browser: deliberately no `tests/Browser`/Dusk suite — real-media WebRTC flows are covered by the manual CDP-driven procedure this project has used since Phase 5, which Dusk wouldn't meaningfully improve on; `TESTING.md`/`ARCHITECTURE.md` previously referenced a `tests/Browser` directory that was never actually created, corrected during this pass
- [x] Manual WebRTC test procedures ([TESTING.md](TESTING.md)) — DONE, and had been continuously since Phase 5 (every phase touching the browser ships its own Verification Checklist) — this phase's actual contribution was fixing the top-level "Result Log" table in `TESTING.md`, which had sat showing all 12 rows as PENDING since Phase 1 despite every one having since been verified in a later per-phase checklist; now cross-references where each was actually verified instead of appearing untested

### Phase 18 — Reactions

Post-MVP addition, user-requested (Zoom feature parity).

- [x] Floating emoji reactions (👍❤️😂👏🎉) — DONE. A new `reaction` client whisper event on the existing `meeting.{uuid}` channel — the same category as `media-state`/`participant.announce` (Phase 5/8), used here because a reaction is ephemeral and needs no persistence or history, unlike chat (Phase 9), which is why it isn't a server-broadcast `ShouldBroadcastNow` event. `Room.vue` gained a "React" toggle button opening a 5-emoji picker; sending both whispers the reaction out and spawns it locally (whispers aren't echoed back to their own sender by Reverb/Pusher's protocol) with the sender's name attached, floating up from the bottom of the video area and auto-removing itself after 2.5s (`@keyframes reaction-float` in `resources/css/app.css` — the project's first non-Tailwind-utility CSS, since no `<style>` block convention existed yet for a rise-and-fade animation)

Verified live over CDP with two independent Chrome profiles (host + a true anonymous guest): a reaction sent by either side appears on the other's screen with the correct sender name attached, and fades out on its own — see [TESTING.md](TESTING.md#phase-18-verification-checklist-reactions).

### Phase 19 — Raise Hand

Post-MVP addition, user-requested (Zoom feature parity).

- [x] Raise/lower hand — DONE. A new `hand-raise` client whisper (`{ from, raised }`) on the `meeting.{uuid}` channel, mirroring `media-state`'s pattern but kept as its own event rather than folded into it, since "hand raised" isn't a media concern. Unlike a reaction, a raised hand is *persistent* state, not a one-shot animation — it stays up until the participant lowers it themselves (no host-lower-for-them control in this pass; every participant can already see who has a hand up, which is the core Zoom behavior this phase targets). A new "Raise hand ✋" / "Lower hand" toggle button in the control bar; a ✋ badge appears on that participant's video tile (top-left, both for `Room.vue`'s local tile and every peer tile) and next to their name in the Participants panel, visible to everyone in the call, not just the host

Verified live over CDP with two independent Chrome profiles: raising and lowering a hand on either side reflects in real time on the other's screen (tile badge + Participants panel), asserted via exact-DOM-node matching rather than a raw text search, since the button's own static label ("Raise hand ✋") also contains the emoji and would otherwise produce a false pass/fail — see [TESTING.md](TESTING.md#phase-19-verification-checklist-raise-hand).

### Phase 20 — Gallery / Speaker View Toggle

Post-MVP addition, user-requested (Zoom feature parity).

- [x] Gallery/Speaker view toggle — DONE. Gallery (the existing sqrt-grid, unchanged) and a new Speaker view: one large "main stage" tile plus a horizontal thumbnail strip for everyone else. The main tile is chosen by genuine Web Audio `AnalyserNode`-based active-speaker detection (one analyser per remote/local audio track, polled on an interval, comparing average frequency-bin amplitude against a silence threshold) — not a placeholder; it's real level detection that will correctly track whoever is actually talking once deployed with real microphones. Local video deliberately never becomes the main tile (it stays in the thumbnail strip, the self-view-stays-small convention most video-call apps use), except when the caller is alone in the meeting. See [WEBRTC.md](WEBRTC.md#active-speaker-detection) for why this can't be meaningfully live-verified for *accuracy* in this project's CDP/fake-media test harness, and what was verified instead

Verified live over CDP with two independent Chrome profiles: the toggle switches the actual DOM layout (grid → main-stage-plus-strip, not just a relabel), the remote participant's video correctly becomes the main tile, and local video correctly appears in the thumbnail strip — see [TESTING.md](TESTING.md#phase-20-verification-checklist-galleryspeaker-view).

### Phase 21 — Pin / Spotlight Participant

Post-MVP addition, user-requested (Zoom feature parity).

- [x] Pin a participant — DONE. Every participant can pin any peer for their own Speaker view, purely local (`Room.vue`'s `pinnedId` ref) — no server endpoint, since it's a per-viewer preference nobody else needs to know about
- [x] Spotlight a participant — DONE. Host-only, forces the spotlighted participant as the main tile for *every* participant's Speaker view. Deliberately implemented as a proper server-validated action (new `spotlightParticipant`/`clearSpotlight` in `MeetingControlController`, `Gate::authorize('manageParticipants', ...)`, broadcasting `HostControlEvent` actions `spotlight`/`spotlight-cleared`) rather than a raw client whisper — matching the same trust boundary mute/remove/lock already sit behind (see [SECURITY.md](SECURITY.md#authorization)), since a forged whisper claiming host status would otherwise let anyone spotlight anyone. Priority for Speaker view's main tile is spotlight > pin > detected active speaker > first-peer fallback

Verified live over CDP with three participants across three independent Chrome profiles (host + two true anonymous guests, needed to prove the selection logic actually differentiates between candidates rather than trivially resolving to the only peer available): pinning selected one peer, spotlighting a different peer correctly overrode the pin, and the spotlight state (plus its "· spotlighted" indicator) propagated in real time to all three participants' screens, including the spotlighted participant's own tile — a gap caught and fixed during this pass, since a spotlighted person can't be their own "peer" and so had no visible indication they were the one spotlighted until this was added. See [TESTING.md](TESTING.md#phase-21-verification-checklist-pinspotlight-participant).

### Phase 22 — Co-Host Promotion

Post-MVP addition, user-requested (Zoom feature parity). `co_host` already existed as a `meeting_participants.role` enum value since Phase 4's schema design, but the app had no way to actually assign it until now.

- [x] Host can promote/demote a participant to co-host — DONE. New `POST .../participants/{id}/promote` / `.../demote`, gated by a new, strictly host-only `MeetingPolicy::manageCoHosts` ability — deliberately *not* extended to co-hosts themselves, matching Zoom (a co-host cannot appoint another co-host). Rejects guests (`422` — a guest has no persistent session to exercise co-host actions with anyway), the host themselves, and non-`joined` participants (`409`). Broadcasts `HostControlEvent` actions `co-host-promoted`/`co-host-demoted`
- [x] A co-host gains real in-meeting authority, not just a label — DONE. `MeetingPolicy::manageSettings`/`manageParticipants` (lock/guests/waiting-room-toggle, mute/remove/spotlight/admit/reject) now accept the host **or** an active co-host (a real DB lookup — `meeting_participants.role = 'co_host'` for that user on that meeting — not a client-asserted flag). `manageLifecycle` (start/end/cancel) and the dashboard-level `view`/`update`/`delete` abilities stay strictly host-only. `Room.vue` gained a `canManage` computed (`isHost || myRole === 'co_host'`) replacing every `isHost`-gated UI element that maps to the widened abilities, while `End meeting for all` and the promote/demote button itself stay behind `isHost` specifically

Verified live over CDP with two authenticated identities (host + a signed-in participant, in separate Chrome profiles — co-hosts must be authenticated, since promoting a guest is rejected): before promotion the participant had no host-level toolbar or per-peer management buttons; after promotion, both the reactive UI *and* a direct API call confirmed the new authority (mute/remove available) and its boundary (a direct `POST .../end` attempt as the co-host correctly still returned `403`); demotion reverted everything in real time on the same connection, with no reconnect needed. See [TESTING.md](TESTING.md#phase-22-verification-checklist-co-host-promotion).

### Phase 23 — Chat File/Image Attachment

Post-MVP addition, user-requested (Zoom feature parity).

- [x] Attach a file or image to a chat message — DONE. `POST /messages` accepts a `multipart/form-data` body with an optional `attachment` file (5MB max, `jpg/jpeg/png/gif/webp/pdf/txt/csv/doc/docx/xls/xlsx/zip` only) alongside the existing optional `message` text — one of the two must be present (`StoreMeetingMessageRequest::withValidator`). Two new migrations: nullable `attachment_path`/`attachment_original_name`/`attachment_mime_type`/`attachment_size` columns on `meeting_messages`, plus a fix making `message` itself nullable (the original Phase 9 migration left it `NOT NULL`, which silently broke attachment-only sends until caught in this phase — see [BUGS.md](BUGS.md)). Files are stored on the `local` disk (`storage/app/private/chat-attachments/{meeting_id}/...`, outside the public webroot, matching [SECURITY.md](SECURITY.md#file-handling)) under Laravel's auto-generated random filename — the original filename is preserved only as metadata, never used on disk, which rules out path traversal/collision. MIME type and size are read server-side from the uploaded file (`getMimeType()`/`getSize()`), never trusted from client input
- [x] Attachments render inline (images) or as a download chip (everything else) — DONE. `MeetingMessage::isImageAttachment()` checks the server-detected MIME type; `Room.vue`'s chat pane branches on `msg.attachment.is_image` to show either an inline `<img>` (wrapped in a link to the full file) or a 📎-icon chip with filename + human-readable size
- [x] Attachment download is participant-gated, not a bare signed URL — DONE. New `GET /messages/{message}/attachment` streams the file (`Storage::disk('local')->response(...)`, `X-Content-Type-Options: nosniff`) only after re-validating the requester is still an active participant of that specific meeting — deliberately chosen over the signed-URL approach [SECURITY.md](SECURITY.md#file-handling) originally sketched, since a signed URL stays valid for its whole window even if the holder is later removed from the meeting, whereas this endpoint's access is revoked in real time like every other chat/meeting resource in this app. Guests (who have no session cookie) pass their token as `?guest_token=...`, mirroring the existing convention on `GET /messages` — necessary because a plain `<img src>`/`<a href>` can't attach a custom header
- [x] A field added to the HTTP response must also reach the real-time broadcast — a real bug caught and fixed during this phase, not just a design note. `Room.vue` renders chat exclusively from the `ChatMessageEvent` broadcast payload (the sender's own tab never applies its HTTP response optimistically — see BUG-010 in [BUGS.md](BUGS.md)), but `ChatMessageEvent::broadcastWith()` had its own independent array literal that was never updated when attachment support was added to the controller's response — so uploads succeeded and persisted correctly, yet nothing ever appeared in any tab's chat pane. Fixed by extracting a single `MeetingMessage::toChatPayload()` model method used by both the controller responses and the broadcast event, specifically to prevent this class of bug (a field landing in only one of two independent copies) from recurring

Verified live over CDP with two independent Chrome profiles (host + a true anonymous guest, using a custom `DOM.setFileInputFiles` CDP extension to attach real local files through the actual file-picker UI rather than faking the upload): the host attached and sent a real PNG, which the guest received rendered as an `<img>` (not just present in the DOM — the guest's own session fetched the actual image bytes back from the attachment endpoint and confirmed `200`/`image/png`/non-zero size); the guest then attached and sent a PDF, which rendered on the host's side as a download chip, not an inline image, and was fetchable by the host too. See [TESTING.md](TESTING.md#phase-23-verification-checklist-chat-fileimage-attachment).

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
- Recording is not implemented — Phase 14 (2026-09-03) documented the architecture decision only (see [RECORDING.md](RECORDING.md)); implementation is a separately-scheduled future phase.
- No mobile apps in this phase — API is kept ready, not yet consumed by a mobile client.
- Updated continuously as real limitations are discovered during testing (see [BUGS.md](BUGS.md)).

---

## 16. Future Improvements

- SFU adoption (mediasoup or self-hosted LiveKit) once mesh participant limits are hit — triggers documented in §5.
- Server-side recording pipeline (FFmpeg + SFU) once the SFU migration (§5) happens — see [RECORDING.md](RECORDING.md) for why recording is sequenced after that migration rather than before it, and client-side `MediaRecorder` recording as the interim path if recording is needed sooner.
- Email/SMS meeting notifications.
- Native Android/iOS clients consuming the existing `/api/v1`.
- Breakout rooms, virtual backgrounds, reactions — evaluated post-MVP.

---

## MVP Status

`MVP COMPLETE` — all 17 phases implemented, tested, and manually verified; post-MVP feature additions in progress (Phase 18+, user-requested Zoom-parity features). Phase 1 (Project Foundation) complete and verified (2026-09-01); Phase 2 (Authentication) complete and verified (2026-09-02); Phase 3 (Meeting Management) complete and verified (2026-09-02); Phase 4 (Join Meeting) complete and verified (2026-09-02); Phase 5 (WebRTC Audio/Video core) complete and verified (2026-09-02); Phase 6 (Multi-Participant Video) complete and verified (2026-09-02); Phase 7 (Meeting Controls) complete and verified (2026-09-02); Phase 8 (Screen Sharing) complete and verified (2026-09-03); Phase 9 (Real-Time Chat) complete and verified (2026-09-03); Phase 10 (Participants Panel) complete and verified (2026-09-03); Phase 11 (Waiting Room) complete and verified (2026-09-03); Phase 12 (Scheduling) complete and verified (2026-09-03); Phase 13 (Meeting History) complete and verified (2026-09-03); Phase 14 (Recording, design-first) complete and verified (2026-09-03) — documentation-only, see [RECORDING.md](RECORDING.md); Phase 15 (Admin Panel) complete and verified (2026-09-03); Phase 16 (Security Hardening) complete and verified (2026-09-03); Phase 17 (Testing) complete and verified (2026-09-03); Phase 18 (Reactions) complete and verified (2026-09-04); Phase 19 (Raise Hand) complete and verified (2026-09-04); Phase 20 (Gallery/Speaker View Toggle) complete and verified (2026-09-04); Phase 21 (Pin/Spotlight Participant) complete and verified (2026-09-04); Phase 22 (Co-Host Promotion) complete and verified (2026-09-04); Phase 23 (Chat File/Image Attachment) complete and verified (2026-09-04). See §11 for live per-phase checklists; a box is checked only once implemented, tested, and manually verified per the phase-completion rule in the project's development workflow. "Complete" describes the 17-phase MVP scope as defined — it does not mean the app is production-hardened beyond what [SECURITY.md](SECURITY.md)/[DEPLOYMENT.md](DEPLOYMENT.md) document, or that [Known Limitations](#15-known-limitations) (§15) have been lifted; [Future Improvements](#16-future-improvements) (§16) remain unscheduled follow-on work, not remaining MVP scope.
