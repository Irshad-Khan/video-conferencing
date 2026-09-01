# WEBRTC.md

## Model: P2P Mesh (MVP)

Every participant establishes a direct `RTCPeerConnection` to every other participant. For `n` participants there are `n(n-1)/2` peer connections total, and each client sends its own upload `n-1` times — bandwidth and CPU cost grow O(n²) per client. This is why the MVP target is **2–6 participants** (documented and tested in Phase 6, §11 of [ROADMAP.md](ROADMAP.md)).

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

1. New participant joins → subscribes to `private-meeting.{meeting_uuid}` Reverb channel.
2. Existing participants are notified (`participant.joined`) and each creates an `RTCPeerConnection`, generates an SDP **offer**, sends it via the channel (`webrtc.offer`, targeted at the new participant's connection id).
3. New participant receives each offer, sets remote description, creates an **answer**, sends it back (`webrtc.answer`).
4. Both sides exchange **ICE candidates** (`webrtc.ice-candidate`) as they're discovered (trickle ICE).
5. Connection state transitions are surfaced in the UI (`connecting` → `connected` / `failed` / `disconnected`).
6. On `participant.left` / `left the call`, all peers tear down their connection to that participant.

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
- Mute/unmute and camera on/off toggle `track.enabled` locally (renegotiation-free) and broadcast a `media.state-changed` signaling event so peers update UI (mic/camera icons) without needing to inspect the media itself.
- Screen share (Phase 8) uses `getDisplayMedia`, replaces the outgoing video track via `RTCRtpSender.replaceTrack` (no renegotiation needed), and restores the camera track on stop.

## When an SFU Becomes Necessary

Migrate off P2P mesh to a Selective Forwarding Unit when manual/load testing (Phase 6) shows:
- CPU usage on client devices becomes unacceptable above ~6 participants, or
- Upload bandwidth requirements (n−1 outbound streams per client) exceed typical residential/mobile uplink capacity, or
- Product requirements call for >6-participant meetings as a supported target.

Candidates evaluated (not implemented in MVP): **mediasoup** (Node-based SFU, fine-grained control, more integration work) and **LiveKit self-hosted** (Go-based, higher-level SDKs, faster to integrate). Jitsi Videobridge and Janus were considered but are heavier to operate standalone alongside a Laravel-centric stack. The selected option and migration plan will be documented here and in `ROADMAP.md` §16 once the trigger condition above is actually met — not built speculatively.

## Known Constraints

- No CI-automated WebRTC media tests (headless browsers can fake media devices, but reliably asserting actual audio/video flow end-to-end in CI is out of scope for MVP) — verified via the manual multi-browser procedure in [TESTING.md](TESTING.md) instead.
- Recording is out of scope until Phase 14 is explicitly scheduled (see ROADMAP.md §Phase 14 for the browser-vs-server-side recording tradeoff).
