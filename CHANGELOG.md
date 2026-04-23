# Changelog

All notable changes to this project are documented here.
The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.4.0] - 2026-04-23

### Added
- `stats` singleton table (`total_messages`, `total_chars`,
  `total_users`) and `seen_users` table in `db/schema.sql`. Counters are
  monotonic and survive `cleanup_message_history`.
- `HISTORY_SIZE = 50` and `ACTIVE_USER_WINDOW_MINUTES = 12` constants
  in `chatService.php`.
- New service-layer functions `fetch_last_n_messages`,
  `cleanup_message_history`, `get_stats`, `active_users_now`.
- `tests/integration/HistoryTest.php` (5 tests) and
  `tests/integration/StatsTest.php` (8 tests).
- `e2e/tests/historyBackfill.spec.ts` — new joiner receives the last N
  messages seeded from three independent sessions.

### Changed
- `chatPoll.php` first-connect now emits the last N messages as SSE
  backfill (ASC by id) before entering the live loop. Supersedes
  SPEC-02's "empty on first connect" decision. Reconnect with
  `Last-Event-ID` is unchanged — no backfill, no duplicates.
- `insert_message` is transactional: the message INSERT and the stats
  counter increments commit together or not at all.
- `create_session` is transactional: INSERT sessions, `INSERT IGNORE`
  seen_users, and — only if the IGNORE actually inserted a new row —
  increment `stats.total_users` so a nickname ever seen counts exactly
  once.
- `e2e/tests/sendAndReceive.spec.ts` and `e2e/tests/antiFlood.spec.ts`
  use suffix-tagged text with a `filter({ hasText }) + toHaveCount(1)`
  pattern, so assertions are robust against the backfill populating
  `#message_container` before the live send.
- CI actions bumped: `actions/checkout@v4 → @v5` and
  `actions/upload-artifact@v4 → @v5`. v4 is deprecated (Node 20
  runtime); v5 runs on Node 24.
- Documentation restructure: Italian `DEPLOYMENT.md` and
  `OPEN-POINTS.md` translated to English and moved under `docs/`. Specs
  moved from root (`SPEC*.md`) to `docs/specs/spec-NN.md`. New
  `docs/architecture.md` captures stack, methodology, testing layers,
  folder layout, branch model, and versioning. Branch model switched
  to lightweight Gitflow (`master` tag-only, `dev` integration).

### Fixed
- `db` healthcheck now verifies the `sessions` table is queryable
  instead of a bare `mysqladmin ping`. The ping succeeded via the local
  socket while `/docker-entrypoint-initdb.d/schema.sql` was still
  running on first boot, causing `--wait` to unblock before the schema
  existed; integration tests then errored on `TRUNCATE TABLE sessions`
  (PHPUnit exit code 2 in CI). The schema-aware check makes a clean-
  slate start deterministic both locally and in GitHub Actions.

## [0.3.1] - 2026-04-23

### Added
- `.github/workflows/ci.yml` runs PHPUnit and Playwright on `push` and
  `pull_request` via docker compose; uploads Playwright artifacts on
  failure.
- `.gitattributes` normalizes text files to LF (silences the Windows
  CRLF warnings) and marks common binary types.
- `DEPLOYMENT.md` captures required prod env vars, schema application,
  the `X-Forwarded-For` trust plan for future IP-based features, and
  the stable-build-to-Aruba release shape.
- `OPEN-POINTS.md` as the concise roadmap source of truth.
- `session_cookie_secure()` helper and three unit tests
  (`tests/unit/ConfigTest.php`) gate the `Secure` cookie flag on
  `APP_ENV=prod`.
- `APP_ENV` env var (default `local`) propagated through `.env.example`
  and `docker-compose.yml`.

### Changed
- `db` service gained a `mysqladmin ping` healthcheck; `app` now
  `depends_on: db: condition: service_healthy`, so `up --wait` is
  deterministic without a polling loop.

### Removed
- Orphan `nul` file from an accidental Windows shell redirect.

