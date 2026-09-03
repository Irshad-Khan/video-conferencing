# DATABASE.md

Database: MySQL 8. All tables use InnoDB, `id` bigint unsigned auto-increment primary keys, and standard Laravel `timestamps()` unless noted.

## users (Laravel default + additions)

| Column | Type | Notes |
|---|---|---|
| id | bigint unsigned PK | |
| name | string | |
| email | string, unique | |
| email_verified_at | timestamp nullable | |
| password | string | bcrypt hash |
| is_admin | boolean, default false | added for Phase 15 |
| is_disabled | boolean, default false | added for Phase 15 (admin can disable a user) |
| remember_token | string nullable | |
| timestamps | | |

## meetings

| Column | Type | Notes |
|---|---|---|
| id | bigint unsigned PK | |
| meeting_uuid | uuid, unique | public identifier used in Reverb channel names |
| meeting_code | string, unique | short human-friendly join code, e.g. `ABC-123-XYZ` |
| host_user_id | FK → users.id | |
| title | string | |
| description | text nullable | |
| password | string nullable | bcrypt hash, never returned in API responses |
| status | enum: scheduled, waiting, live, ended, cancelled | |
| waiting_room_enabled | boolean, default false | Phase 11 |
| allow_guests | boolean, default true | |
| is_locked | boolean, default false | Phase 7 |
| scheduled_at | timestamp nullable | |
| started_at | timestamp nullable | |
| ended_at | timestamp nullable | |
| timestamps | | |

Indexes: unique(`meeting_uuid`), unique(`meeting_code`), index(`host_user_id`), index(`status`).

## meeting_participants

| Column | Type | Notes |
|---|---|---|
| id | bigint unsigned PK | |
| meeting_id | FK → meetings.id | |
| user_id | FK → users.id, nullable | null for guests |
| guest_name | string, nullable | required when user_id is null |
| guest_token | string, nullable | sha256 hash of a short-lived token issued to guests on join, used to authenticate their `/leave` call (never stored/returned raw) |
| role | enum: host, co_host, participant, guest | |
| status | enum: waiting, admitted, joined, left, removed, rejected | |
| joined_at | timestamp nullable | |
| left_at | timestamp nullable | |
| admitted_at | timestamp nullable | set once, the first time the host admits this participant past the waiting room (Phase 11) — distinct from `joined_at`/`left_at`, which flip on every reconnect; lets a reconnecting participant skip the waiting room a second time once already vetted |
| timestamps | | |

Indexes: index(`meeting_id`), index(`user_id`), index(`status`), index(`guest_token`), unique(`meeting_id`, `user_id`) (one participant row per authenticated user per meeting; re-joining updates the existing row rather than duplicating it — `NULL` `user_id` values are exempt from the uniqueness check, so guests are unaffected).
Constraint (app-level, enforced in the join controller, not a DB CHECK): exactly one of `user_id` / `guest_name` must be present.

## meeting_messages

| Column | Type | Notes |
|---|---|---|
| id | bigint unsigned PK | |
| meeting_id | FK → meetings.id | |
| user_id | FK → users.id, nullable | nullable to allow guest messages tied to the participant instead |
| meeting_participant_id | FK → meeting_participants.id, nullable | resolves sender identity for guests |
| message | text | sanitized/escaped on render, never stored pre-rendered as HTML |
| created_at | timestamp | messages are immutable, no updated_at needed |

Indexes: index(`meeting_id`, `created_at`).

## personal_access_tokens

Standard Sanctum table (added by `php artisan install:api`), used for future mobile/token clients; the first-party SPA uses Sanctum's cookie-based session guard instead.

## Entity Relationships

```
users 1───* meetings (host)
users 1───* meeting_participants (nullable, guests excluded)
meetings 1───* meeting_participants
meetings 1───* meeting_messages
meeting_participants 1───* meeting_messages (guest sender resolution)
```

## Migration Ordering

`users` → `meetings` → `meeting_participants` → `meeting_messages`, respecting FK dependencies. Each ships as a standard Laravel migration file under `database/migrations/`.
