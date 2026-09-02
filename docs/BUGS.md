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
