# TESTING.md

## Automated Tests

Located under `tests/Unit`, `tests/Feature`, `tests/Browser`. Run inside Docker:

```bash
docker compose exec app php artisan test
```

Coverage grows phase-by-phase (see [ROADMAP.md](ROADMAP.md) §11 for the per-phase test checklist): authentication, meeting CRUD/lifecycle, join flow, permissions (host/co-host/participant/guest/admin), chat, participant management. WebRTC media itself is not asserted in automated tests (see WEBRTC.md's "Known Constraints") — it is covered manually below.

## Manual End-to-End Testing

Use at least three browser windows/devices:

```
Browser 1 → Host
Browser 2 → Participant
Browser 3 → Participant
```

### Procedure

1. Host creates a meeting.
2. Participant joins via the meeting link.
3. Camera works for both.
4. Microphone works for both.
5. Host sees participant's video.
6. Participant sees host's video.
7. Participant 2 joins.
8. All three see each other.
9. Mute works (icon + remote silence).
10. Camera OFF works (icon + remote black tile).
11. Chat works (send/receive, all participants).
12. Screen share works (starts, visible remotely, stops, camera restored).
13. Host removes a participant (they are disconnected immediately).
14. Host ends the meeting (all participants are disconnected, status becomes `ended`).
15. Meeting history reflects correct participants/duration/status.

### Result Log

| ID | Test | Expected | Actual | Status |
|----|------|----------|--------|--------|
| TC-001 | Create meeting | Meeting created, code generated | — | PENDING |
| TC-002 | Join meeting | Participant joins successfully | — | PENDING |
| TC-003 | Camera | Local + remote video visible | — | PENDING |
| TC-004 | Microphone | Local + remote audio audible | — | PENDING |
| TC-005 | Multi-join | Participant 2 joins, mesh updates | — | PENDING |
| TC-006 | Mute | Icon updates, remote audio stops | — | PENDING |
| TC-007 | Camera off | Icon updates, remote tile blank | — | PENDING |
| TC-008 | Chat | Message delivered to all participants | — | PENDING |
| TC-009 | Screen share | Remote sees shared screen, camera restores on stop | — | PENDING |
| TC-010 | Host removes participant | Removed participant disconnected | — | PENDING |
| TC-011 | Host ends meeting | All disconnected, status = ended | — | PENDING |
| TC-012 | Meeting history | Participants/duration/status correct | — | PENDING |

This table is updated as each phase is manually verified; entries move from PENDING to PASS/FAIL with the date and tester noted. Any FAIL is logged as a bug in [BUGS.md](BUGS.md) before the corresponding roadmap checkbox may be marked DONE.

## Phase 1 Verification Checklist (Docker Foundation)

Verified 2026-09-01, mcsirshad95@gmail.com.

| ID | Test | Expected | Status |
|----|------|----------|--------|
| TC-P1-01 | `docker compose up -d` | All containers start | PASS |
| TC-P1-02 | Container health | app, nginx, mysql, redis, reverb, vite all healthy/running | PASS |
| TC-P1-03 | Laravel loads | `/` returns 200 | PASS |
| TC-P1-04 | DB connectivity | `php artisan migrate` succeeds | PASS (against MySQL, `migrate:status` confirms 4 migrations ran) |
| TC-P1-05 | Redis connectivity | App can read/write a Redis key | PASS (via `artisan tinker`) |
| TC-P1-06 | WebSocket server | Reverb process starts and listens | PASS (process stable, port 8080/8301 accepts TCP connections; full signaling round-trip deferred to Phase 5) |
| TC-P1-07 | Health endpoint | `/api/v1/health` returns `{"status":"ok"}` | PASS (also covered by automated `tests/Feature/HealthCheckTest.php`) |
| TC-P1-08 | Vue loads | SPA root mounts in browser/curl of built asset | PASS (confirmed via headless-Chrome screenshot showing "API status: ok") |

Bugs found and fixed during this pass are logged in [BUGS.md](BUGS.md) (BUG-001 through BUG-004).
