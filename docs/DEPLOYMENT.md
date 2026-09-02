# DEPLOYMENT.md

## Development

```bash
git clone <repo>
cd <project>
cp .env.example .env
docker compose up -d
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate
```

Frontend assets are served via the `vite` service's dev server (HMR) in development; `docker compose exec app php artisan reverb:start` runs as its own `reverb` service for WebSocket signaling.

## Production Topology

Same Docker Compose services, with these changes:

- `APP_ENV=production`, `APP_DEBUG=false`.
- Frontend built once (`npm run build`) into `public/build`, served as static assets by Nginx instead of the Vite dev server — the `vite` service is dev-only and not part of the production compose file/profile.
- Reverse proxy (Nginx or Traefik) in front of Nginx terminating TLS (Let's Encrypt/ACME), forwarding HTTP → app and WS/WSS → the Reverb service.
- **coturn** deployed as a self-hosted TURN server (own container or host process) — required for reliable WebRTC connectivity across NATs/firewalls in real deployments. Minimum config: a realm, a shared static-auth secret (or long-term credentials tied to short-lived per-meeting turn credentials issued by the Laravel backend), UDP relay port range opened on the host/firewall, and TLS (`turns:`) for the TURN listener where possible.
- MySQL and Redis on named, backed-up volumes (or managed equivalents if the host provides them).
- Secrets (DB password, `APP_KEY`, Reverb app secret, coturn shared secret) supplied via environment, never committed.
- `REVERB_SERVER_HOST`/`REVERB_SERVER_PORT` must resolve on the Docker-internal network (the `app`/`queue` containers publishing broadcast events, e.g. Phase 7's host-control actions) — keep them as `reverb`/`8080` even in production, distinct from the public `REVERB_HOST`/`REVERB_PORT` behind the TLS-terminating reverse proxy that `VITE_REVERB_*` points browsers at. See BUG-009 in [BUGS.md](BUGS.md).

## Scaling Notes

- Reverb can scale horizontally behind Redis pub/sub once a single instance's connection count becomes a bottleneck — not needed for MVP traffic levels.
- WebRTC media is P2P (§ WEBRTC.md) — it does not add server load beyond signaling, but does need TURN relay bandwidth provisioned for the fraction of connections that can't go direct.

## Rollback / Zero-Downtime

Out of scope for MVP; documented once the project reaches a deployment target that requires it (per the project's "don't build for hypothetical requirements" rule).