## [0.3.0] - 2026-04-23

### Added
- Anonymous session model: `sessions` table, opaque 64-hex session id,
  `sid` cookie with `HttpOnly` and `SameSite=Strict`, 15 min idle TTL,
  nickname uniqueness among active sessions, lazy expired-session
  cleanup on join.
- Blocking nickname dialog on page load; `joinChat.php` endpoint with
  200/400/409/500 responses.
- Per-session 3 s cooldown with escalating penalty: each blocked attempt
  pushes `send_blocked_until` to `NOW() + (3 × cooldown_attempts)`
  seconds; 429 response includes `wait_seconds`.
- 200-character message cap enforced server-side (400 `too_long`); live
  client-side `N/200` counter; send-button gating at 0 and over 200
  chars.
- CRLF, other C0 control chars, and invalid UTF-8 rejected at
  `sendMessage.php` (400 `invalid_chars`) — defends the SSE frame.
- Offender-visible `[system]` local `<li>` for cooldown waits and
  validation errors — never broadcast over SSE.
- `tests/unit/ValidationTest.php` (12 tests),
  `tests/integration/SessionTest.php` (11 tests),
  `e2e/tests/antiFlood.spec.ts` (6 scenarios).

### Changed
- `sendMessage.php` requires a valid `sid` cookie (401 otherwise); body
  is only `{ message }` — `user_id` is server-derived from the session.
- `chatPoll.php` requires a valid `sid` cookie (401 otherwise); refreshes
  `last_seen_at` on each loop iteration.
- `main.js` drops `generateID()`; SSE connects automatically on
  successful join; adds the live char counter and 200/400/401/429
  response handling.
- `index.php` replaces the `#user_id` input and `#join_chat` button with
  a blocking `<dialog>`; `#text_value` and `#send_message` start
  disabled; adds `#char_counter` and a static `#chat_list` placeholder.

## [0.2.0] - 2026-04-23

### Fixed
- `chatPoll.php` now streams as a real SSE loop — it used to dump the
  table once and exit.
- Removed `var_dump` from the SSE stream body that was corrupting the
  `text/event-stream` framing.
- `HttpRequest.send()` now uses `this.complete` and `this.body` — the
  free-variable bug was sending `undefined` bodies.
- `EventSource` connect listener is wrapped in an arrow so `this` binds
  correctly.
- `sendMessage` success handler targets the real `#message_container`
  element (was `#list`).

### Added
- `chatService.php` extracts the DB-touching logic into unit-testable
  free functions: `fetch_messages_since`, `insert_message`,
  `max_message_id`.
- `tests/integration/ChatServiceTest.php` covers the three new functions
  against live MySQL.
- `e2e/tests/sendAndReceive.spec.ts` asserts cross-client message
  delivery via SSE.
- `Last-Event-ID` resumption in `chatPoll.php`; first connect seeds the
  cursor from `max_message_id()` ("empty on first connect").

### Removed
- `index.html` scaffolding leftover.
- DOM-append "Message sent" confirmation — replaced with a native
  `alert()`.

## [0.1.0] - 2026-04-22

### Added
- Containerized dev environment: `docker-compose.yml` with `app`
  (php:8.3-apache), `db` (mysql:8.0), `playwright` services.
- `.env.example` template; `.env.local` / `.env.prod` env-file switching
  at compose time; `vlucas/phpdotenv` for bare-PHP paths so the
  `getenv()` code path is identical in both modes.
- `bootstrap.php` loads Composer autoload and `.env` via phpdotenv.
- `access.php` reads DB config from `getenv()` — no hardcoded
  credentials.
- `db/schema.sql` creates the `messages` table; auto-loaded on first
  `db` container boot via `/docker-entrypoint-initdb.d/`.
- PHPUnit 11 configured with `unit` and `integration` testsuites;
  `tests/unit/SmokeTest.php` proves the harness.
- Playwright with `e2e/playwright.config.ts`, `e2e/tests/baseline.spec.ts`,
  and the committed baseline snapshot.
