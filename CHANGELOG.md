# Changelog

All notable changes to this project are documented here.
The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.5.0] - 2026-04-24

### Added
- Admin panel — the first authenticated surface of the project.
  Single admin, password hashed with bcrypt in the `ADMIN_PASSWORD_HASH`
  env var, PHP-session gated pages, `HttpOnly` + `SameSite=Strict`
  cookie (`Secure` in prod), 1h idle TTL, CSRF token on every POST.
- Runtime config store: new `config` table in `db/schema.sql` with
  seven keys (`message_max_length`, `cooldown_base_seconds`,
  `history_size`, `nickname_min_length`, `nickname_max_length`,
  `session_ttl_minutes`, `active_user_window_minutes`). Seeded via
  `INSERT IGNORE` so admin-edited values survive schema re-apply.
- Service-layer helpers in `chatService.php`: `get_config`,
  `set_config`, `set_all_config`, `get_all_config`, `config_bounds`,
  `check_config_invariants`, plus `start_admin_session`,
  `verify_admin_password`, `authenticate_admin_request`,
  `admin_is_authenticated`, `require_admin_auth`, `admin_csrf_token`,
  `verify_admin_csrf`, `admin_logout`.
- New endpoints: `adminLogin.php`, `authenticateAdmin.php`,
  `adminPanel.php`, `adminUpdateConfig.php`, `adminCleanupHistory.php`,
  `adminLogout.php`; minimal styling in `assets/adminStyles.css`.
- Tests: `tests/unit/AdminAuthTest.php` (16 tests),
  `tests/unit/ConfigBoundsTest.php` (7 tests),
  `tests/integration/RuntimeConfigTest.php` (14 tests),
  `e2e/tests/adminPanel.spec.ts` (6 scenarios: login fail, login
  success, history cleanup, cooldown=0 back-to-back sends, bounds
  rejection, logout).
- `ADMIN_PASSWORD_HASH` in `.env.example` / `.env.local`, passed through
  `docker-compose.yml` to the `app` service.

### Changed
- `validate_nickname` and `validate_message` signatures widened to
  accept min/max length as explicit parameters; callers read those from
  the config table. `validate_nickname` now separates the length check
  (`mb_strlen`) from the constant character-class regex, so the class
  never goes through string interpolation.
- `get_session`, `cleanup_expired_sessions`, `active_users_now`,
  `advance_cooldown`, and the `chatPoll.php` backfill branch read their
  thresholds from `config` instead of the old `const`s.
- `index.php` became a PHP passthrough: renders `minlength`,
  `maxlength`, `pattern`, and the char-counter label from live config,
  and emits a `window.SSE_CONFIG` inline script that `main.js` consumes
  at init.
- `e2e/playwright.config.ts` pins `workers: 1`. The SPEC-05 history
  cleanup test deletes shared DB state — parallel workers would race
  with other specs' seeding.
- The five hard-coded constants in `chatService.php`
  (`SESSION_TTL_MINUTES`, `MESSAGE_MAX_LENGTH`, `COOLDOWN_BASE_SECONDS`,
  `HISTORY_SIZE`, `ACTIVE_USER_WINDOW_MINUTES`) are gone. Same values,
  now stored in `config`, tunable via the panel.

### Fixed
- Rejoining with an existing `sid` cookie now reuses the caller's own
  session instead of hitting `UNIQUE (nickname)` against themselves.
  Closing a tab and reopening (or refreshing after a successful join)
  no longer forces the user to wait for the TTL to drain. `joinChat.php`
  routes through a new `rejoin_or_create_session` helper:
  - matching `sid` + nickname → refresh `last_seen_at`, reuse the sid
  - matching `sid` + different nickname → drop the old row (identity
    switch) and create a fresh session
  - no cookie / stale cookie → existing create flow, still rejects
    collisions from other browsers with a 409.
  Five new integration tests in `tests/integration/SessionTest.php`
  cover all four branches plus the preserved cross-browser rejection.
  Pre-existing SPEC-03 bug, surfaced during SPEC-05 manual testing.
- `ADMIN_PASSWORD_HASH` needs `$$` escaping in any compose-parsed env
  file — compose re-expands `$`-sequences during interpolation, so an
  un-escaped bcrypt hash gets mangled (`$a9x…` is seen as a missing
  variable reference). Documented inline in `.env.example`.

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
