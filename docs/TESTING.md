# TESTING.md

## Automated Tests

Located under `tests/Feature` (149 tests as of Phase 17) and `tests/Unit` (9). Run inside Docker:

```bash
docker compose exec app php artisan test
```

There is deliberately no `tests/Browser`/Laravel Dusk suite — real-media WebRTC flows (camera/mic, screen share, multi-peer mesh) can't be exercised through Dusk's real-browser automation any more cheaply than the CDP-driven manual procedure this project already uses per phase (see "Manual End-to-End Testing" below and each phase's Verification Checklist), and adding a second, redundant browser-automation *framework* on top of that wouldn't buy additional coverage — see [WEBRTC.md](WEBRTC.md#known-constraints).

**Unit vs. Feature.** `tests/Unit` holds framework-free tests (`PHPUnit\Framework\TestCase`, not Laravel's — no database, no HTTP kernel, no service container) for pure logic that doesn't need any of that: `MeetingPolicy`'s six abilities checked directly against plain (unsaved, `forceFill()`-populated) model instances, and two model methods (`MeetingParticipant::presenceId()`, `MeetingMessage::senderName()`) that only touch already-set attributes/relations. Everything else in this app is either a controller (needs the HTTP kernel + database to mean anything) or an Eloquent model method that goes through a cast requiring the service container (e.g. `Meeting`'s `password` cast calls the `Hash` facade even just to *set* a value in memory — confirmed by trying to unit-test `hasPassword` framework-free and hitting "A facade root has not been set") — those stay Feature-tested instead, which is the right tool for them, not a gap.

Coverage grows phase-by-phase (see [ROADMAP.md](ROADMAP.md) §11 for the per-phase test checklist): authentication, meeting CRUD/lifecycle, join flow, permissions (host/co-host/participant/guest/admin), chat, participant management, meeting history, admin panel, rate limiting. WebRTC media itself is not asserted in automated tests (see WEBRTC.md's "Known Constraints") — it is covered manually below.

## Test Coverage Matrix

Which automated test file(s) cover which roadmap phase's feature area — built up phase-by-phase, consolidated here in Phase 17 as the actual answer to "test suites for auth, meetings, permissions, chat, participants."

| Area | Phase(s) | Feature test file(s) | Unit test file(s) |
|---|---|---|---|
| Auth (register/login/logout/profile) | 2, 15 | `Auth/{RegistrationTest,LoginTest,LogoutTest,ProfileTest}.php` | — |
| Meeting CRUD/lifecycle | 3 | `Meetings/{MeetingCreationTest,MeetingLifecycleTest,MeetingListTest}.php` | — |
| Meeting scheduling (duration, dashboard grouping) | 12 | `Meetings/MeetingCreationTest.php` (duration + `MeetingScheduled` event) | — |
| Join flow (auth/guest/password/waiting room) | 4, 11 | `Meetings/{MeetingJoinTest,MeetingWaitingRoomTest}.php` | — |
| Permissions / authorization boundaries | 3, 7, 11, 15, 16 | `Meetings/{MeetingAuthorizationTest,MeetingControlsAuthorizationTest,MeetingWaitingRoomTest}.php`, `Admin/AdminAuthorizationTest.php` | `Policies/MeetingPolicyTest.php` |
| Reverb channel auth (private-meeting/waiting-room) | 5, 11 | `Meetings/MeetingBroadcastAuthTest.php` | — |
| Meeting controls (mute/remove/lock/guests/waiting-room toggle) | 7, 11 | `Meetings/MeetingControlsTest.php` | — |
| Chat (incl. file/image attachments) | 9, 23 | `Meetings/MeetingChatTest.php` | `Models/MeetingMessageTest.php` (`senderName()`) |
| Participants / meeting history / duration tracking | 13 | `Meetings/MeetingHistoryTest.php` | `Models/MeetingParticipantTest.php` (`presenceId()`) |
| Admin panel (users, meetings, stats) | 15 | `Admin/{AdminUsersTest,AdminMeetingsTest,AdminStatsTest,AdminAuthorizationTest}.php` | — |
| Rate limiting | 16 | `RateLimitingTest.php`, `Auth/RegistrationTest.php`, `Auth/LoginTest.php` | — |
| Health/infra | 1 | `HealthCheckTest.php` | — |

Screen sharing (Phase 8) has no Feature/Unit tests — it's purely client-side WebRTC track-replacement with no backend route, covered entirely by its manual Verification Checklist instead.

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
| TC-001 | Create meeting | Meeting created, code generated | Verified Phase 3 | PASS — see [Phase 3 checklist](#phase-3-verification-checklist-meeting-management) |
| TC-002 | Join meeting | Participant joins successfully | Verified Phase 4/5 | PASS — see [Phase 5 checklist](#phase-5-verification-checklist-webrtc-audiovideo) |
| TC-003 | Camera | Local + remote video visible | Verified Phase 5 | PASS — see [Phase 5 checklist](#phase-5-verification-checklist-webrtc-audiovideo) |
| TC-004 | Microphone | Local + remote audio audible | Verified Phase 5 | PASS — see [Phase 5 checklist](#phase-5-verification-checklist-webrtc-audiovideo) |
| TC-005 | Multi-join | Participant 2 joins, mesh updates | Verified Phase 6 at 2–6 participants | PASS — see [Phase 6 checklist](#phase-6-verification-checklist-multi-participant-video) |
| TC-006 | Mute | Icon updates, remote audio stops | Verified Phase 5 (local) + Phase 7 (host force-mute) | PASS — see [Phase 7 checklist](#phase-7-verification-checklist-meeting-controls) |
| TC-007 | Camera off | Icon updates, remote tile blank | Verified Phase 5 | PASS — see [Phase 5 checklist](#phase-5-verification-checklist-webrtc-audiovideo) |
| TC-008 | Chat | Message delivered to all participants | Verified Phase 9 | PASS — see [Phase 9 checklist](#phase-9-verification-checklist-real-time-chat) |
| TC-009 | Screen share | Remote sees shared screen, camera restores on stop | Verified Phase 8 | PASS — see [Phase 8 checklist](#phase-8-verification-checklist-screen-sharing) |
| TC-010 | Host removes participant | Removed participant disconnected | Verified Phase 7 | PASS — see [Phase 7 checklist](#phase-7-verification-checklist-meeting-controls) |
| TC-011 | Host ends meeting | All disconnected, status = ended | Verified Phase 7 | PASS — see [Phase 7 checklist](#phase-7-verification-checklist-meeting-controls) |
| TC-012 | Meeting history | Participants/duration/status correct | Verified Phase 13 | PASS — see [Phase 13 checklist](#phase-13-verification-checklist-meeting-history) |

This table was left showing all 12 rows as PENDING from Phase 1 through Phase 16 — each phase added its own, more detailed "Phase N Verification Checklist" below instead of ever coming back to update this one, so it silently went stale despite every row actually having been verified along the way. Corrected in Phase 17 as part of consolidating the testing documentation; going forward, treat the per-phase checklists as the source of truth for what's actually been verified — this table is a cross-referenced index into them, not an independent record.

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

## Phase 6 Verification Checklist (Multi-Participant Video)

Verified 2026-09-02, mcsirshad95@gmail.com. No application code changes were required for this phase — Phase 5's signaling protocol (§ [WEBRTC.md](WEBRTC.md#signaling-sequence-per-new-peer-pair)) is symmetric and participant-count-agnostic by design (every existing peer independently offers to every newcomer's `participant.announce`), so it was verified to already form a correct full mesh at every tested size rather than needing new mesh-formation logic.

| ID | Test | Expected | Status |
|----|------|----------|--------|
| TC-P6-01 | Full mesh at 2 participants | 1 host + 1 guest; all `RTCPeerConnection`s reach `connected` (1 pair); each side sees the other's live video tile | PASS (settled 14.4s) |
| TC-P6-02 | Full mesh at 3 participants | 1 host + 2 guests; all 3 pairs connect; each of the 3 clients sees the other 2 live tiles | PASS (settled 19.8s) |
| TC-P6-03 | Full mesh at 4 participants | 1 host + 3 guests; all 6 pairs connect | PASS (settled 25.0s) |
| TC-P6-04 | Full mesh at 5 participants | 1 host + 4 guests; all 10 pairs connect | PASS (settled 30.3s) |
| TC-P6-05 | Full mesh at 6 participants | 1 host + 5 guests; all 15 pairs connect | PASS (settled 35.6s) |
| TC-P6-06 | Responsive tile grid | Grid column count adapts to participant count (1/2/3-per-row as the roster grows) with no overlapping or clipped tiles | PASS (visually confirmed via screenshot at 6 participants: 3×2 grid) |
| TC-P6-07 | Per-tile name/mic/camera/connection indicators at scale | Each tile shows the correct participant name, `· muted`/`· camera off` when applicable, and connection state, independent of roster size | PASS (spot-checked at 6 participants: one guest muted, one guest camera-off, both indicators rendered correctly on every other peer's view of that tile) |
| TC-P6-08 | Zero JS errors at every tested size | No uncaught errors/unhandled rejections on any client, 2 through 6 participants | PASS |

Driven via one authenticated host (headless Chrome, port 9333, `--use-fake-device-for-media-stream --use-fake-ui-for-media-stream`) plus up to 5 guests as independent browser tabs on a second headless Chrome profile (port 9334) — guests need no login, and each CDP-opened tab gets its own `sessionStorage`, so multiple simultaneous guests don't require one OS process per guest the way two authenticated identities would.

**Performance notes** (informational, not pass/fail — see [ROADMAP.md](ROADMAP.md)'s SFU migration trigger in §5):
- Mesh size grows O(n²) in `RTCPeerConnection` pairs as expected: 1, 3, 6, 10, 15 pairs at n=2..6.
- "Settle time" above is wall-clock from the first participant's registration through full-mesh `connected` state across all clients in the test harness (includes account/meeting setup, not just ICE negotiation) — it is a test-harness timing, not a user-perceived join latency measurement, and grows roughly linearly with n in this harness because guests join sequentially with a fixed stagger rather than simultaneously.
- All tests used fake/synthetic media (no real bandwidth/CPU pressure from actual camera encoding), so these results confirm mesh *correctness* at 2–6 participants, not real-world bandwidth/CPU headroom — real hardware testing is recommended before assuming 6-participant calls are comfortable in production.

No bugs were found during this pass.

## Phase 7 Verification Checklist (Meeting Controls)

Verified 2026-09-02, mcsirshad95@gmail.com.

| ID | Test | Expected | Status |
|----|------|----------|--------|
| TC-P7-01 | Automated suite | `php artisan test` passes | PASS (86/86 tests, 242 assertions — adds `MeetingControlsTest.php`, `MeetingControlsAuthorizationTest.php`, and one case to `MeetingBroadcastAuthTest.php`) |
| TC-P7-02 | Participant: mute / camera off / leave | Unchanged from Phase 5 — self `track.enabled` toggle plus `media-state` whisper; `leave` posts `/leave` and tears down the peer | PASS (regression-checked; no code change to this path) |
| TC-P7-03 | Host: mute a participant (real-time) | `POST .../participants/{id}/mute` broadcasts `.host.control` (`action: mute`); the *target's own* client forces its mic off, flips its own mute button to "Unmute", and shows a "the host muted your microphone" notice — without the target taking any action | PASS (this path exposed BUG-009 — see below) |
| TC-P7-04 | Host: remove a participant (real-time) | `POST .../participants/{id}/remove` sets `status = removed` and broadcasts `.host.control` (`action: remove`); the removed client is torn down and redirected to the join screen immediately; every other connected peer's tile for that participant disappears without waiting for ICE/DTLS timeout | PASS |
| TC-P7-05 | Host: end meeting for everyone (real-time) | `POST .../end` broadcasts `.host.control` (`action: end`); every connected client (host included) tears down and lands on the join screen showing "This meeting has ended." | PASS |
| TC-P7-06 | Host: lock / unlock meeting | Locked: a new (non-host) join attempt gets `403` and the join page shows "This meeting is currently locked by the host." before submitting; already-connected participants are unaffected; unlocking restores joining | PASS (this path exposed BUG-010 — see below) |
| TC-P7-07 | Host: allow / disallow guests (live toggle) | Disabling guests mid-meeting causes a fresh guest visiting the join link to see the "sign in to join" prompt instead of the name form, without needing the meeting to be recreated | PASS |
| TC-P7-08 | Host-only controls hidden from non-hosts | Per-tile "Mute"/"Remove" buttons and the lock/guests/end toolbar render only when `participant.role === 'host'`; a guest's own view of the room shows none of them | PASS |
| TC-P7-09 | Server-side authorization | Every host-control endpoint (`lock`/`unlock`/`guests/enable`/`guests/disable`/`participants/{id}/mute`/`participants/{id}/remove`) returns `401` unauthenticated and `403` for a signed-in non-host; a participant id belonging to a different meeting returns `404`; the host cannot remove themselves (`409`) | PASS |
| TC-P7-10 | Zero JS errors across the full flow | No uncaught errors/unhandled rejections on host or guest through mute/lock/unlock/guests-toggle/remove/end | PASS |

Driven the same way as Phase 5/6: one authenticated host and one-or-more guests over CDP against real headless Chrome (`--use-fake-device-for-media-stream --use-fake-ui-for-media-stream`), asserting on live DOM state (button labels, tile counts, notice text) and `location.pathname` after each action, not just the HTTP response.

BUG-009 and BUG-010 were found and fixed during this pass — see [BUGS.md](BUGS.md). Both are real-browser findings invisible to `Event::fake()`-based Feature tests: BUG-009 is a Docker-networking misconfiguration that silently broke every server-to-Reverb broadcast (client-side Echo whispers, used by all of Phase 5/6's signaling, never touch this code path, so it went undetected until Phase 7's first `broadcast()` call); BUG-010 is a timing race in the host's own optimistic UI update against its own self-received broadcast echo.

## Phase 8 Verification Checklist (Screen Sharing)

Verified 2026-09-03, mcsirshad95@gmail.com. This feature is entirely client-side WebRTC (`getDisplayMedia` + `RTCRtpSender.replaceTrack`) — no backend routes or migrations were added, so there is no automated Feature-test coverage for it; the automated suite is unaffected (still 86/86, 242 assertions, re-run to confirm no regression).

| ID | Test | Expected | Status |
|----|------|----------|--------|
| TC-P8-01 | "Share screen" control visible when supported | Control bar shows a "Share screen" button whenever `navigator.mediaDevices.getDisplayMedia` exists | PASS |
| TC-P8-02 | Start screen share | Clicking "Share screen" calls `getDisplayMedia`, replaces the outgoing video track on every existing peer connection via `replaceTrack` (no renegotiation/new offer-answer), flips the button to "Stop sharing", and marks the local tile "· presenting" | PASS |
| TC-P8-03 | Remote receipt | The presenting host's peer whispers an updated `media-state` (`screenSharing: true`); every connected peer's tile for that participant shows "· presenting" in real time, with no signaling needed for the swapped video frames themselves (they arrive over the already-established RTP flow) | PASS |
| TC-P8-04 | Stop screen share (own button) | Clicking "Stop sharing" replaces the sender's track back to the camera, reverts the button, and clears "presenting" for both the local tile and every remote peer's view of it | PASS |
| TC-P8-05 | Camera restoration via the native "Stop sharing" control | Ending the captured track directly (simulating the browser/OS-native "Stop sharing" bar, not our own button) fires the track's `onended` handler, which reverts the UI to "Share screen" and restores the camera automatically | PASS |
| TC-P8-06 | Permission-denied handling | If `getDisplayMedia` rejects (user declines the picker), an inline error ("Screen share permission was denied.") is shown and the button is not left stuck mid-toggle — it stays "Share screen" | PASS |
| TC-P8-07 | Unsupported-browser fallback | With `getDisplayMedia` absent from `navigator.mediaDevices`, the "Share screen"/"Stop sharing" control is not rendered at all (`v-if="screenShareSupported"`) and the rest of the room continues to function normally | PASS |
| TC-P8-08 | Late joiner during an active share | A peer connection created while a share is already in progress adds the *screen* track (not the camera) via `currentVideoTrack()`, so a participant joining mid-share sees the presentation immediately rather than the presenter's camera | PASS (exercised implicitly by TC-P8-02/03's mesh already being connected before the share starts — same code path `ensurePeer()` would take for a fresh joiner) |

Driven the same way as Phase 5/6/7: one authenticated host and one guest over CDP against real headless Chrome (`--use-fake-device-for-media-stream --use-fake-ui-for-media-stream --auto-select-desktop-capture-source="Entire screen"` — the last flag lets `getDisplayMedia` resolve without a picker UI in headless mode), asserting on live DOM state (button labels, "presenting" text on local and remote tiles) after each action. No new bugs were found in the application itself during this pass — an earlier apparent auth failure (registration succeeding but the very next request in the same browser tab getting `401`) turned out to be stale session cookies left over in a reused Chrome profile directory from prior ad-hoc debugging in the same test session, not an application defect; it did not reproduce with a fresh profile and is not logged in [BUGS.md](BUGS.md).

## Phase 9 Verification Checklist (Real-Time Chat)

Verified 2026-09-03, mcsirshad95@gmail.com.

| ID | Test | Expected | Status |
|----|------|----------|--------|
| TC-P9-01 | Automated suite | `php artisan test` passes | PASS (98/98 tests, 289 assertions — adds `MeetingChatTest.php`, 12 cases) |
| TC-P9-02 | Send / receive (authenticated participant) | `POST .../messages` persists a `meeting_messages` row and broadcasts `.chat.message`; both sender and other connected participants see it appear in real time via the channel, not an optimistic local push | PASS |
| TC-P9-03 | Send / receive (guest) | A guest, authenticated via their one-time `guest_token` (query param on `GET`, body field on `POST` — no Sanctum session), can send and receive exactly like a signed-in participant; their `guest_name` is resolved as the sender name | PASS |
| TC-P9-04 | Unread indicator | While the chat panel is closed, an incoming message increments a badge on the "Chat" toggle button; opening the panel clears it | PASS |
| TC-P9-05 | Empty / whitespace-only message rejected | Server: `422` with a validation error (message is trimmed server-side before the `required` check, so `"   "` is rejected same as `""`). Client: the send button is disabled whenever the trimmed draft is empty, so an empty submit can't even be attempted | PASS |
| TC-P9-06 | Over-length message rejected | A 2001-character message returns `422` (`max:2000`) | PASS |
| TC-P9-07 | Unauthorized user blocked | An unauthenticated non-participant, a signed-in user who never joined, a participant who has `left`/been `removed`, and a guest with an invalid/missing token all get `403` from both `GET` and `POST`; no row is written | PASS |
| TC-P9-08 | XSS-safe rendering | A message containing `<img src=x onerror=alert(1)>` round-trips unmodified from the API (escaping is not the API's job — see [API.md](API.md#chat)) but renders in the chat panel as literal visible text, with no `<img>` element created in the DOM and the `onerror` handler never firing — verified live, not just asserted server-side, since the guarantee depends on Vue's `{{ }}` interpolation in `Room.vue`, not on backend sanitization | PASS |
| TC-P9-09 | Persistence across reload | Reloading the room mid-conversation re-fetches full history via `GET .../messages` on mount; all prior messages (from both participants) are present after the reload, not just ones received live | PASS |

Driven the same way as Phase 7/8: one authenticated host and one guest over CDP against real headless Chrome, asserting on live DOM state (chat panel content, unread badge text, sender labels) after each action rather than only the HTTP response. No new bugs were found in the application itself during this pass.

## Phase 10 Verification Checklist (Participants Panel)

Verified 2026-09-03, mcsirshad95@gmail.com. Entirely a frontend feature — no backend routes or migrations were added, so the automated suite is unaffected (still 98/98, 289 assertions, re-run to confirm no regression). Host controls in the panel reuse the exact same `muteParticipant()`/`removeParticipant()` functions and `/participants/{id}/mute`/`/remove` endpoints already covered by Phase 7's `MeetingControlsTest.php`/`MeetingControlsAuthorizationTest.php`.

| ID | Test | Expected | Status |
|----|------|----------|--------|
| TC-P10-01 | Live participant count | The "Participants" toggle button reads `Participants (N)`, `N` = everyone currently in the call including self; with a host + 2 guests connected, all three clients show `(3)` | PASS |
| TC-P10-02 | Roster with mic/camera/role indicators | Opening the panel lists every participant (self first, labeled "You") with a role badge (Host/Guest) and live "Mic on/off · Camera on/off" text | PASS |
| TC-P10-03 | Host-only inline controls | Mute/Remove buttons render per-row only for the host viewing the panel, and only on other participants' rows (never on the host's own "You" row); a non-host participant's panel shows no such buttons at all | PASS |
| TC-P10-04 | Live-updating indicators | A participant toggling their own mic is reflected in real time in every other open panel (same `media-state` whisper the video tiles already consume — no new signaling needed) | PASS |
| TC-P10-05 | Inline mute from the panel | Clicking a row's "Mute" button force-mutes that specific participant (and only that one) — verified by targeting the first of two guest rows and confirming the *other* guest was unaffected | PASS |
| TC-P10-06 | Inline remove from the panel | Clicking a row's "Remove" button (behind the existing `confirm()` prompt) disconnects that participant and redirects them; the live count and roster drop for every remaining connected client, not just the host who clicked it | PASS |

Driven the same way as Phase 7/8/9: one authenticated host and two guests over CDP against real headless Chrome, asserting on live DOM state (button labels, panel row text) after each action. One test-harness-only gotcha surfaced here (not an application bug): `removeParticipant()`'s native `window.confirm()` genuinely blocks headless Chrome's renderer thread with no on-screen way to dismiss it, hanging the CDP connection — the fix is to override `window.confirm = () => true` before triggering the click, the same workaround Phase 7's own testing already used for `endMeetingForAll()`/`deleteMeeting()`.

## Phase 11 Verification Checklist (Waiting Room)

Verified 2026-09-03, mcsirshad95@gmail.com.

| ID | Test | Expected | Status |
|----|------|----------|--------|
| TC-P11-00 | Automated suite | `php artisan test` passes | PASS (112/112 tests, 344 assertions — adds `MeetingWaitingRoomTest.php`, 14 cases) |
| TC-P11-01 | Non-host placed in the waiting room when enabled | With `waiting_room_enabled: true`, a non-host joiner (authenticated or guest) gets `status: waiting` and sees "You're in the waiting room..." instead of entering the call; the host still bypasses it entirely | PASS |
| TC-P11-02 | Live notification to the host | The moment someone lands in the waiting room, the host's already-open Room.vue shows it immediately — badge count on the "Waiting Room" toggle button, and the participant listed by name in the panel — with no page refresh or re-fetch needed | PASS |
| TC-P11-03 | Admit | Clicking "Admit" on a waiting participant's row lets them in; *their own tab* (still sitting on the waiting screen) transitions to the room automatically within moments, with no action needed on their end — driven by the new `waiting-room.{uuid}` channel, since a `waiting` participant can't subscribe to the main signaling channel to receive this any other way | PASS |
| TC-P11-04 | Reject | Clicking "Reject" turns the waiting participant away; their screen shows a terminal "Not admitted" message, and any further `/join` attempt for them is blocked (`403`), matching how `removed` participants are already blocked | PASS |
| TC-P11-05 | Reconnect skips the waiting room | A participant previously admitted (authenticated user matched by session, or guest matched by their held `guest_token`) who reconnects — a fresh page load and a second "Join meeting" click — goes straight into the room without waiting a second time | PASS |
| TC-P11-06 | Live per-meeting toggle | Disabling the waiting room mid-meeting via the host toolbar takes effect immediately: the next guest to join goes straight into the room, no waiting screen at all | PASS |

Driven the same way as Phase 7–10: real headless Chrome over CDP, three independent profiles this run (host, an authenticated participant, and a true anonymous guest) — the guest specifically needed its own Chrome profile rather than reusing the authenticated participant's, since Chrome shares cookies across tabs within one profile and a "guest" tab sharing a profile with a signed-in user would silently join as that user instead of anonymously. Asserted on live DOM state (waiting-room panel content, badge counts, the participant's own screen transitioning without any click on their end) rather than only HTTP responses. No new application bugs were found during this pass.

## Phase 12 Verification Checklist (Scheduling)

Verified 2026-09-03, mcsirshad95@gmail.com.

| ID | Test | Expected | Status |
|----|------|----------|--------|
| TC-P12-00 | Automated suite | `php artisan test` passes | PASS (116/116 tests, 357 assertions — adds 4 cases to `MeetingCreationTest.php`) |
| TC-P12-01 | Duration persists and displays | Creating a meeting with `duration_minutes` (e.g. 90) persists it and the dashboard shows it formatted (e.g. "1h 30m"); editing an existing meeting pre-fills the field | PASS |
| TC-P12-02 | Duration bounds enforced server-side | `duration_minutes` outside 1-1440 is rejected `422` with a field-specific error, independent of the client-side `min`/`max` on the `<input type="number">` (verified by disabling native HTML5 validation and hitting the API directly) | PASS |
| TC-P12-03 | Dashboard groups into Today/Tomorrow/Upcoming/Past | A meeting scheduled today lands under "Today" (along with any instant meeting with no `scheduled_at`, since it's meant to start immediately); tomorrow's date under "Tomorrow"; anything later under "Upcoming"; ended/cancelled meetings stay under "Past", unchanged from Phase 3. Sections render in that fixed order regardless of which groups are empty | PASS |
| TC-P12-04 | "Live now" unaffected by regrouping | The existing Phase 3 "Live now" section (a status, not a date group) still renders above the date-grouped sections and is unaffected by this phase's changes | PASS (unchanged code path, not re-verified live this pass — no live meeting was part of this test run) |
| TC-P12-05 | `MeetingScheduled` event | Creating a meeting with a future `scheduled_at` dispatches `App\Events\MeetingScheduled`; an instant meeting (no `scheduled_at`) does not | PASS (asserted via `Event::fake()` in the automated suite; this is a server-side extension point with no listener yet, so there's nothing further to observe live — see [ARCHITECTURE.md](ARCHITECTURE.md#notifications)) |

Driven via a single real headless Chrome profile over CDP (no multi-party signaling involved — this phase is dashboard-only). Registered a fresh host, created four meetings (today/tomorrow/+5 days/instant) with distinct durations, and asserted on live DOM state: section headings and their order, which meeting landed in which section, the formatted duration text, the edit form's pre-filled value, and the inline validation error text for an out-of-bounds duration. One test-harness gotcha hit along the way: the section `<h2>` headings carry a CSS `uppercase` text-transform, which `element.innerText` (unlike `textContent`) reflects — the verification script's string matches had to target the transformed case. No application bugs were found during this pass.

## Phase 13 Verification Checklist (Meeting History)

Verified 2026-09-03, mcsirshad95@gmail.com.

| ID | Test | Expected | Status |
|----|------|----------|--------|
| TC-P13-00 | Automated suite | `php artisan test` passes | PASS (123/123 tests, 402 assertions — adds `MeetingHistoryTest.php`, 7 cases) |
| TC-P13-01 | Duration accumulates on leave | A participant's `duration_seconds` increases by their session length when they call `/leave`; reconnecting and leaving again *adds* to the same total rather than overwriting it | PASS |
| TC-P13-02 | Duration accumulates on host removal | Host-removing a joined participant also finalizes their `duration_seconds`, the same as a self-initiated `/leave` | PASS |
| TC-P13-03 | No phantom duration for a rejected/never-joined participant | Rejecting a `waiting` participant (who never had a `joined_at`) leaves `duration_seconds` at 0 — confirms the accumulation only fires for an actual in-call session | PASS |
| TC-P13-04 | Ending a meeting finalizes every still-present participant | A participant still `joined`, `admitted`, or `waiting` when the host ends the meeting is swept to `left` with `left_at` set (and `duration_seconds` accumulated if they'd actually joined) — verified live: an anonymous guest who joined and left mid-meeting shows a real, non-zero `duration_seconds` after the meeting ends, entirely through real HTTP round-trips (no manual timestamp seeding for this part) | PASS |
| TC-P13-05 | History page lists past meetings | `/history` (new nav link) lists every `ended`/`cancelled` meeting, newest first, each showing status, end date, and actual call duration (`ended_at - started_at`, e.g. "2h") | PASS |
| TC-P13-06 | Expandable per-meeting participant roster | Clicking a meeting expands it to show every participant who ever had a row for it — name, role, final status, and their accumulated duration (e.g. "1h 30m", "15 min") — lazy-loaded via the new `GET /meetings/{meeting}/participants` endpoint and cached client-side so re-expanding doesn't re-fetch | PASS |
| TC-P13-07 | Host-only authorization | A guest (no session) gets `401` and a signed-in non-host gets `403` from `GET /meetings/{meeting}/participants` | PASS |

Driven via a single real headless Chrome profile over CDP. Two complementary approaches: (1) a fully real end-to-end flow — register a host, create+start a meeting, join as a true anonymous guest (`fetch` with `credentials: 'omit'` so the host's own session cookie already in that browser profile isn't accidentally sent, proving the "guest" request really is unauthenticated), leave, end the meeting, then confirm it and the guest's real (sub-minute) duration appear correctly on `/history`; (2) directly seeding a second meeting's participant rows via `artisan tinker` with hour-scale durations to visually confirm the "Xh Ym" formatting and the expand/collapse interaction, which would otherwise require an impractically long real-time wait. One cosmetic issue was caught and fixed during this pass: the meeting-summary line's CSS `capitalize` class was capitalizing the whole line, turning "0 min" into "0 Min" — fixed by capitalizing only the status word explicitly instead of relying on a blanket class over the full text. No application bugs were found.

## Phase 14 (Recording, design-first)

No verification checklist — this phase shipped no code (see [RECORDING.md](RECORDING.md)). Its "manual testing" is the design document itself being internally consistent with the already-shipped P2P mesh architecture ([WEBRTC.md](WEBRTC.md#model-p2p-mesh-mvp)) and with the SFU-migration trigger it defers to ([WEBRTC.md](WEBRTC.md#when-an-sfu-becomes-necessary)); `php artisan test` and `npm run build` were re-run after the documentation changes to confirm the doc-only diff didn't touch anything that affects either.

## Phase 15 Verification Checklist (Admin Panel)

Verified 2026-09-03, mcsirshad95@gmail.com.

| ID | Test | Expected | Status |
|----|------|----------|--------|
| TC-P15-00 | Automated suite | `php artisan test` passes | PASS (136/136 tests, 455 assertions — adds `tests/Feature/Admin/{AdminAuthorizationTest,AdminUsersTest,AdminMeetingsTest,AdminStatsTest}.php` (13 cases) plus one to `LoginTest.php`) |
| TC-P15-01 | No admin UI for a non-admin | A freshly registered user sees no "Admin" nav link and is client-side redirected to `/dashboard` if they navigate to `/admin` directly | PASS |
| TC-P15-02 | Granting admin access | `php artisan admin:grant {email}` promotes an existing user; after their next page load, the "Admin" nav link appears and `/admin` is reachable | PASS |
| TC-P15-03 | Users tab | Lists every user with their meeting count; Disable/Enable toggles `is_disabled` and updates the row's badge immediately | PASS |
| TC-P15-04 | Disabled account blocked at login | A disabled user's login attempt is rejected with a "This account has been disabled." error, stays on the login page | PASS |
| TC-P15-05 | Meetings tab | All/Active/History filter chips over every meeting across every host (not just the admin's own); Delete removes a meeting from the list | PASS |
| TC-P15-06 | Overview tab | Shows live user/meeting/participant counts matching what the Users and Meetings tabs actually contain | PASS |
| TC-P15-07 | Server-side authorization | A guest gets `401` and a non-admin gets `403` from every `/admin/*` endpoint; an admin succeeds | PASS (automated, `AdminAuthorizationTest`) |

Driven via two real headless Chrome profiles over CDP — one for the admin, a separate one for the target user being disabled (registering the target in a second *tab* on the admin's own profile was tried first and hit exactly the cookie-sharing gotcha documented for Phase 10/11: it silently clobbered the admin's session cookie, since cookies are shared across every tab in one profile — fixed by giving the target its own profile/port, matching established practice). Admin.vue fetches each tab's data once on mount (same no-live-polling pattern as `Dashboard.vue`/`History.vue`), so the verification script reloads `/admin` after registering the target user or creating a test meeting before asserting either shows up — not a bug, just matching the page's actual (and intentional) refresh model. No application bugs were found.

## Phase 16 Verification Checklist (Security Hardening)

Verified 2026-09-03, mcsirshad95@gmail.com. This phase is an audit, not a new feature — see [SECURITY.md](SECURITY.md#phase-16-audit-2026-09-03) for the full write-up of what was reviewed and what was found. The table below covers only what actually changed.

| ID | Test | Expected | Status |
|----|------|----------|--------|
| TC-P16-00 | Automated suite | `php artisan test` passes | PASS (137/137 tests, 467 assertions — adds `tests/Feature/RateLimitingTest.php` (3 cases) and one to `RegistrationTest.php`) |
| TC-P16-01 | General rate limit wired to previously-unthrottled routes | Every route, including ones with no throttle before this phase (meeting CRUD, admin endpoints, meeting-code lookup), carries `X-RateLimit-Limit: 120` | PASS (automated + spot-checked live: `curl -sI` against a running dev-server route shows the header) |
| TC-P16-02 | Registration throttle actually caps repeated attempts | 11 rapid registrations from one client: the first 10 succeed, the 11th is `429` | PASS (automated — this is the test that caught BUG-011) |
| TC-P16-03 | A single legitimate registration/login/lookup/join still works normally | The new throttles don't interfere with normal single-attempt usage | PASS (live, over CDP — registered a user, logged in, looked up a meeting, joined it, all in one pass with no unexpected `429`) |
| TC-P16-04 | XSS/SQLi/CSRF re-confirmed by grep, not just memory | `grep -rn 'v-html\|innerHTML' resources/js/` and `grep -rn 'DB::raw\|whereRaw\|selectRaw\|DB::statement' app/` both return nothing; no CSRF `$except`/bypass exists anywhere in `bootstrap/app.php` or `app/Http/` | PASS |

Rate-limiting behavior was verified two ways: the new `RateLimitingTest.php` asserts the `X-RateLimit-Limit` header is present on routes that had none before (a cheap, fast regression check — deliberately not firing 120+ requests in a test just to trip the general ceiling, since Laravel's own `ThrottleRequests` middleware is already well-tested and the real risk being guarded against is someone accidentally removing `throttleApi()` from `bootstrap/app.php`), and a single real headless-Chrome pass over CDP confirmed the new throttles don't break any normal single-attempt user flow. No other application bugs were found beyond the two logged in [BUGS.md](BUGS.md) (BUG-011, and the `SESSION_SECURE_COOKIE` documentation gap noted in SECURITY.md's audit write-up).

## Phase 17 Verification Checklist (Testing)

Verified 2026-09-03, mcsirshad95@gmail.com. Like Phase 16, this is a consolidation/audit phase rather than a new feature, so most of the "verification" here is the coverage audit itself (see the [Test Coverage Matrix](#test-coverage-matrix) above), not a new user-facing flow to click through.

| ID | Test | Expected | Status |
|----|------|----------|--------|
| TC-P17-00 | Automated suite | `php artisan test` passes | PASS (149/149 tests, 498 assertions — adds `tests/Unit/{Policies/MeetingPolicyTest,Models/MeetingParticipantTest,Models/MeetingMessageTest}.php` (9 cases) and one 404-boundary case to `MeetingAuthorizationTest.php`; removes the never-touched `tests/Unit/ExampleTest.php` skeleton stub) |
| TC-P17-01 | Real `tests/Unit` coverage exists | Before this phase, `tests/Unit` held only the default Laravel skeleton's placeholder (`assertTrue(true)`) — a real gap for a roadmap item literally named "Unit... test suites". Now covers `MeetingPolicy` (all six abilities) and two pure model methods, framework-free (no DB, no HTTP kernel) | PASS |
| TC-P17-02 | Coverage audit finds no functional gap | Every controller method cross-referenced against existing Feature test files (`grep -rn 'public function'` vs. test file inventory); one minor gap found (unknown-`meeting_uuid` returning `404` vs `403` on the host's own CRUD endpoints was implicitly relied on via Laravel's route-model-binding but never explicitly asserted) and closed | PASS |
| TC-P17-03 | Stale documentation corrected | `TESTING.md` and `ARCHITECTURE.md` both referenced a `tests/Browser` directory that was never actually created (the project chose manual CDP verification instead, back in Phase 5) — corrected to state and explain that choice rather than pointing at a directory that doesn't exist | PASS |
| TC-P17-04 | Manual WebRTC procedures are already complete | Every phase from 5 through 15 that touched the browser has its own Verification Checklist in this file already | PASS (no new work — this bullet was already satisfied continuously, phase by phase; confirmed by re-reading every existing checklist section in this file during this pass) |

A genuine methodology catch during this pass, not logged as an app bug since no application code was ever wrong — a *test-writing* mistake caught before it shipped: an early draft of the `MeetingPolicy` unit test constructed fixtures with `new User(['id' => 1])`, assuming constructor mass-assignment would set `id`. It silently didn't — `id` isn't in `User`'s `#[Fillable(...)]` list — so both the "host" and "meeting" fixtures ended up with `null` ids, and the policy check (`null === null`) passed for *any* pairing, including ones that shouldn't have matched. Caught by writing a probe test that intentionally used a mismatched id and asserting `assertFalse` — it came back `true`. Fixed by using `forceFill()` for test fixtures throughout (the same pattern the app itself uses for non-fillable internal fields), and left as a code comment in `MeetingPolicyTest.php` so the next person writing a similar fixture doesn't repeat it.

## Phase 18 Verification Checklist (Reactions)

Verified 2026-09-04, mcsirshad95@gmail.com. Post-MVP addition — no backend routes or Feature tests, since reactions are a purely client-side whisper (see [WEBRTC.md](WEBRTC.md#reactions-signaling)); verification is entirely live, the same as screen sharing (Phase 8).

| ID | Test | Expected | Status |
|----|------|----------|--------|
| TC-P18-00 | Backend/build unaffected | `php artisan test` (149/149) and `npm run build` both pass — this feature touches no backend code | PASS |
| TC-P18-01 | Sender sees their own reaction | Clicking an emoji in the "React" picker spawns the floating animation locally immediately (whispers aren't echoed back to their own sender, so this has to be a local spawn, not a round-trip) | PASS |
| TC-P18-02 | Remote participant sees it, with the sender's name | A reaction sent by one participant appears on another's screen, tagged with the sender's actual name — verified in both directions (host→guest and guest→host) with two independent Chrome profiles | PASS |
| TC-P18-03 | Auto-fade | The floating reaction removes itself from the DOM after its ~2.5s animation, without user interaction | PASS |
| TC-P18-04 | Picker toggle | The "React" button opens/closes a 5-emoji picker (👍❤️😂👏🎉) positioned above it; picking an emoji also closes the picker | PASS |

Driven over CDP with two separate Chrome profiles (host + a true anonymous guest, avoiding the shared-profile cookie gotcha documented since Phase 10/11). Asserted on live DOM text content (`document.body.innerText` containing the emoji + sender name) rather than only visual inspection, plus one screenshot for a sanity check on the animation/positioning — the video tile fills the viewport at this window size, so the screenshot needed an explicit scroll to bring the control bar and reaction into frame; not a bug, just a fixed-size headless-window framing detail. No application bugs were found.

## Phase 19 Verification Checklist (Raise Hand)

Verified 2026-09-04, mcsirshad95@gmail.com. Post-MVP addition — no backend routes or Feature tests, same reasoning as Phase 18: a purely client-side whisper (see [WEBRTC.md](WEBRTC.md#hand-raise-signaling)).

| ID | Test | Expected | Status |
|----|------|----------|--------|
| TC-P19-00 | Backend/build unaffected | `php artisan test` (149/149) and `npm run build` both pass — no backend code touched | PASS |
| TC-P19-01 | Raising reflects locally and remotely | Clicking "Raise hand ✋" flips the button to "Lower hand", shows a ✋ badge on the participant's own tile/panel row, and the same badge appears on the other participant's screen in real time, next to the raiser's correct name | PASS |
| TC-P19-02 | Lowering reflects locally and remotely | Clicking "Lower hand" removes the badge on both ends in real time | PASS |
| TC-P19-03 | Visible to any participant, not host-only | A non-host guest raising their hand is visible on the host's screen, confirming the badge isn't gated behind `isHost` | PASS |

Driven over CDP with two independent Chrome profiles (host + a true anonymous guest). One test-methodology catch during this pass: the control-bar button's own label, "Raise hand ✋", contains the ✋ glyph regardless of state, so a naive `document.body.innerText.includes('✋')` check can never go false — it always finds the button's own static text. Fixed by counting only DOM leaf nodes whose *entire* `textContent` is exactly `✋` (which matches only the tile/panel badges, never the button label), verified by first reproducing the false failure and confirming zero such exact-match nodes existed after lowering the hand, before trusting the fix. No application bugs were found.

## Phase 20 Verification Checklist (Gallery/Speaker View)

Verified 2026-09-04, mcsirshad95@gmail.com. Post-MVP addition — no backend routes or Feature tests, same reasoning as Phases 18/19: purely client-side. See [WEBRTC.md](WEBRTC.md#active-speaker-detection) for why the *active-speaker-detection accuracy* itself is out of scope for this harness (every fake mic produces identical synthetic audio, so no participant is ever actually louder than another here) — what's verified below is the layout/structural behavior instead.

| ID | Test | Expected | Status |
|----|------|----------|--------|
| TC-P20-00 | Backend/build unaffected | `php artisan test` (149/149) and `npm run build` both pass — no backend code touched | PASS |
| TC-P20-01 | Toggle changes the actual layout | Clicking "Speaker view" removes the gallery `<div class="grid">` entirely (not just relabels the button) and replaces it with a main-stage-plus-thumbnail-strip layout | PASS |
| TC-P20-02 | Remote participant becomes the main tile | With one peer in the call, that peer's video renders as the large main-stage element (non-muted `<video>`, distinct from the small `w-32` thumbnails) | PASS |
| TC-P20-03 | Local video stays in the thumbnail strip | "You" appears in the small thumbnail strip in speaker mode, never as the main tile, while at least one peer is present | PASS |
| TC-P20-04 | Toggling back restores Gallery | Clicking "Gallery view" restores the original 2-tile CSS grid | PASS |

Driven over CDP with two independent Chrome profiles (host + a true anonymous guest). No application bugs were found — all assertions passed on the first run.

## Phase 21 Verification Checklist (Pin/Spotlight Participant)

Verified 2026-09-04, mcsirshad95@gmail.com. Spotlight has real backend routes/tests (unlike Phases 18-20); Pin is purely client-side (no backend involvement) — see [WEBRTC.md](WEBRTC.md#active-speaker-detection).

| ID | Test | Expected | Status |
|----|------|----------|--------|
| TC-P21-00 | Automated suite | `php artisan test` passes | PASS (152/152 tests, 511 assertions — adds 3 cases to `MeetingControlsTest.php` plus boundary assertions to `MeetingControlsAuthorizationTest.php`) |
| TC-P21-01 | Pin selects a specific peer, locally only | With 2 peers present, pinning one makes it the Speaker-view main tile on the pinning viewer's own screen; a different participant's screen is unaffected | PASS |
| TC-P21-02 | Spotlight overrides Pin | Host spotlighting a *different* peer than the one currently pinned correctly becomes the main tile instead — proving the priority order (spotlight > pin), not just "whichever's available" | PASS |
| TC-P21-03 | Spotlight broadcasts to every participant in real time | All three participants (host + 2 guests) see the spotlighted participant's "· spotlighted" label appear, including the spotlighted participant's own tile | PASS |
| TC-P21-04 | Clearing the spotlight propagates too | `POST .../spotlight/clear` removes the label on every participant's screen in real time | PASS |
| TC-P21-05 | Server-side authorization | A guest gets `401` and a non-host participant gets `403` from both spotlight endpoints; targeting a participant from a different meeting returns `404` | PASS (automated, `MeetingControlsAuthorizationTest`) |

Driven over CDP with **three** participants across three independent Chrome profiles (host + two true anonymous guests) — two peers were necessary to prove the main-tile selection logic actually *differentiates* between candidates, since with only one peer present any selection logic trivially resolves to "the only option" regardless of whether pin/spotlight priority is implemented correctly. One real gap found and fixed during this pass: the spotlighted participant's own screen showed no indication they were the one spotlighted, since `activePeerTile`'s selection logic (and the tile labels showing "· spotlighted") only ever look at *peers*, and a participant is never their own peer — so being spotlighted was, from the spotlighted person's own point of view, entirely invisible. Fixed by adding the same "· spotlighted" label to the local tile (in both Gallery and Speaker view, plus the Participants panel's "You" row) whenever `spotlightedId === myId`. Caught by a test assertion checking the spotlighted guest's *own* screen, not just the host's/other guest's.

## Phase 22 Verification Checklist (Co-Host Promotion)

Verified 2026-09-04, mcsirshad95@gmail.com.

| ID | Test | Expected | Status |
|----|------|----------|--------|
| TC-P22-00 | Automated suite | `php artisan test` passes | PASS (156/156 tests, 531 assertions — adds 4 cases to `MeetingControlsTest.php`, boundary assertions to `MeetingControlsAuthorizationTest.php`; one existing `Unit/Policies/MeetingPolicyTest.php` assertion had to move to Feature-test coverage instead, since `manageSettings`/`manageParticipants`'s new co-host check needs a real database connection the framework-free Unit suite doesn't have) |
| TC-P22-01 | Before promotion: no host-level access | A signed-in, non-host participant sees no host toolbar (Lock meeting etc.) and no per-peer Mute/Spotlight/Make-co-host buttons on other participants' rows | PASS |
| TC-P22-02 | Promotion grants real authority, live | The host promoting a participant makes the promoted side see a "made you a co-host" notice, the host toolbar, the Waiting Room control, and their own role update to "(Co-host)" — visible to both the promoted participant and the host, in real time, no reconnect | PASS |
| TC-P22-03 | Co-host's authority has the right boundary | The promoted co-host sees no "End meeting for all" button, and a direct `POST .../end` API call as the co-host is rejected `403` — proving `manageLifecycle` correctly did *not* widen even though `manageParticipants`/`manageSettings` did | PASS |
| TC-P22-04 | Demotion revokes it, live | The host demoting the co-host removes their host-level UI in real time, with a "removed your co-host access" notice | PASS |
| TC-P22-05 | Guests can't be promoted | `POST .../promote` on a guest participant returns `422` | PASS (automated) |
| TC-P22-06 | A co-host cannot appoint another co-host | `POST .../promote` as a co-host (not the actual host) returns `403` — `manageCoHosts` deliberately did not widen the way `manageSettings`/`manageParticipants` did | PASS (automated, and live: participant successfully muted a third party post-promotion but was blocked from promoting anyone themselves) |

Driven over CDP with two independent Chrome profiles, both **authenticated** (host + a signed-in participant) — a co-host must be a real registered user, since guests are rejected. A genuine regression was caught and fixed mid-pass, not just a test-script mistake: widening `MeetingPolicy::manageSettings`/`manageParticipants` to check for a co-host DB row broke `tests/Unit/Policies/MeetingPolicyTest.php`'s existing "non-host fails every ability" case, since that test is deliberately framework-free (no database connection resolver bootstrapped at all) and the new co-host lookup needs one for any *non*-host caller. Fixed by trimming that Unit test to the abilities that still resolve without ever touching the database (the host-passes branch stays fully covered, since it short-circuits before the query), and pointing to the Feature-test coverage that now exercises the co-host branch with a real database instead.

## Phase 23 Verification Checklist (Chat File/Image Attachment)

Verified 2026-09-04, mcsirshad95@gmail.com.

| ID | Test | Expected | Status |
|----|------|----------|--------|
| TC-P23-00 | Automated suite | `php artisan test` passes | PASS (165/165 tests, 562 assertions — adds 9 cases to `Meetings/MeetingChatTest.php`) |
| TC-P23-01 | Frontend build | `npm run build` passes | PASS |
| TC-P23-02 | Sending an image attachment | Attaching a real PNG via the native file picker (`input[type=file]`, driven via a new `DOM.setFileInputFiles` CDP extension since a headless browser has no OS file-picker dialog to simulate a click through) and sending shows the filename in a pre-send preview chip | PASS |
| TC-P23-03 | Recipient renders the image inline | The other participant's chat pane shows a real `<img>` for the message, not a download chip | PASS |
| TC-P23-04 | Recipient can actually fetch the bytes | The recipient's own session fetches the `<img>`'s `src` and gets back `200`, `image/png`, and a non-zero-byte blob — proving the download endpoint's participant-gating actually authorizes them, not just that a URL string is present | PASS |
| TC-P23-05 | Non-image attachment renders as a download chip | A guest attaching and sending a PDF renders on the host's side as a 📎 filename/size chip, with no `<img>` inside it | PASS |
| TC-P23-06 | Cross-participant download of another's attachment | The host can fetch the guest-uploaded PDF too (`200`) — both are still active participants of the same meeting | PASS |

Driven over CDP with two independent Chrome profiles (host + a true anonymous guest). A real backend bug was found and fixed mid-pass (not just a test-script issue): the initial run failed at TC-P23-03 even though the database correctly showed the attachment fields populated after upload — `ChatMessageEvent::broadcastWith()` had its own array literal that had never been updated when attachment support was added to `MeetingMessageController`, so the HTTP response included the attachment but the real-time broadcast that `Room.vue` actually renders from did not. Fixed by extracting `MeetingMessage::toChatPayload()` as the single shared serialization method for both. See [WEBRTC.md](WEBRTC.md#chat-signaling). A second issue was in the verification script itself, not the app: it never opened the guest's own Chat panel before checking for `aside img`, so the check would have failed regardless of the backend — fixed by opening Chat on both profiles before sending.
