# BUGS.md

Every discovered bug is logged here and stays tracked until resolved. Format:

```markdown
## BUG-001

Status: OPEN | DONE

Description:
<what's wrong>

Steps:
1. ...
2. ...

Expected:
<expected behavior>

Actual:
<actual behavior>

Fix:
<what was changed, once fixed>

Test:
<how the fix was verified>
```

## BUG-001

Status: DONE

Description:
Laravel 13 (installed via `composer create-project laravel/laravel`) requires PHP >= 8.4.1, but the initial `Dockerfile` used `php:8.3-fpm`. `app`/`reverb` containers fatal-errored on every request (`Composer detected issues in your platform`).

Steps:
1. Build the `app`/`reverb` images from a `php:8.3-fpm` base.
2. Start the stack, hit any artisan command or HTTP route.

Expected:
Laravel boots normally.

Actual:
Fatal platform-check error from Composer's autoloader.

Fix:
Bumped `Dockerfile` base image to `php:8.4-fpm` and `composer.json`'s `php` constraint to `^8.4`.

Test:
Rebuilt images; `docker compose exec app php artisan test` and `GET /up` succeed.

---

## BUG-002

Status: DONE

Description:
Default Docker host ports (8000, 3306, 8080, 5173) collide with several other Docker projects already running on this development machine, so `docker compose up -d` failed with "port is already allocated".

Steps:
1. `docker compose up -d` with default `.env.example` ports on a machine already running other Dockerized Laravel projects.

Expected:
Stack starts.

Actual:
`failed to set up container networking: ... port is already allocated`.

Fix:
`.env.example` keeps the conventional defaults (8000/3306/8080/5173) for a clean checkout; this machine's local `.env` overrides them to 8300/3399/8301/5473. `docker-compose.yml` exposes `APP_PORT`, `DB_FORWARD_PORT`, `REVERB_PORT`, `VITE_PORT` for exactly this purpose — documented in README.md.

Test:
`docker compose up -d` succeeds with no port conflicts; all containers reach `Up`/`healthy`.

---

## BUG-003

Status: DONE

Description:
After rebuilding the `app` image and recreating the container, `nginx` kept proxying to the old container IP and returned `502 Bad Gateway` for every route (`connect() failed (111: Connection refused)`), because nginx resolves the `app` upstream hostname once at worker startup and doesn't re-resolve it when the container behind that name changes.

Steps:
1. `docker compose up -d` with `nginx` already running.
2. Rebuild and recreate the `app` container (new IP on the compose network).
3. Request any route through nginx.

Expected:
Request reaches the new `app` container.

Actual:
502 Bad Gateway; nginx logs show it connecting to the stale IP.

Fix:
No code fix needed — restart `nginx` (`docker compose restart nginx`) after recreating `app`/`reverb` so it re-resolves the upstream. Noted here for future sessions rebuilding the app image.

Test:
`curl http://localhost:8300/` and `/api/v1/health` return 200 after the nginx restart.

---

## BUG-004

Status: DONE

Description:
`GET /` and `GET /up` returned HTTP 500 (`tempnam(): file created in the system's temporary directory`) even though the health JSON endpoint worked. The PHP-FPM pool runs as `www-data`, but `storage/` and `bootstrap/cache/` (bind-mounted from the host) were owned by the host user (uid 1000) with mode 755 — `www-data` (a different uid inside the container) couldn't write compiled views/cache there.

Steps:
1. Bring up the stack with the default `php:8.4-fpm` image (unmodified `www-data` uid).
2. Request any route that writes to `storage/framework/views` (i.e. any Blade view).

Expected:
Page renders.

Actual:
500 error from a failed `tempnam()` write.

Fix:
`Dockerfile` now remaps `www-data` to the same uid/gid as the host user (`UID`/`GID` build args, default 1000) so the PHP-FPM worker can write to the bind-mounted `storage/`/`bootstrap/cache/` directories without loosening their permissions.

Test:
Rebuilt `app`/`reverb` images; `GET /`, `GET /up`, and `GET /api/v1/health` all return 200; `php artisan test` passes.

---

## BUG-005

Status: DONE

Description:
`php artisan test` / `vendor/bin/phpunit` ran with `APP_ENV=local` instead of `testing`, even though `phpunit.xml` sets `<env name="APP_ENV" value="testing"/>`. This made every stateful-session-dependent auth test (register, login, logout) fail with `419 CSRF token mismatch`, because `PreventRequestForgery::runningUnitTests()` (which normally bypasses CSRF in tests) evaluated false.

