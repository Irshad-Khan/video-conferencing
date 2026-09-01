# Video Meeting App

A self-hosted, web-based video conferencing platform built on Laravel, Vue 3, and WebRTC — no paid/proprietary video APIs (no Agora, Twilio, Daily, Zoom SDK, etc.). Everything runs in Docker.

See [docs/ROADMAP.md](docs/ROADMAP.md) for the full project plan and phase-by-phase status, and the rest of [docs/](docs/) for architecture, database, WebRTC, security, testing, and deployment details.

## Requirements

Only Docker and Docker Compose are required on the host — no local PHP, MySQL, or Node installation.

## Setup

```bash
git clone <repo-url>
cd "Vidoe Meeting App"
cp .env.example .env
docker compose up -d
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate
```

If any of the default ports (8000, 3306, 8080, 5173) are already in use on your machine, override `APP_PORT`, `DB_FORWARD_PORT`, `REVERB_PORT`, or `VITE_PORT` in `.env` before bringing the stack up.

Once running:

- App: http://localhost:8000 (or your `APP_PORT`)
- Vite dev server (asset HMR): http://localhost:5173
- Reverb (WebSocket signaling): ws://localhost:8080

## Services

| Service | Purpose |
|---|---|
| `app` | Laravel (PHP-FPM) |
| `nginx` | Web server, proxies to `app` |
| `mysql` | Database |
| `redis` | Cache, queue, Reverb scaling backend |
| `reverb` | WebSocket signaling server |
| `vite` | Frontend dev server (Vue 3 + Vite, HMR) — dev only |

## Common Commands

```bash
docker compose exec app php artisan test      # run automated tests
docker compose exec app php artisan tinker    # REPL
docker compose logs -f reverb                 # tail the signaling server
docker compose down                           # stop everything
```

## Project Status

Tracked live in [docs/ROADMAP.md](docs/ROADMAP.md). Known issues are logged in [docs/BUGS.md](docs/BUGS.md).
