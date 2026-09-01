# ARCHITECTURE.md

## Overview

Monolithic Laravel application serving a Vue 3 SPA (via Laravel's Vite plugin) plus a versioned JSON API (`/api/v1`) consumed by that same SPA and, later, by mobile clients. Real-time signaling runs through Laravel Reverb (WebSocket); actual audio/video/screen media flows directly between browsers over WebRTC and never touches the application server.

## Components

| Component | Responsibility |
|---|---|
| Nginx | TLS termination (prod), static asset serving, reverse proxy to PHP-FPM |
| Laravel app (PHP-FPM) | REST API, auth, business logic, Reverb channel authorization |
| Laravel Reverb | WebSocket server: signaling, presence, chat, host-control events |
| MySQL | System of record: users, meetings, participants, messages |
| Redis | Cache, queue driver, Reverb scaling/pub-sub backend |
| Vue 3 SPA | Dashboard, meeting UI, WebRTC peer connection management |
| coturn (prod) | STUN/TURN for WebRTC NAT traversal |

## Request Flows

**Signaling (per meeting):**
```
Client A ──WS──▶ Reverb ──WS──▶ Client B     (offer/answer/ICE, chat, control events)
```

**Media (per pair, P2P mesh):**
```
Client A ◀════ WebRTC (SRTP) ════▶ Client B    (audio/video/screen — never touches the server)
```

## Why a monolith (not separate API + SPA repos)

Single Laravel repo serves Blade entry + Vue SPA bundle via Vite, keeping Docker Compose topology simple for MVP while the `/api/v1` surface stays fully separable — a future mobile app or a split frontend deployment needs no backend changes, only a different client consuming the same versioned API.

## Directory Structure

Standard Laravel layout (see project root) plus:
- `docker/` — Dockerfiles and service configs (php, nginx, mysql init)
- `docs/` — this documentation set
- `resources/js/` — Vue 3 SPA source
- `tests/Feature`, `tests/Unit`, `tests/Browser` — automated test suites

## Related Docs

[ROADMAP.md](ROADMAP.md) · [DATABASE.md](DATABASE.md) · [WEBRTC.md](WEBRTC.md) · [SECURITY.md](SECURITY.md) · [API.md](API.md) (added in Phase 2/3) · [DEPLOYMENT.md](DEPLOYMENT.md)
