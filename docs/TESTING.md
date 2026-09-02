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

## Phase 2 Verification Checklist (Authentication)

Verified 2026-09-02, mcsirshad95@gmail.com.

| ID | Test | Expected | Status |
|----|------|----------|--------|
| TC-P2-01 | Automated suite | `php artisan test` passes | PASS (21/21 tests, 72 assertions — `tests/Feature/Auth/{Registration,Login,Logout,Profile}Test.php`) |
| TC-P2-02 | Registration validation | Duplicate email, mismatched confirmation, missing fields all rejected with 422 | PASS |
| TC-P2-03 | Login security | Wrong password / unknown email rejected (422, generic message, no user enumeration); 5 failed attempts trigger lockout | PASS |
| TC-P2-04 | Logout | Session invalidated, `web` guard no longer authenticated | PASS |
| TC-P2-05 | Profile authorization | Guest gets 401 on `GET`/`PUT /profile`; a user can never see/edit another user's profile | PASS |
| TC-P2-06 | Password change | Requires correct `current_password`; wrong current password rejected, hash actually changes in DB | PASS |
| TC-P2-07 | Frontend flow (real browser) | Register → dashboard; logout → login; visiting `/dashboard` logged out → redirected to `/login`; login → dashboard; profile update persists and reflects on dashboard; visiting `/login` while authenticated → redirected to `/dashboard` | PASS (driven via Chrome DevTools Protocol against the live Docker stack at localhost:8300 — see BUG-005/BUG-006 for infra issues found and fixed along the way) |
| TC-P2-08 | Code style | `./vendor/bin/pint --test` | PASS (42 files, no issues) |

Bugs found and fixed during this pass are logged in [BUGS.md](BUGS.md) (BUG-005, BUG-006).

## Phase 3 Verification Checklist (Meeting Management)

Verified 2026-09-02, mcsirshad95@gmail.com.

| ID | Test | Expected | Status |
|----|------|----------|--------|
| TC-P3-01 | Automated suite | `php artisan test` passes | PASS (44/44 tests, 134 assertions — full suite incl. `tests/Feature/Meetings/*`) |
| TC-P3-02 | Create meeting | Host creates a meeting; `meeting_uuid`/`meeting_code` generated, status `scheduled`, guest gets 401, missing title gets 422 | PASS |
| TC-P3-03 | Meeting code uniqueness | Retry loop in `Meeting::generateUniqueCode()` re-rolls on a forced collision instead of violating the unique constraint | PASS (`Str::createRandomStringsUsingSequence()` forces a collision, asserts the retried code is used) |
| TC-P3-04 | Meeting password | Optional password is bcrypt-hashed, never returned raw; `has_password` reflects presence | PASS |
| TC-P3-05 | Authorization | Guest gets 401 on every meeting route; a non-host gets 403 on view/update/delete/cancel/start/end of another host's meeting; the host succeeds on all of them | PASS |
| TC-P3-06 | Lifecycle | `scheduled → live` (`/start`), `live → ended` (`/end`), `scheduled → cancelled` (`/cancel`) all persist correctly; invalid transitions (starting a live meeting, ending a scheduled one, cancelling a live one) rejected with 409 | PASS |
| TC-P3-07 | Edit/delete guardrails | Editing blocked once a meeting is no longer `scheduled` (409); deleting blocked while `live` (409); an `ended` meeting can be deleted | PASS |
| TC-P3-08 | Dashboard listing | `GET /meetings` returns only the authenticated user's own meetings, across every status | PASS |
| TC-P3-09 | Frontend flow (real browser) | Create an instant meeting and a scheduled+password-protected meeting from the Dashboard; start → appears under Live; end → moves to Past as `ended`; edit a scheduled meeting's title; cancel it → shows `cancelled` in Past; delete a past meeting; reload confirms persistence | PASS (driven via Chrome DevTools Protocol against the live Docker stack at localhost:8300; see BUG-007 for an implementation bug found and fixed along the way) |
| TC-P3-10 | Code style | `./vendor/bin/pint --test` | PASS (53 files, no issues) |

Bugs found and fixed during this pass are logged in [BUGS.md](BUGS.md) (BUG-007).

## Phase 4 Verification Checklist (Join Meeting)

Verified 2026-09-02, mcsirshad95@gmail.com.

