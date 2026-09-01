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
