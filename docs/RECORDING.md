# RECORDING.md

Phase 14 was design-first: this document was the deliverable, with implementation deferred. **Recording now ships as of Phase 24** ([ROADMAP.md](ROADMAP.md) §11), following the "Decision" below almost exactly — client-side, `MediaRecorder`-based, composited from the visible video tiles — with one deliberate simplification: the recording is saved straight to the recording participant's own device and never uploaded anywhere, so the server never stores or even sees the file. That sidesteps the storage-growth and access-control considerations raised below for whichever path shipped, since there's nothing server-side to secure or clean up. The rest of this document is kept as originally written, since the comparison and reasoning it captures are still the reasoning the implementation followed.

## Goal

Let a host capture a meeting's audio/video for later playback. Out of scope for this decision: transcription, live streaming to a third party, and per-participant separate-track recording (all noted as possible post-MVP extensions in [§16](ROADMAP.md#16-future-improvements)).

## Why this needs a decision before code

The two realistic architectures assume different places for media to be recordable, and the MVP's actual architecture only supports one of them today:

- **Server-side recording** requires media to pass through a server process that can capture it (an SFU, or a headless "bot" participant). The MVP's WebRTC model (see [WEBRTC.md](WEBRTC.md#model-p2p-mesh-mvp)) is a **P2P mesh** — media flows browser-to-browser and never touches the application server (§3 of ROADMAP.md: "Media... never transits the app servers"). There is no server-side media pipeline to record from until an SFU is adopted, which [WEBRTC.md](WEBRTC.md#when-an-sfu-becomes-necessary) documents as its own separately-triggered migration (participant-count/bandwidth driven), not something to bring in early just to unlock recording.
- **Client-side recording** (the browser's `MediaRecorder` API) needs no server-side media pipeline at all — it works against whatever `MediaStream` the recording participant's browser already has, which the P2P mesh already provides today.

Given the mesh is the MVP's committed architecture and an SFU migration is an independent, not-yet-triggered decision, building a server-side recorder now would mean building throwaway infrastructure (or forcing the SFU migration early, for reasons unrelated to its actual trigger condition).

## Options compared

| | Client-side (`MediaRecorder`) | Server-side (SFU + FFmpeg) |
|---|---|---|
| Works with the current P2P mesh | Yes, no architecture change | No — needs an SFU first (see above) |
| Who can record | Only participants with a strong-enough device/connection; realistically the host | Anyone, symmetrically — the server does the work |
| Survives the recorder's tab closing/crashing | No — recording is lost or truncated | Yes — recording lives on the server, independent of any one participant |
| What gets captured | Whatever composite the recording client renders (e.g. a `<canvas>` mixing visible tiles) — bound by that browser's CPU/GPU | Every participant's raw track, mixed or kept separate, server-side |
| Client resource cost | Extra CPU/battery on the recording participant, on top of already being in an n-way mesh call | None beyond normal call participation |
| Server resource cost | Only storage for the uploaded file after the call | Ongoing CPU (FFmpeg) + storage for every recorded meeting, while it's happening |
| Implementation cost from today's MVP | Low — a recording toggle, `MediaRecorder`, and an upload endpoint | High — SFU adoption, a recording bot/mixer, storage pipeline |
| Consistent with §16's stated future | Not the one named there, but a valid interim step | `ROADMAP.md` §16 already names this: "Server-side recording pipeline (FFmpeg + SFU) once Phase 14 is scheduled" |

## Decision

**Client-side recording via `MediaRecorder`, deferred to a later implementation phase, is the near-term path if/when recording is built before the SFU migration happens.** Server-side recording (FFmpeg fed by an SFU) remains the intended long-term architecture — it's already the plan named in `ROADMAP.md` §16 — but it is *sequenced after* the SFU migration described in [WEBRTC.md](WEBRTC.md#when-an-sfu-becomes-necessary), not before it. Recording should not be the reason the SFU migration happens; participant-count/bandwidth pressure is.

Concretely, this means:
1. If recording is needed **before** the SFU migration trigger is hit, implement it client-side: the host's browser composites the call (e.g. draws all visible `<video>` tiles to a `<canvas>`, captures that canvas's stream + mixed audio via `MediaRecorder`), and uploads the resulting file to a new server endpoint afterward for storage — the server's only new job is accepting and storing a file, not touching live media.
2. If recording is needed **after** the SFU migration already happened for its own (mesh-scaling) reasons, build the server-side recorder against the SFU instead — it will be strictly better (survives any single client, no per-recorder CPU tax, uniform quality) and the SFU work needed for it will already exist.
3. Either way, **do not** bring forward the SFU migration solely to unlock recording. That inverts [WEBRTC.md](WEBRTC.md#when-an-sfu-becomes-necessary)'s stated trigger (mesh scaling limits, not feature convenience) and would mean adopting new production infrastructure for a feature not yet requested, ahead of the trigger that actually justifies its operational cost.

## Non-technical considerations for whichever path is implemented

These apply to both options and are noted here so the eventual implementation phase doesn't skip them:
- **Consent**: participants should be notified a recording is active (a visible indicator, not just a host-side toggle) before implementation — this is a legal requirement in a number of jurisdictions, not just good practice.
- **Access control**: a stored recording is meeting content; it should inherit the same host-only access model the rest of the app uses (`MeetingPolicy`), not a separate, looser one.
- **Storage growth**: recordings are unbounded in size unlike everything else this app currently persists (text rows); the implementation phase needs a retention/cleanup story, not just a place to write files.

## Explicitly out of scope for Phase 14

No code changes shipped with this document: no recording toggle, no `MediaRecorder` usage, no upload endpoint, no storage schema. `docs/ROADMAP.md`'s Known Limitations entry for recording pointed here instead of to a TBD decision, and recording stayed unimplemented until Phase 24, which is that future phase. See the note at the top of this document for what actually shipped and where it differs from the plan below.