Steps:
1. `docker-compose.yml`'s `app` service declared `environment: - APP_ENV=${APP_ENV:-local}`, which sets `APP_ENV` as a real OS-level environment variable inside the container.
2. PHP auto-populates `$_SERVER['APP_ENV']` from that OS env var at process start.
3. Run `php artisan test`. PHPUnit's `<env>` directive sets `putenv()`/`$_ENV`, but never touches `$_SERVER`.
4. Laravel's `Illuminate\Support\Env` (via `vlucas/phpdotenv`'s adapter chain) reads `$_SERVER` ahead of `$_ENV`/`putenv()`, so `env('APP_ENV')` kept resolving to the stale `local` value.

Expected:
`app()->environment()` reports `testing` during the test run, matching `phpunit.xml`.

Actual:
`app()->environment()` reported `local`; CSRF verification stayed active for POST/PUT routes reached via `EnsureFrontendRequestsAreStateful`, so every stateful auth test got a 419.

Fix:
Removed the redundant `environment: - APP_ENV=${APP_ENV:-local}` line from the `app` service in `docker-compose.yml` — Laravel already reads `APP_ENV` from the bind-mounted `.env` file directly, so the container-level override added no functional value while silently poisoning `$_SERVER` for every process in that container, including test runs. Also added `force="true"` to `phpunit.xml`'s `APP_ENV` `<env>` entry as defense in depth.

Test:
`php artisan test` now runs with `APP_ENV=testing`; all `tests/Feature/Auth/*` tests pass without CSRF errors.

---

## BUG-006

Status: DONE

Description:
`tests/Feature/Auth/LogoutTest.php`'s `assertGuest()` (no guard argument) failed even though `AuthenticatedSessionController::destroy()` correctly logged the user out. Root cause: the `/api/v1/auth/logout` route runs through `auth:sanctum` middleware, and Laravel's `Authenticate` middleware calls `Auth::shouldUse('sanctum')` on successful authentication, which flips the *default* guard to `sanctum` for the rest of that request. Sanctum's `RequestGuard` memoizes its resolved user per guard instance, and because a single test process reuses the same container (and therefore the same cached `sanctum` guard instance) across the test's simulated login and logout requests, `assertGuest()` (which checks the default guard) read the stale memoized answer. This is a test-harness-only artifact — real HTTP requests are separate PHP processes with no cross-request guard caching, so production logout is unaffected.

Steps:
1. In a Feature test, log in a user (establishing a session), then POST to `/api/v1/auth/logout`.
2. Call `$this->assertGuest()` (default guard) afterward.

Expected:
Assertion passes — the user is no longer authenticated.

Actual:
Assertion failed: the default guard resolved to the `sanctum` `RequestGuard`, whose memoized user was cached before the controller's `Auth::guard('web')->logout()` call ran, and never re-resolved for the remainder of the test process.

Fix:
No application code change (the logout implementation is correct). Updated the test to assert against the concrete `web` guard — `$this->assertGuest('web')` — since that's the guard `logout()` actually targets and the one that governs real session-based auth state.

Test:
`php artisan test --filter=LogoutTest` passes.

---

## BUG-007

Status: DONE

Description:
`Meeting::start()`/`cancel()`/`end()` (in `MeetingController`) returned `200 OK` with the *unchanged* meeting — `status`, `started_at`, and `ended_at` never actually updated in the database. No exception was thrown, so this silently passed casual testing of the happy path and was only caught by the Feature tests that asserted the returned/persisted status.

Steps:
1. `Meeting`'s `#[Fillable(...)]` attribute lists only the fields a host is allowed to set from a form (`title`, `description`, `password`, `scheduled_at`, `waiting_room_enabled`, `allow_guests`) — deliberately excluding `status`/`started_at`/`ended_at`, which are internal lifecycle state, not user input.
2. `MeetingController::start()` (and `cancel()`/`end()`) called `$meeting->update(['status' => ..., 'started_at' => ...])` — `update()` is a mass-assignment call, so Eloquent silently discarded the non-fillable keys instead of throwing, because the app doesn't call `Model::shouldBeStrict()`.
3. `postJson('/meetings/{uuid}/start')` returned `200` with the stale `status`.

Expected:
The lifecycle action persists the new `status`/`started_at`/`ended_at` and returns them in the response.

Actual:
The meeting's `status` silently remained `scheduled` (or `live`, for `end()`) after a "successful" `200` response.

