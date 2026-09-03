# WEBRTC.md

## Model: P2P Mesh (MVP)

Every participant establishes a direct `RTCPeerConnection` to every other participant. For `n` participants there are `n(n-1)/2` peer connections total, and each client sends its own upload `n-1` times — bandwidth and CPU cost grow O(n²) per client. This is why the MVP target is **2–6 participants** (verified at every size 2 through 6 in Phase 6, §11 of [ROADMAP.md](ROADMAP.md); results in [TESTING.md](TESTING.md#phase-6-verification-checklist-multi-participant-video)).

The signaling sequence below (§ "Signaling Sequence") requires no participant-count-specific logic: every existing peer independently reacts to a newcomer's `participant.announce`, so it forms a correct full mesh at any `n` without change — confirmed, not just assumed, by Phase 6's 2/3/4/5/6-participant test runs.

```
User A
  │
  │ WebSocket signaling (Reverb)
  ▼
Signaling Server
  │
  │ WebSocket signaling
  ▼
User B

User A ◄──── WebRTC media (SRTP) ────► User B
```

The signaling server (Reverb) never sees decoded/decrypted media — only SDP and ICE control messages.

## Signaling Sequence (per new peer pair)

The channel is `private-meeting.{meeting_uuid}` (plain private, not presence — see "Why not a presence channel" below). All signaling — roster, offer/answer, ICE — travels as client **whisper** events on this one channel; Reverb relays them without any Laravel/PHP involvement per message, only the initial subscribe is authenticated server-side (`routes/channels.php`).

1. New participant joins the room, subscribes to the channel, and once subscription is confirmed whispers `participant.announce` (`{ id, name }`, `id` being their stable presence id — `user-{user_id}` or `guest-{participant_id}`).
2. Every existing participant is listening for `participant.announce`; on receipt each creates an `RTCPeerConnection`, generates an SDP **offer**, and whispers it (`webrtc-offer`, targeted at the new participant's id).
3. The new participant receives each offer, sets the remote description, creates an **answer**, and whispers it back (`webrtc-answer`).
4. Both sides exchange **ICE candidates** (`webrtc-ice-candidate`) as they're discovered (trickle ICE).
5. Connection state transitions are surfaced in the UI (`connecting` → `connected` / `failed` / `disconnected`), driven by `RTCPeerConnection.connectionState`.
6. On leaving, a participant whispers `participant.bye`; every peer tears down its connection to that id. An ungraceful departure (closed tab, crash) has no `bye` to react to — the peer connection eventually surfaces as `disconnected`/`failed` via ICE/DTLS timeout instead, and the tile reflects that state.

### Why not a presence channel

Pusher/Reverb presence channels derive their member id from `Auth::user()->getAuthIdentifier()`, which for a real signed-in user is their `users.id` and — via `AuthenticateBroadcastGuest` — for a guest is their `meeting_participants.id`. Those are two independent auto-increment sequences, so a user and a guest sharing the same meeting can collide on the same numeric presence id, corrupting Reverb's member-tracking. A plain private channel with application-level roster whispers sidesteps this entirely and is what's implemented.

## Host Control Signaling

Unlike every other signaling message on `meeting.{uuid}` (roster, offer/answer, ICE, `media-state`), host actions — mute a participant, remove a participant, lock/unlock the meeting, allow/disallow guests, end the meeting — cannot be a client whisper, because the target of the action (a removed or force-muted participant) can't be relied on to whisper their own removal/mute to themselves. These are the one case in this app where the **server** is the trigger, so Phase 7 introduced the app's first real Laravel broadcast event: `App\Events\HostControlEvent`, dispatched from `MeetingControlController` (lock/unlock/guests toggle/mute/remove) and from `MeetingController::end()`.

- Implements `ShouldBroadcastNow` (synchronous — this app has no queue worker service, see [ROADMAP.md](ROADMAP.md#9-docker-architecture)) and broadcasts on the same `private-meeting.{meeting_uuid}` channel as everything else, as `.host.control` (custom `broadcastAs()`, hence the leading dot `Room.vue` uses when subscribing: `channel.listen('.host.control', ...)`).
- Payload: `{ action, target? }` — `action` is one of `mute`/`remove`/`lock`/`unlock`/`end`/`guests-enabled`/`guests-disabled`; `target` (mute/remove only) is the affected participant's `presenceId()`, so every client — including the target — can cheaply check `payload.target === myId`.
- The host also receives their own broadcast (there is no `toOthers()` — the host's own tab needs the lock/guests-toggle confirmation just like everyone else). `Room.vue`'s host-only lock/guests toggle handlers account for this: they capture the intended target value *before* awaiting the HTTP response and apply that captured value afterwards, rather than inverting whatever the reactive ref holds at that point — otherwise the self-broadcast arriving before the HTTP response resolves can double-flip the UI (see BUG-010 in [BUGS.md](BUGS.md)).
- `lock`ing a meeting is enforced server-side in `MeetingJoinController::join()` (rejects any non-host join with `403` while `is_locked`); it does not affect participants already connected.
- Server-to-Reverb publishing needs Reverb's Docker-internal address (`REVERB_SERVER_HOST`/`REVERB_SERVER_PORT`, defaulting to `reverb:8080`), not the browser-facing `REVERB_HOST`/`REVERB_PORT` (`localhost:8301`) that `config/broadcasting.php`'s reverb connection used exclusively before Phase 7 — see BUG-009 in [BUGS.md](BUGS.md). This had no effect on Phase 5/6, since all of that signaling is client whispers and never calls `broadcast()`.

## STUN / TURN

- WebRTC needs STUN at minimum to discover public-facing candidates; it needs **TURN** whenever both peers are behind NAT that blocks direct/STUN connectivity (common on restrictive corporate/mobile networks).
- **Development**: a public STUN server (e.g. Google's) may be used only for local development, never relied on for production.
- **Production**: self-hosted **coturn**, deployed and documented in [DEPLOYMENT.md](DEPLOYMENT.md). No paid TURN provider is used, per project constraints.

```
Browser A
    │
    ├── STUN/TURN
    │
    ▼
coturn
    │
    ▼
Browser B
```

## Client-Side Media Handling

- `getUserMedia({ audio, video })` for camera/mic; local preview attached immediately, independent of signaling state.
- Mute/unmute and camera on/off toggle `track.enabled` locally (renegotiation-free) and whisper a `media-state` signaling event (`{ from, mic, camera }`) so peers update UI (mic/camera icons) without needing to inspect the media itself — necessary because a disabled track keeps flowing at the transport level with no automatic remote-side signal.
- Screen share uses `getDisplayMedia`, replaces the outgoing video track via `RTCRtpSender.replaceTrack` on every existing peer connection (no renegotiation, no new offer/answer round-trip — the swapped frames flow over the already-established RTP transport). `stopScreenShare()` restores the original camera track, and the same restore path also fires automatically from the captured track's own `onended` event, so the browser/OS-native "Stop sharing" control (not just the in-app button) correctly hands the camera back. A peer connection created *while* a share is already in progress (`ensurePeer()`, for a participant who joins mid-share) picks up the screen track instead of the camera for its initial `addTrack`, via `currentVideoTrack()`. Since `replaceTrack` itself carries no signaling, remote UI ("· presenting" on a tile) is driven by extending the existing `media-state` whisper with a `screenSharing: boolean` flag rather than a new event type. Permission-denied and unsupported-browser (`!navigator.mediaDevices.getDisplayMedia`) are both handled inline — see [TESTING.md](TESTING.md#phase-8-verification-checklist-screen-sharing).

## Guest Signaling Auth

Guests never get a Sanctum session, so they can't rely on the cookie-based `$request->user()` that Laravel's default `/broadcasting/auth` route uses. `App\Http\Middleware\AuthenticateBroadcastGuest` (registered only on the broadcasting-auth route group in `bootstrap/app.php`) resolves an unauthenticated request's identity from an `X-Guest-Token` header — the same one-time token returned by `POST /meetings/{code}/join` — by hashing it and matching it against `meeting_participants.guest_token`. The frontend sets this header at runtime via `window.Echo.connector.options.auth.headers['X-Guest-Token']` (see `resources/js/pages/Room.vue`) before subscribing, since the token is only known after a successful join, not at Echo's initial construction.

## When an SFU Becomes Necessary

Phase 6's testing (fake-media, 2–6 participants) found no correctness issues and no trigger condition met at 6 participants — but it did not measure real CPU/bandwidth headroom (see the performance notes in [TESTING.md](TESTING.md#phase-6-verification-checklist-multi-participant-video)). Migrate off P2P mesh to a Selective Forwarding Unit when manual/load testing with real media shows:
- CPU usage on client devices becomes unacceptable above ~6 participants, or
- Upload bandwidth requirements (n−1 outbound streams per client) exceed typical residential/mobile uplink capacity, or
- Product requirements call for >6-participant meetings as a supported target.

Candidates evaluated (not implemented in MVP): **mediasoup** (Node-based SFU, fine-grained control, more integration work) and **LiveKit self-hosted** (Go-based, higher-level SDKs, faster to integrate). Jitsi Videobridge and Janus were considered but are heavier to operate standalone alongside a Laravel-centric stack. The selected option and migration plan will be documented here and in `ROADMAP.md` §16 once the trigger condition above is actually met — not built speculatively.

## Known Constraints

- No CI-automated WebRTC media tests — the manual multi-browser procedure in [TESTING.md](TESTING.md) is run by hand (via headless Chrome instances with `--use-fake-device-for-media-stream --use-fake-ui-for-media-stream`, driven over the Chrome DevTools Protocol) rather than wired into the automated test suite, since that setup is heavier than a `php artisan test` run and not worth the CI investment at MVP scale. Scaling past 2 participants only needs one Chrome process per *authenticated* identity (cookie-based sessions are shared across all tabs in a profile); guests are stateless (token lives in per-tab `sessionStorage`, not cookies), so many simultaneous guests can share one headless Chrome profile as separate tabs — used to reach 6 participants in Phase 6 with only 2 Chrome processes.
- Recording is out of scope until Phase 14 is explicitly scheduled (see ROADMAP.md §Phase 14 for the browser-vs-server-side recording tradeoff).
