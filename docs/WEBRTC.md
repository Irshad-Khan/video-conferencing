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

1. New participant joins the room, subscribes to the channel, and once subscription is confirmed whispers `participant.announce` (`{ id, name, participantId, role }`, `id` being their stable presence id — `user-{user_id}` or `guest-{participant_id}`; `role` — added in Phase 10 for the participants panel's host/guest indicator — is carried the same way into the `webrtc-offer` payload for the reverse direction, since a new joiner only learns an existing peer's identity from the offer they receive, never from an announce of their own).
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
- Payload: `{ action, target? }` — `action` is one of `mute`/`remove`/`lock`/`unlock`/`end`/`guests-enabled`/`guests-disabled`/`waiting-room-enabled`/`waiting-room-disabled`/`spotlight`/`spotlight-cleared` (Phase 20); `target` (mute/remove/spotlight only) is the affected participant's `presenceId()`, so every client — including the target — can cheaply check `payload.target === myId`. Spotlight went through this server-validated path rather than an unauthenticated client whisper deliberately, unlike Pin (see [Active Speaker Detection](#active-speaker-detection)) — it's a host-authority action that overrides what *every other participant* sees, the same trust boundary mute/remove/lock already sit behind (see [SECURITY.md](SECURITY.md#authorization)), and a forged whisper claiming to be the host would have no such check.
- The host also receives their own broadcast (there is no `toOthers()` — the host's own tab needs the lock/guests-toggle confirmation just like everyone else). `Room.vue`'s host-only lock/guests toggle handlers account for this: they capture the intended target value *before* awaiting the HTTP response and apply that captured value afterwards, rather than inverting whatever the reactive ref holds at that point — otherwise the self-broadcast arriving before the HTTP response resolves can double-flip the UI (see BUG-010 in [BUGS.md](BUGS.md)).
- `lock`ing a meeting is enforced server-side in `MeetingJoinController::join()` (rejects any non-host join with `403` while `is_locked`); it does not affect participants already connected.
- Server-to-Reverb publishing needs Reverb's Docker-internal address (`REVERB_SERVER_HOST`/`REVERB_SERVER_PORT`, defaulting to `reverb:8080`), not the browser-facing `REVERB_HOST`/`REVERB_PORT` (`localhost:8301`) that `config/broadcasting.php`'s reverb connection used exclusively before Phase 7 — see BUG-009 in [BUGS.md](BUGS.md). This had no effect on Phase 5/6, since all of that signaling is client whispers and never calls `broadcast()`.

## Chat Signaling

Chat (Phase 9) reuses the same `App\Events\...implements ShouldBroadcastNow` + `private-meeting.{meeting_uuid}` pattern as host control, for the same underlying reason: persistence and real-time fan-out both need the server as the source of truth, not a client whisper the sender's own tab might fail to send. `App\Events\ChatMessageEvent` is dispatched from `MeetingMessageController::store()` once the message is persisted to `meeting_messages`, broadcasting as `.chat.message` with `{ id, message, sender_name, sender_role, presence_id, created_at }`.

- Like host control, there is no `toOthers()` — the sender's own tab receives its own message back over the channel rather than pushing it into the local message list optimistically, so there is exactly one code path for "a message appeared," matching the lock/guests-toggle precedent that avoids double-render/race bugs (see BUG-010 in [BUGS.md](BUGS.md)).
- Unlike WebRTC signaling and host control, chat is also reachable without an active Echo subscription: `GET /meetings/{code}/messages` (participant-gated the same way as the channel itself) returns full history, which `Room.vue` loads once on mount so a participant who joins mid-conversation sees prior messages, not just what arrives live afterward.
- Rendering safety is entirely the frontend's responsibility: the API stores and returns the message text verbatim (see [API.md](API.md#chat)), and `Room.vue` renders it through Vue's default `{{ }}` mustache interpolation, which HTML-escapes automatically — the message is never passed through `v-html`.

## Reactions Signaling

Reactions (Phase 18, post-MVP) are a client whisper (`channel.whisper('reaction', { from, emoji })`) on the existing `meeting.{uuid}` channel — the opposite choice from chat, and deliberately so: a reaction has no history to preserve and no need to survive a page reload, so there's nothing for the server to be the source of truth for. Persisting it (a `meeting_reactions` table, a `ShouldBroadcastNow` event) would add a migration, a model, and a broadcast round-trip for a UI effect that's supposed to disappear in 2.5 seconds regardless.

Because whispers aren't echoed back to their own sender (the same Reverb/Pusher-protocol behavior `participant.announce` and `media-state` already rely on), `sendReaction()` in `Room.vue` does two things on click: whispers it out to everyone else, and spawns the floating-emoji animation locally with the sender's own name — so the sender sees their own reaction the same way everyone else does, without a round-trip through the network.

## Hand-Raise Signaling

Raise Hand (Phase 19, post-MVP) is also a client whisper (`hand-raise`, `{ from, raised }`) on `meeting.{uuid}`, for the same "no persistence needed" reasoning as reactions — but unlike a reaction, it's *state*, not a one-shot event: `Room.vue` tracks `peers[id].handRaised` (and a local `myHandRaised`) and only updates it on the next whisper, the same eventually-consistent model `media-state` already uses for mic/camera. This means, like mic/camera/screen-share state, a participant who joins mid-call won't see an already-raised hand until its owner happens to toggle it again — a pre-existing gap in how `participant.announce`/`webrtc-offer` initialize a new peer's tile (they carry only identity, not current media/hand state), not something Phase 19 introduces or fixes.

## Waiting Room Signaling

The waiting room (Phase 11) needs real-time notification in *two independent directions*, which is why it introduces both a new event class and a second private channel, rather than reusing `meeting.{uuid}`:

- **Host ← waiting participant**: when someone lands in or leaves the waiting room, `MeetingJoinController` broadcasts `App\Events\WaitingRoomEvent` (`action: 'participant-waiting'` / `'participant-left-waiting'`, carrying the waiting participant's id/name/role) on the existing `meeting.{uuid}` channel — the host is already subscribed there, so their Waiting Room panel updates live without any new subscription.
- **Waiting participant ← host**: when the host admits or rejects someone, `MeetingControlController` broadcasts the same event class (`action: 'admitted'` / `'rejected'`, `target` = the participant's `presenceId()`) — but this time on a **new** `private-waiting-room.{meeting_uuid}` channel (see `routes/channels.php`), because a `waiting` participant is deliberately *not* authorized onto `meeting.{uuid}` itself (its channel auth requires `joined`/`admitted` status). `Join.vue` subscribes to this channel only while showing the waiting screen, and filters for `payload.target === presence_id`.

`WaitingRoomEvent` picks its channel via a constructor flag (`forWaitingParticipant: bool`) rather than being two separate classes, since both directions share the same `ShouldBroadcastNow` + `broadcastAs() === 'waiting-room'` shape and only differ in audience.

On `admitted`, `Join.vue` doesn't navigate directly — it re-calls its own `submitJoin()`, i.e. `POST /meetings/{code}/join` again, now sending back the `guest_token` it already holds in `sessionStorage` (authenticated users don't need this — they're matched by `user_id` regardless). Server-side, `MeetingJoinController::join()` looks up that existing participant row, sees `admitted_at` is set, and returns `status: joined` directly instead of `waiting` — the exact same reconnect check that lets anyone previously admitted skip the waiting room a second time on any future reconnect, guest or authenticated. This means the "admitted" transition and the "reconnect" case are the *same code path*, not two.

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

## Active Speaker Detection

Speaker view (Phase 20, post-MVP) needs to know who's currently talking, purely client-side — there's no server involvement, since this is a local rendering decision, not something other participants need to agree on. `Room.vue` taps every audio track (local and each peer's, as soon as its `MediaStream` is available — `pc.ontrack` for peers, right after `getUserMedia` for local) with a Web Audio `AnalyserNode`, and polls all of them on a 700ms interval, comparing average frequency-bin amplitude (`getByteFrequencyData`) against a fixed silence threshold; whoever's loudest becomes the "active speaker" driving the main-stage tile.

This is real level detection, not a placeholder — but it can't be meaningfully verified for *accuracy* in this project's CDP-driven test harness: `--use-fake-device-for-media-stream` generates identical synthetic audio for every simulated participant, so no one is ever actually louder than anyone else there. Live verification (see [TESTING.md](TESTING.md#phase-20-verification-checklist-galleryspeaker-view)) instead confirms the *structural* behavior — the view toggle changes the actual layout, and the fallback-to-first-peer logic correctly selects and renders a remote participant as the main tile — since that's the part a fake-media environment can actually exercise. Local video is deliberately excluded from ever becoming the main tile (it stays in the thumbnail strip), sidestepping the added complexity of a single `<video>` element needing to conditionally become either the small self-view or the large main stage depending on who's speaking.

**Pin and Spotlight (Phase 21) both override this selection**, in priority order: `spotlightedId` (host-set, broadcast — see [Host Control Signaling](#host-control-signaling)) beats `pinnedId` (this viewer's own choice) beats `activeSpeakerId` (auto-detected) beats the first-peer fallback. The two overrides are deliberately different in kind, not just in priority:
- **Pin** is a pure local UI preference — `Room.vue`'s `pinnedId` ref, never sent anywhere. Each viewer can pin a different tile for themselves; it has no server endpoint because there's nothing for a server to authorize or broadcast.
- **Spotlight** is host authority affecting *everyone's* view, so it goes through the same server-validated `HostControlEvent` path as mute/remove/lock (`spotlightedId`, driven by the `.host.control` whisper's `spotlight`/`spotlight-cleared` actions) rather than a raw client whisper — a forged unauthenticated whisper claiming host status would otherwise let any participant spotlight anyone for the whole call.

A spotlighted participant is excluded from becoming their own main tile the same way local video always is (you can't be your own "peer"), so `Room.vue` separately shows a "· spotlighted" label on that participant's own local tile — otherwise the person actually being spotlighted would have no way to know it, since the effect is only visible on *other* participants' screens.

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
- Recording is not implemented — see [RECORDING.md](RECORDING.md) for the browser-vs-server-side architecture decision (client-side `MediaRecorder` is the near-term path; server-side recording is sequenced after an SFU migration, not before it).