Fix:
Changed the three lifecycle actions in `MeetingController` to use `$meeting->forceFill([...])->save()` instead of `update()` — `forceFill()` intentionally bypasses the mass-assignment guard, which is correct here because these are internal state transitions driven by controller logic (already authorized via `Gate::authorize('manageLifecycle', ...)`), not raw user-supplied field lists. Any future phase that mutates a non-fillable column from a controller (e.g. Phase 7's host controls, Phase 4's `meeting_participants.status`) should use the same pattern rather than widening `#[Fillable]`.

Test:
`tests/Feature/Meetings/MeetingLifecycleTest.php` — `test_the_host_can_start_a_scheduled_meeting`, `test_the_host_can_end_a_live_meeting`, `test_the_host_can_cancel_a_scheduled_meeting` all assert the persisted status/timestamp changed; all pass.

---

## BUG-008

Status: DONE

Description:
A guest could never establish a WebRTC connection — `Room.vue` set the guest's `X-Guest-Token` header at `window.Echo.connector.pusher.config.auth.headers`, but that path doesn't exist at runtime; pusher-js keeps the live `auth.headers` object the `/broadcasting/auth` request actually reads from at `window.Echo.connector.options.auth.headers` instead (`pusher.config` holds transport settings like `wsHost`/`cluster`, not the auth block). Setting the header threw `TypeError: Cannot read properties of undefined (reading 'headers')`, silently caught as an unhandled promise rejection, so the guest's channel subscription never carried the token and Reverb rejected it — the guest simply never received or sent any signaling, with no visible error in the UI.

Steps:
1. Join a meeting as a guest (no Sanctum session).
2. Enter the room; observe the console for an unhandled rejection reading `Cannot read properties of undefined (reading 'headers')`.
3. Compare against a signed-in participant in a second browser — the host sees no remote tile for the guest, and the guest sees no remote tile for the host.

Expected:
The guest's `/broadcasting/auth` request carries `X-Guest-Token`, authorizes against their `meeting_participants` row (`AuthenticateBroadcastGuest`), and offer/answer/ICE exchange proceeds normally.

Actual:
The header assignment threw before the channel was ever subscribed; the guest's Echo private-channel subscription silently failed, so no `participant.announce` whisper was ever sent or received on either side.

Fix:
Changed `resources/js/pages/Room.vue` to set the header at `window.Echo.connector.options.auth.headers['X-Guest-Token']` instead of the nonexistent `connector.pusher.config.auth` path.