| ID | Test | Expected | Status |
|----|------|----------|--------|
| TC-P4-01 | Automated suite | `php artisan test` passes | PASS (62/62 tests, 182 assertions — adds `tests/Feature/Meetings/MeetingJoinTest.php`) |
| TC-P4-02 | Public lookup | `GET /meetings/lookup/{code}` returns title/host/status/has_password/allow_guests for a valid code, `404` for an unknown one, never leaks the password | PASS |
| TC-P4-03 | Host join | The host joins their own meeting as `role: host`, bypassing both the password check and the waiting room | PASS |
| TC-P4-04 | Authenticated participant join | A signed-in non-host joins as `role: participant`; placed in `waiting` status when `waiting_room_enabled` is true, `joined` otherwise | PASS |
| TC-P4-05 | Password protection | Wrong password rejected `422` with a field error; correct password admits the joiner | PASS |
| TC-P4-06 | Guest join | A guest (no session) joins as `role: guest` given a name, when `allow_guests` is true, and receives a one-time `guest_token`; rejected `403` when `allow_guests` is false; rejected `422` when no name is given | PASS |
| TC-P4-07 | Meeting state guardrails | Joining an `ended` or `cancelled` meeting is rejected `409`; an unknown code is `404` | PASS |
| TC-P4-08 | Leave / rejoin | An authenticated participant can leave (`status: left`) and rejoin, reusing their existing `meeting_participants` row rather than duplicating it; a `removed`/`rejected` participant cannot rejoin (`403`); a guest can leave using their `guest_token` | PASS |
| TC-P4-09 | Frontend flow (real browser) | From the Dashboard's "Join" box, navigating to `/meeting/{code}`: host joins bypassing password/waiting room and leaves; a second signed-in user is rejected with the wrong password then admitted to the waiting room with the correct one; a signed-out guest is prompted for a name, joins the waiting room, and leaves via a client-stored `guest_token`; a meeting with guests disabled shows a "sign in to join" prompt instead of a guest form; an unknown code shows "Meeting not found" | PASS (driven via Chrome DevTools Protocol against the live Docker stack at localhost:8300) |
| TC-P4-10 | Code style | `./vendor/bin/pint --test` | PASS (59 files, no issues) |

No bugs were logged during this pass.

## Phase 5 Verification Checklist (WebRTC Audio/Video)

Verified 2026-09-02, mcsirshad95@gmail.com.

| ID | Test | Expected | Status |
|----|------|----------|--------|
| TC-P5-01 | Automated suite | `php artisan test` passes | PASS (70/70 tests, 190 assertions — adds `tests/Feature/Meetings/MeetingBroadcastAuthTest.php`) |
| TC-P5-02 | Broadcasting auth — participant | An active (`joined`/`admitted`) participant authorizes `private-meeting.{uuid}`; a user who never joined, or a `waiting`-room participant, gets `403` | PASS |
| TC-P5-03 | Broadcasting auth — guest | A guest with a valid `X-Guest-Token` header authorizes the channel; an invalid token, a `left` guest's token, or no token at all (and no session) gets `403` | PASS |
| TC-P5-04 | Camera/mic + local preview (real browser) | `getUserMedia` grants a local stream, attached to the local `<video>` tile immediately on entering the room | PASS (driven via two headless Chrome instances launched with `--use-fake-device-for-media-stream --use-fake-ui-for-media-stream` over the Chrome DevTools Protocol, ports 9333/9334, each a fully separate browser profile so host and participant/guest hold independent sessions) |
| TC-P5-05 | Offer/answer/ICE exchange, host + authenticated participant | Both peers' `RTCPeerConnection.connectionState` reaches `connected`; each sees the other's live remote video tile; zero uncaught JS errors on either side | PASS |
| TC-P5-06 | Offer/answer/ICE exchange, host + guest | Same as TC-P5-05 with an unauthenticated guest (via `X-Guest-Token`) instead of a signed-in participant; host's tile shows the guest's chosen name | PASS (this path exposed BUG-008 — see below) |
| TC-P5-07 | Mute / camera-off | Toggling mute or camera-off locally (`track.enabled`) whispers `media-state`; the remote peer's tile immediately shows `· muted` / `· camera off` | PASS |
| TC-P5-08 | Leave / peer teardown | Leaving whispers `participant.bye`; the remaining peer's `RTCPeerConnection` closes and their tile disappears, leaving just the local tile | PASS |
| TC-P5-09 | Code style | `./vendor/bin/pint --test` | PASS (61 files, no issues) |

BUG-008 was found and fixed during this pass — see [BUGS.md](BUGS.md).