Test:
Caught by real-browser E2E (this bug is invisible to the PHPUnit broadcasting-auth tests, which hit `/broadcasting/auth` directly and never exercise the frontend's header-setting code) — driven via two independent headless Chrome profiles (`--use-fake-device-for-media-stream --use-fake-ui-for-media-stream`) over CDP: a host and a guest both reach `RTCPeerConnection` with live remote video tiles and zero JS errors after the fix (TC-P5-06 in TESTING.md).

---

## BUG-009

Status: DONE

Description:
Every Phase 7 host-control action (`mute`/`remove`/`lock`/`unlock`/`end`/guest-toggle) called `broadcast(new HostControlEvent(...))` from the `app` container, but the request failed server-side with `Pusher error: cURL error 7: Failed to connect to localhost port 8301` and a `500` was masked as a `200` only because `broadcast()` (not `broadcast()->toOthers()->` with error propagation suppressed) actually let the `BroadcastException` bubble — in practice every host-control endpoint's *database* write still succeeded (visible via a direct `artisan tinker` query or a second `GET`), but nothing was pushed to connected clients in real time, so a muted/removed participant's browser never reacted. This had never surfaced before Phase 7 because Phase 5/6's signaling is 100% client-side Echo whispers — nothing in the app had ever called Laravel's `broadcast()` helper (which publishes over HTTP to Reverb's REST API) until this phase.

Steps:
1. `config/broadcasting.php`'s `reverb` connection `options.host`/`options.port` read `env('REVERB_HOST')` / `env('REVERB_PORT')`, i.e. `localhost:8301` — the address the *browser* uses to reach Reverb's port published on the host machine.
2. From inside the `app` container, `localhost` resolves to the container itself, not the separate `meetapp_reverb` container — there is nothing listening on `localhost:8301` inside `app`.
3. Trigger any host-control action (e.g. `POST /meetings/{uuid}/lock`) and tail `storage/logs/laravel.log`.

Expected:
The `app` container publishes the event to Reverb over the Docker-internal network (`reverb:8080`, the port `reverb:start --port=8080` actually binds inside its own container), and every subscribed client receives it within the same real-time push that Echo whispers already use.

Actual:
`local.ERROR: Pusher error: cURL error 7: Failed to connect to localhost port 8301 ... (see PusherBroadcaster.php:171)` on every host-control call; no client ever received the `.host.control` event, so force-mute/remove/end never took visible effect for anyone.

Fix:
Added `REVERB_SERVER_HOST=reverb` / `REVERB_SERVER_PORT=8080` (`.env`, `.env.example`) — the Docker-internal address, distinct from the browser-facing `REVERB_HOST`/`REVERB_PORT` used by `VITE_REVERB_*`. `config/broadcasting.php`'s reverb connection now resolves `host`/`port` from `REVERB_SERVER_HOST`/`REVERB_SERVER_PORT` with a fallback to `REVERB_HOST`/`REVERB_PORT` (so a non-Docker, single-host deployment where both addresses are identical needs no new vars).

Test:
Caught by real-browser E2E, not PHPUnit — the Feature tests use `Event::fake([HostControlEvent::class])`, which never touches the real broadcaster and so could not see this. Reproduced and verified via two headless Chrome profiles over CDP: before the fix, a host force-muting a guest left the guest's own mute button unchanged and no "muted your microphone" notice appeared; after the fix, the guest's button flips to "Unmute", the host's tile shows "· muted", and the notice text appears — same live-verification pattern confirmed for remove (guest is redirected out of the room, host's tile count drops) and end (both host and guest land back on the join screen with "This meeting has ended.").

---

## BUG-010

Status: DONE

Description:
`Room.vue`'s `toggleLock()`/`toggleGuests()` set the new state by inverting the *current* ref after the `POST` request resolved (`meetingLocked.value = !meetingLocked.value`). The host's own client also receives its own `lock`/`unlock`/`guests-enabled`/`guests-disabled` broadcast via the same `.host.control` Echo listener used to react to other host actions — and that WebSocket push frequently arrives and updates the ref *before* the still-pending HTTP response resolves. When it did, the later `!meetingLocked.value` inverted the value a second time, silently reverting the host's own UI to the opposite of what had actually just been persisted (e.g. clicking "Lock meeting" left the button reading "Lock meeting" even though the meeting was, correctly, now locked in the database and rejecting new joins).

Steps:
1. As host, click "Lock meeting" — the meeting is genuinely locked (a fresh guest's join attempt is correctly rejected).
2. Click "Unlock meeting" — with the timing-dependent double-flip, the button can still read "Lock meeting" (never having visually updated after step 1), and the click is a no-op because no button with that exact text exists.

Expected:
Clicking lock/unlock (or the guest-access toggle) always leaves the host's own button reflecting the value that was actually persisted, regardless of whether the self-broadcast echo or the HTTP response resolves first.

Actual:
Depending on which arrived first, the local flip could double-apply and desync the button label from the real `is_locked`/`allow_guests` value — confirmed via CDP: after locking, clicking the (still-labeled-incorrectly) "Unlock meeting" button found no matching element.

Fix:
`toggleLock()`/`toggleGuests()` now capture the intended target value (`wasLocked`/`wereAllowed`) *before* the `await`, and set the ref to that captured, request-scoped value afterwards instead of inverting whatever the ref happens to hold at that point — idempotent against the self-broadcast arriving first.

Test:
Caught and verified via real-browser E2E only (a timing race between a WebSocket push and a pending HTTP response isn't reachable through `Event::fake()`-based Feature tests, which never dispatch a real broadcast). Reproduced and confirmed fixed via CDP: lock → unlock → the "Unlock meeting" button is found and clicked successfully, and a fresh guest can join again afterward.

---

## BUG-011

Status: DONE

Description:
While adding rate limiting to `/api/v1/auth/register` as part of the Phase 16 security audit, the first attempt used Laravel's numeric-signature throttle (`->middleware('throttle:10,1')`), which keys by *the current authenticated user's id, falling back to IP only for guests*. Registration's whole job is to authenticate the caller as a brand-new user on success (`Auth::login($user)` in `RegisteredUserController`) — so every successful registration handed the *next* request a fresh, never-before-seen throttle key (the new user's id), never accumulating hits against any single key. The cap was silently a no-op against exactly the abuse pattern it existed to stop: scripted repeated registration from one client.

Steps:
1. Add `->middleware('throttle:10,1')` to `POST /api/v1/auth/register`.
2. From a single test client (one cookie/session jar, matching a real scripted-abuse client reusing one HTTP session), POST to `/register` 11 times in a row with different emails each time.

Expected:
The 11th request is rejected `429` — the endpoint is capped at 10/minute.

Actual:
All 11 requests succeeded with `201`, each creating a new user, because each one's throttle key (the newly-registered user's own id, set by the *previous* request's `Auth::login()`) had never been hit before.

Fix:
Replaced the numeric-signature throttle with a dedicated named limiter, `RateLimiter::for('register', fn ($request) => Limit::perMinute(10)->by($request->ip()))` (`AppServiceProvider::boot()`), keyed by IP only — immune to the auth-identity shift since it never looks at `$request->user()` at all. Route now uses `->middleware('throttle:register')`.

Test:
`tests/Feature/Auth/RegistrationTest.php::test_repeated_registration_attempts_are_rate_limited` — fires 10 successful registrations then asserts the 11th is `429` and never reaches the database. Caught this exact bug on first run (11th request returned `201`, not `429`) before the fix.

---

## BUG-012

Status: DONE

Description:
The host had no way to enter their own live meeting's room from `Dashboard.vue`. The "Live now" card (shown while a meeting's `status` is `live`) only ever had an "End meeting" button — clicking `/meetings/{uuid}/start` (or `New meeting` on an instant meeting) updates the meeting's status and shows it as live, but never navigates the host anywhere. There was no other UI path back into the room either: the only way in was manually retyping the meeting's own code into the small "Enter a meeting code" box at the top of the page.

Steps:
1. As a host, start a meeting (instant meeting or `Start` on a scheduled one).
2. Observe the "Live now" card on the dashboard.

Expected:
The host has a direct way to enter the meeting room they just started.

Actual:
Only an "End meeting" button was present; the host could never see their own participant tile, other participants who joined, or use any in-room host controls unless they happened to know to manually type their own meeting code into the join-by-code box.

Fix:
Added a "Join meeting" button to the "Live now" card in `Dashboard.vue`, next to "End meeting". It navigates to the existing `/meeting/{code}` join lobby (`enterMeeting()`, same route the join-by-code box already uses) rather than straight to `/meeting/{code}/room`, since `Room.vue` requires session data (`sessionStorage`'s `join_data_{code}`) that only the join lobby's `submitJoin()` sets — the host still sees the lobby screen and clicks "Join meeting" once more, but the server already bypasses the password/waiting-room checks for the host (`$isHost` in `MeetingJoinController::join()`), so it's a fast, one-extra-click path with no backend changes needed.

Test:
Reported by the user, live over CDP: registered a host, started a meeting via a direct API call (bypassing the dashboard's own create form to isolate the bug), confirmed the "Join meeting" button is present on the "Live now" card, clicked it through to the join lobby and then into the actual room (`/meeting/{code}/room`), and confirmed the host's own name appears as a participant there. Full backend suite (149/149) re-run and unaffected, since this is a frontend-only change.

---

## BUG-013

Status: DONE

Description:
A direct follow-on from BUG-012's fix, caught immediately by the user re-testing it: the join lobby (`Join.vue`) had no way to know the current viewer *is* the meeting's own host, so a host clicking through the new "Join meeting" button on a password-protected meeting was shown a "Meeting password" field and asked to fill it in — even though `MeetingJoinController::join()` has always bypassed the password/lock checks for the host server-side (`! $isHost` guards). The field wasn't just unnecessary noise; a host unsure whether to fill it in (they may not know their own meeting's password was even required, or may not remember it) could be confused into thinking they were blocked.

Steps:
1. As host, create a meeting with a password set.
2. Start it, then use the "Live now" card's "Join meeting" button (BUG-012's fix) to reach the join lobby.

Expected:
The host sees no password prompt and no "locked" warning — both are irrelevant to them, since the server already lets them straight through regardless.

Actual:
The lobby rendered exactly as it would for any other participant: a "Meeting password" input (and, if the meeting were locked, a "This meeting is currently locked by the host" warning), even though submitting empty always worked anyway once clicked.

Fix:
`GET /meetings/lookup/{code}` (`MeetingJoinController::lookup()`) now also returns `host_user_id` — a plain foreign-key integer, no more sensitive than `host_name`, which this same public endpoint was already returning. `Join.vue` computes `isHost = state.user?.id === lookup.host_user_id` and uses it to hide the password field and the locked-meeting warning, and to swap "Hosted by {name}" for "You're the host" in the header.

Test:
Live over CDP: host created a password-protected meeting, started it, and reached the join lobby via the dashboard's "Join meeting" button — confirmed no `#password` input exists in the DOM at all (not just visually hidden), the header reads "You're the host", and clicking "Join meeting" enters the room directly with zero password entry. `MeetingJoinTest.php`'s existing "wrong password is rejected"/"correct password allows joining" cases (which exercise a *non-host* participant) re-run unaffected, confirming the password check itself wasn't touched — only who sees the field for it.
